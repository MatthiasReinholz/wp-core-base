<?php

declare(strict_types=1);

use WpOrgPluginUpdater\TempWorkspace;

/** @param callable(bool,string):void $assert */
function run_test_runner_contract_tests(callable $assert, string $repoRoot): void
{
    $workspace = TempWorkspace::create($repoRoot, 'runner-contracts');
    $root = $workspace->path();
    $runner = $repoRoot . '/tools/wporg-updater/tests/support/SuiteRunner.php';
    try {
        mkdir($root . '/integration');
        file_put_contents($root . '/integration/example.php', '<?php function example_suite(): void {}');
        file_put_contents($root . '/integration/forgotten.php', '<?php file_put_contents(__DIR__ . "/executed", "unsafe");');
        $preamble = '<?php require ' . var_export($runner, true) . '; putenv("WP_CORE_BASE_COVERAGE_FILE"); putenv("WP_CORE_BASE_TEST_REPORT=' . $root . '/report.json");';
        $constructor = 'new SuiteRunner(' . var_export($root . '/integration', true) . ', ["example.php" => "example_suite"], ' . var_export($repoRoot, true) . ')';
        $invoke = static function (string $code) use ($root, $preamble): array {
            file_put_contents($root . '/invoke.php', $preamble . $code);
            $process = proc_open([PHP_BINARY, $root . '/invoke.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (! is_resource($process)) { throw new RuntimeException('Unable to run test-runner fixture.'); }
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            return ['status' => proc_close($process), 'output' => (string) $output . (string) $error];
        };
        $result = $invoke('$runner = ' . $constructor . ';');
        $assert($result['status'] !== 0 && str_contains($result['output'], 'Unregistered: forgotten.php'), 'Expected an unregistered integration suite to fail before fixtures.');
        $assert(! file_exists($root . '/integration/executed'), 'Expected inventory validation before requiring any suite fixture.');
        unlink($root . '/integration/forgotten.php');
        $result = $invoke('$runner = ' . $constructor . '; $runner->run("example.php", function () use ($runner): void { $runner->assertion(true, "pass"); }); $runner->complete();');
        $report = json_decode((string) file_get_contents($root . '/report.json'), true, 512, JSON_THROW_ON_ERROR);
        $assert($result['status'] === 0 && $report['status'] === 'passed', 'Expected a complete registered runner to succeed.');
        $assert($report['executed_suites'] === 1 && $report['assertions'] === 1 && $report['suites']['example.php']['assertions'] === 1, 'Expected total and per-suite assertion counts.');
        $assert(is_int($report['suites']['example.php']['duration_ms']), 'Expected per-suite timing in the machine-readable report.');
        $result = $invoke('$runner = ' . $constructor . '; $runner->complete();');
        $assert($result['status'] !== 0 && str_contains($result['output'], 'did not execute: example.php'), 'Expected skipped registered suites to fail completion.');
        $result = $invoke('$runner = ' . $constructor . '; $runner->run("example.php", static function (): void {}); $runner->run("example.php", static function (): void {});');
        $assert($result['status'] !== 0 && str_contains($result['output'], 'execute exactly once'), 'Expected duplicate suite execution to fail.');
        $result = $invoke('$runner = ' . $constructor . '; $runner->run("example.php", function () use ($runner): void { $runner->assertion(false, "deliberate failure"); });');
        $report = json_decode((string) file_get_contents($root . '/report.json'), true, 512, JSON_THROW_ON_ERROR);
        $assert($result['status'] !== 0 && $report['status'] === 'failed' && $report['suites']['example.php']['status'] === 'failed', 'Expected assertion failures to retain the failed suite report.');
        $assert(str_contains($report['suites']['example.php']['error'], 'deliberate failure'), 'Expected actionable assertion failure context in the report.');
    } finally {
        $workspace->close();
    }
}
