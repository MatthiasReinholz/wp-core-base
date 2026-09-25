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
    } finally {
        $workspace->close();
    }
}
