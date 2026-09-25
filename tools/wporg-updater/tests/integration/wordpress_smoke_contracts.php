<?php

declare(strict_types=1);

use WpOrgPluginUpdater\TempWorkspace;

/** @param callable(bool,string):void $assert */
function run_wordpress_smoke_contract_tests(callable $assert, string $repoRoot): void
{
    $runner = $repoRoot . '/tools/wporg-updater/tests/support/WordpressSmokeRunner.php';
    require_once $runner;
    $workspace = TempWorkspace::create($repoRoot, 'smoke-runner-contracts');
    try {
        $fixture = $workspace->path() . '/phase.php';
        $require = 'require ' . var_export($runner, true) . ';';
        foreach ([
            'early-zero-exit' => 'exit(0);',
            'wrong-receipt' => 'file_put_contents(getenv("WP_CORE_BASE_SMOKE_RECEIPT"), "stale receipt");',
            'failure-after-completion' => $require . 'WordpressSmokeRunner::complete("bootstrap"); exit(1);',
        ] as $case => $code) {
            file_put_contents($fixture, '<?php ' . $code);
            $failure = null;
            try {
                WordpressSmokeRunner::run($fixture, 'bootstrap', $workspace->path(), []);
            } catch (RuntimeException $caught) {
                $failure = $caught;
            }
            $assert($failure !== null, 'Expected smoke subprocess rejection for ' . $case . '.');
            if ($case === 'early-zero-exit') {
                $assert(str_contains($failure?->getMessage() ?? '', 'did not complete every assertion'), 'Expected exit(0) before assertions to fail with completion context.');
            }
        }
        file_put_contents($fixture, '<?php ' . $require . 'WordpressSmokeRunner::complete($argv[1]);');
        foreach (['install', 'bootstrap', 'cleanup'] as $phase) {
            WordpressSmokeRunner::run($fixture, $phase, $workspace->path(), []);
            $assert(true, 'Expected a completed smoke phase with a matching receipt to pass: ' . $phase);
        }
        file_put_contents($fixture, '<?php ' . $require . 'fwrite(STDOUT,"child-out\\n"); fwrite(STDERR,"child-error\\n"); WordpressSmokeRunner::complete($argv[1]);');
        $parent = $workspace->path() . '/parent.php';
        file_put_contents($parent, '<?php ' . $require . 'fwrite(STDOUT,"parent-before\\n"); WordpressSmokeRunner::run('
            . var_export($fixture, true) . ',"bootstrap",' . var_export($workspace->path(), true) . ',[]); fwrite(STDOUT,"parent-after\\n");');
        $log = $workspace->path() . '/combined.log';
        $handle = fopen($log, 'w+b');
        if ($handle === false) { throw new RuntimeException('Unable to open redirected smoke log fixture.'); }
        try {
            $child = proc_open([PHP_BINARY, $parent], [1 => $handle, 2 => $handle], $pipes);
            $assert(is_resource($child) && proc_close($child) === 0, 'Redirected smoke validation must complete successfully.');
        } finally {
            fclose($handle);
        }
        $assert(file_get_contents($log) === "parent-before\nchild-out\nchild-error\nparent-after\n", 'Smoke subprocesses must preserve earlier output and ordering in a shared redirected validation log.');
        $upgrade = $repoRoot . '/scripts/ci/verify_wordpress_upgrade.php';
        $safeEnvironment = [
            'WP_CORE_BASE_SMOKE_DB_HOST' => '127.0.0.1:3306',
            'WP_CORE_BASE_SMOKE_DB_NAME' => 'wp_core_base_smoke',
            'WP_CORE_BASE_SMOKE_DB_USER' => 'fixture',
            'WP_CORE_BASE_SMOKE_DB_PASSWORD' => 'fixture',
        ];
        foreach ([
            'remote-database' => [[], ['WP_CORE_BASE_SMOKE_DB_HOST' => 'database.example.invalid'], 'dedicated local'],
            'site-database' => [[], ['WP_CORE_BASE_SMOKE_DB_NAME' => 'production'], 'dedicated local'],
            'duplicate-profile' => [['--profile=full-core', '--profile=content-only'], [], 'Use --profile'],
            'unknown-storage' => [['--order-storage=unknown'], [], 'Use --profile'],
        ] as $case => [$arguments, $overrides, $expectedMessage]) {
            $output = $workspace->path() . '/' . $case . '.log';
            $process = proc_open([PHP_BINARY, $upgrade, ...$arguments], [1 => ['file', $output, 'w'], 2 => ['redirect', 1]], $pipes, $repoRoot, array_replace($safeEnvironment, $overrides));
            $assert(is_resource($process) && proc_close($process) !== 0, 'Upgrade fixture must reject unsafe input before allocating runtime/database state: ' . $case);
            $assert(str_contains((string) file_get_contents($output), $expectedMessage), 'Upgrade fixture rejection must identify its failed boundary: ' . $case);
        }
        foreach ([
            'database-error' => '[fixture] WordPress database error Incorrect table name for query SHOW FULL COLUMNS',
            'fatal-error' => '[fixture] PHP Fatal error: Uncaught RuntimeException',
            'parse-error' => '[fixture] PHP Parse error: unexpected token',
        ] as $case => $diagnostic) {
            $failure = null;
            try {
                WordpressSmokeRunner::assertHealthyLog($diagnostic);
            } catch (RuntimeException $caught) {
                $failure = $caught;
            }
            $assert($failure !== null, 'Zero-exit WordPress phases must still fail on recorded ' . $case . '.');
        }
        WordpressSmokeRunner::assertHealthyLog('[fixture] PHP Notice: Translation loading triggered too early.');
        $assert(true, 'Nonfatal upstream diagnostics remain distinguishable from migration/runtime failure.');
        file_put_contents($fixture, '<?php ' . $require . 'file_put_contents(__DIR__ . "/wordpress.log", "WordPress database error Incorrect table name"); WordpressSmokeRunner::complete($argv[1]);');
        WordpressSmokeRunner::run($fixture, 'bootstrap', $workspace->path(), []);
        $failure = null;
        try {
            WordpressSmokeRunner::assertHealthyLog((string) file_get_contents($workspace->path() . '/wordpress.log'));
        } catch (RuntimeException $caught) {
            $failure = $caught;
        }
        $assert($failure !== null, 'A successful process and completion receipt must not hide a swallowed WordPress database error.');
    } finally {
        $workspace->close();
    }
}
