<?php

declare(strict_types=1);

/** Keeps the historical fixture order while making registration and execution observable. */
final class SuiteRunner
{
    /** @var array<string, array{assertions:int,duration_ms:int,status:string,error:?string}> */
    private array $results = [];
    private ?string $active = null;
    private int $assertions = 0;
    private int $legacyAssertions = 0;
    private float $startedAt;
    private ?string $coverageDriver = null;
    private bool $reported = false;

    /** @param array<string,string> $inventory */
    public function __construct(private readonly string $directory, private readonly array $inventory, private readonly string $frameworkRoot)
    {
        $this->startedAt = microtime(true);
        $actual = array_map('basename', glob($directory . '/*.php') ?: []);
        $expected = array_keys($inventory);
        sort($actual); sort($expected);
        if ($actual !== $expected) {
            throw new RuntimeException(sprintf('Integration suite inventory mismatch. Unregistered: %s; missing files: %s.', implode(', ', array_diff($actual, $expected)), implode(', ', array_diff($expected, $actual))));
        }
        register_shutdown_function(function (): void {
            if (! $this->reported) {
                try {
                    $this->report(false);
                } finally {
                    // An exit(0) inside a fixture must not make incomplete
                    // validation look successful to the CI process runner.
                    exit(1);
                }
            }
        });
        $this->startCoverage();
        foreach ($inventory as $file => $function) {
            require_once $directory . '/' . $file;
            if (! function_exists($function)) {
                throw new RuntimeException(sprintf('Suite %s does not define registered entry point %s.', $file, $function));
            }
        }
    }

    public function assertion(bool $condition, string $message): void
    {
        $this->assertions++;
        if ($this->active === null) {
            $this->legacyAssertions++;
        }
        if (! $condition) {
            throw new RuntimeException(($this->active ?? 'legacy-contracts') . ': ' . $message);
        }
    }

    public function run(string $file, callable $suite): mixed
    {
        if (! isset($this->inventory[$file]) || isset($this->results[$file]) || $this->active !== null) {
            throw new RuntimeException(sprintf('Suite must be registered and execute exactly once: %s.', $file));
        }
        $this->active = $file;
        $start = microtime(true); $before = $this->assertions;
        $status = 'passed'; $error = null;
        try {
            return $suite();
        } catch (Throwable $failure) {
            $status = 'failed'; $error = $failure->getMessage();
            throw new RuntimeException(sprintf('Suite %s failed: %s', $file, $error), 0, $failure);
        } finally {
            $this->results[$file] = ['assertions' => $this->assertions - $before, 'duration_ms' => (int) round((microtime(true) - $start) * 1000), 'status' => $status, 'error' => $error];
            $this->active = null;
            fwrite(STDOUT, sprintf("[%s] %s: %d assertions, %d ms\n", $status, $file, $this->results[$file]['assertions'], $this->results[$file]['duration_ms']));
        }
    }

    public function complete(): void
    {
        $missing = array_diff(array_keys($this->inventory), array_keys($this->results));
        if ($missing !== []) {
            throw new RuntimeException('Registered integration suites did not execute: ' . implode(', ', $missing));
        }
        foreach ($this->results as $file => $result) {
            if ($result['status'] !== 'passed') {
                throw new RuntimeException('Cannot complete validation with a failed suite: ' . $file);
            }
        }
        $this->report(true);
    }

    private function report(bool $success): void
    {
        $this->reported = true;
        $report = ['status' => $success ? 'passed' : 'failed', 'registered_suites' => count($this->inventory), 'executed_suites' => count($this->results), 'assertions' => $this->assertions, 'legacy_assertions' => $this->legacyAssertions, 'duration_ms' => (int) round((microtime(true) - $this->startedAt) * 1000), 'suites' => $this->results, 'not_executed' => array_values(array_diff(array_keys($this->inventory), array_keys($this->results)))];
        fwrite(STDOUT, sprintf("Test result: %s; %d/%d integration suites; %d assertions (%d legacy); %d ms.\n", $report['status'], $report['executed_suites'], $report['registered_suites'], $this->assertions, $this->legacyAssertions, $report['duration_ms']));
        $path = getenv('WP_CORE_BASE_TEST_REPORT');
        if (is_string($path) && $path !== '') {
            file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        }
        $this->writeCoverage();
    }

    private function startCoverage(): void
    {
        $path = getenv('WP_CORE_BASE_COVERAGE_FILE');
        if (! is_string($path) || $path === '') { return; }
        if (function_exists('xdebug_start_code_coverage')) {
            xdebug_start_code_coverage(defined('XDEBUG_CC_UNUSED') ? XDEBUG_CC_UNUSED : 0);
            $this->coverageDriver = 'xdebug';
        } elseif (function_exists('pcov\\start')) {
            \pcov\start();
            $this->coverageDriver = 'pcov';
        } else {
            throw new RuntimeException('Coverage requested but neither Xdebug coverage mode nor PCOV is available.');
        }
    }

    private function writeCoverage(): void
    {
        if ($this->coverageDriver === null) { return; }
        $coverage = $this->coverageDriver === 'xdebug' ? xdebug_get_code_coverage() : \pcov\collect();
        if ($this->coverageDriver === 'xdebug') { xdebug_stop_code_coverage(); } else { \pcov\stop(); }
        $files = [];
        foreach ($coverage as $file => $lines) {
            $root = rtrim($this->frameworkRoot, '/') . '/tools/wporg-updater/';
            if (! str_starts_with($file, $root . 'src/') && ! str_starts_with($file, $root . 'bin/')) { continue; }
            $observed = [];
            foreach ($lines as $line => $hits) {
                if (is_int($hits) && $hits > 0) { $observed[] = (int) $line; }
            }
            sort($observed);
            $files[substr($file, strlen($this->frameworkRoot) + 1)] = $observed;
        }
        ksort($files);
        $report = ['driver' => $this->coverageDriver, 'scope' => 'Observed executed framework lines in this PHP process; subprocess execution is not included.', 'observed_lines' => array_sum(array_map('count', $files)), 'files' => $files];
        file_put_contents((string) getenv('WP_CORE_BASE_COVERAGE_FILE'), json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    }
}
