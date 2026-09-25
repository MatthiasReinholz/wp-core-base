<?php

declare(strict_types=1);

/** Isolated CLI checks: a private Git checkout and fake read-only GitHub transport. */
$fixture = sys_get_temp_dir() . '/wp-core-base-security-cli-' . bin2hex(random_bytes(12));
if (! mkdir($fixture, 0700)) {
    throw new RuntimeException('Unable to create security CLI fixture.');
}
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
/** @param list<string> $command
 * @param array<string,string>|null $environment
 * @return array{exit:int,stdout:string,stderr:string}
 */
$run = static function (array $command, ?array $environment = null): array {
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
    if (! is_resource($process)) {
        throw new RuntimeException('Unable to start fixture command.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit' => proc_close($process), 'stdout' => (string) $stdout, 'stderr' => (string) $stderr];
};

try {
    $checkout = $fixture . '/checkout';
    mkdir($checkout . '/scripts/ci', 0700, true);
    mkdir($checkout . '/.github');
    mkdir($checkout . '/.wp-core-base');
    mkdir($checkout . '/runtime');
    mkdir($checkout . '/docs/security-reviews', 0700, true);
    mkdir($fixture . '/bin');
    foreach (['check_security_review.php', 'security_review_policy.php', 'security_review_github.php'] as $script) {
        if (! copy(__DIR__ . '/' . $script, $checkout . '/scripts/ci/' . $script)) {
            throw new RuntimeException('Unable to copy security CLI fixture input.');
        }
    }
    file_put_contents($checkout . '/.wp-core-base/framework.php', "<?php return ['baseline' => ['wordpress_core' => '7.1.2']];\n");
    file_put_contents($checkout . '/runtime/editor.js', 'ordinary fixture source');
    file_put_contents($checkout . '/docs/security-reviews/baseline.md', '# Fixture review');
    $location = ['path' => 'runtime/editor.js', 'start_line' => 1, 'end_line' => 1, 'start_column' => 1, 'end_column' => 3];
    $register = [
        'schema_version' => 1, 'repository' => 'fixture/project', 'wordpress_version' => '7.1.2',
        'sources' => ['runtime/editor.js' => hash_file('sha256', $checkout . '/runtime/editor.js')],
        'analysis_categories' => ['/language:javascript-typescript'],
        'analysis_key' => 'dynamic/github-code-scanning/codeql:analyze', 'workflow_path' => 'dynamic/github-code-scanning/codeql',
        'findings' => [['number' => 230, 'rule_id' => 'js/redos', 'tool' => 'CodeQL', 'category' => '/language:javascript-typescript',
            'location' => $location, 'source_paths' => ['runtime/editor.js'], 'review_document' => 'docs/security-reviews/baseline.md']],
        'manual_findings' => [],
    ];
    file_put_contents($checkout . '/.github/security-review.json', json_encode($register, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    foreach ([['init', '--quiet', '--initial-branch=main'], ['add', '.'],
        ['-c', 'user.name=Security Fixture', '-c', 'user.email=fixture@example.invalid', '-c', 'commit.gpgsign=false', '-c', 'core.hooksPath=/dev/null', 'commit', '--quiet', '-m', 'Isolated security fixture']] as $arguments) {
        $result = $run(['git', '-C', $checkout, ...$arguments]);
        $assert($result['exit'] === 0, 'Fixture Git command failed: ' . $result['stderr']);
    }
    $result = $run(['git', '-C', $checkout, 'rev-parse', 'HEAD']);
    $assert($result['exit'] === 0, 'Cannot read fixture revision.');
    $sha = trim($result['stdout']);
    $responses = [
        'repository' => ['full_name' => 'fixture/project', 'default_branch' => 'main'],
        'workflows' => ['total_count' => 1, 'workflows' => [['id' => 1, 'path' => $register['workflow_path'], 'state' => 'active']]],
        'runs' => ['total_count' => 1, 'workflow_runs' => [['id' => 2, 'path' => $register['workflow_path'], 'head_sha' => $sha,
            'head_branch' => 'main', 'event' => 'dynamic', 'created_at' => '2026-09-25T10:00:00Z',
            'run_started_at' => '2026-09-25T10:00:01Z', 'run_attempt' => 1, 'status' => 'completed', 'conclusion' => 'success']]],
        'analyses' => [['id' => 3, 'category' => '/language:javascript-typescript', 'ref' => 'refs/heads/main', 'commit_sha' => $sha,
            'created_at' => '2026-09-25T10:01:00Z', 'error' => '', 'warning' => '', 'rules_count' => 17, 'results_count' => 1,
            'analysis_key' => $register['analysis_key'], 'tool' => ['name' => 'CodeQL', 'version' => '2.27.1']]],
        'alerts' => [['number' => 230, 'state' => 'open', 'rule' => ['id' => 'js/redos'], 'tool' => ['name' => 'CodeQL', 'version' => '2.27.1'],
            'most_recent_instance' => ['state' => 'open', 'ref' => 'refs/heads/main', 'commit_sha' => $sha,
                'category' => '/language:javascript-typescript', 'analysis_key' => $register['analysis_key'], 'location' => $location]]],
    ];
    $responsesPath = $fixture . '/responses.json';
    $logPath = $fixture . '/github-calls.log';
    $writeResponses = static function (array $data) use ($responsesPath): void {
        file_put_contents($responsesPath, json_encode($data, JSON_THROW_ON_ERROR));
    };
    $writeResponses($responses);
    $fakeGithub = <<<'PHP'
#!/usr/bin/env php
<?php
declare(strict_types=1);
$endpoint = $argv[count($argv) - 1];
file_put_contents(getenv('SECURITY_REVIEW_FIXTURE_LOG'), $endpoint . "\n", FILE_APPEND);
$responses = json_decode(file_get_contents(getenv('SECURITY_REVIEW_FIXTURE_RESPONSES')), true, 512, JSON_THROW_ON_ERROR);
if ($endpoint === 'repos/fixture/project') {
    $key = 'repository';
} elseif (str_starts_with($endpoint, 'repos/fixture/project/actions/workflows?')) {
    $key = 'workflows';
} elseif (str_starts_with($endpoint, 'repos/fixture/project/actions/workflows/1/runs?')) {
    $key = 'runs';
} elseif (str_starts_with($endpoint, 'repos/fixture/project/code-scanning/analyses?')) {
    $key = 'analyses';
} elseif (str_starts_with($endpoint, 'repos/fixture/project/code-scanning/alerts?')) {
    $key = 'alerts';
} else {
    fwrite(STDERR, 'Unexpected fixture endpoint.');
    exit(1);
}
echo json_encode($responses[$key], JSON_THROW_ON_ERROR);
PHP;
    file_put_contents($fixture . '/bin/gh', $fakeGithub);
    chmod($fixture . '/bin/gh', 0700);
    $environment = getenv();
    foreach (['GITHUB_ACTIONS', 'GITHUB_REPOSITORY', 'GITHUB_API_URL', 'GH_TOKEN', 'GITHUB_TOKEN', 'GH_ENTERPRISE_TOKEN', 'GITHUB_ENTERPRISE_TOKEN'] as $variable) {
        unset($environment[$variable]);
    }
    $environment['PATH'] = $fixture . '/bin:' . dirname(PHP_BINARY) . ':' . ($environment['PATH'] ?? '/usr/bin:/bin');
    $environment['SECURITY_REVIEW_FIXTURE_RESPONSES'] = $responsesPath;
    $environment['SECURITY_REVIEW_FIXTURE_LOG'] = $logPath;
    $command = [PHP_BINARY, $checkout . '/scripts/ci/check_security_review.php'];
    $live = ['--github', '--commit=' . $sha];
    $reject = static function (array $arguments, array $env, string $message, bool $beforeNetwork = true) use ($run, $command, $assert, $logPath): void {
        file_put_contents($logPath, '');
        $result = $run([...$command, ...$arguments], $env);
        $assert($result['exit'] === 1 && str_contains($result['stderr'], $message), 'Expected CLI rejection "' . $message . '", got: ' . json_encode($result));
        $assert($result['stdout'] === '', 'Failed security review must not emit a success receipt.');
        if ($beforeNetwork) {
            $assert(file_get_contents($logPath) === '', 'Invalid local inputs must fail before reading GitHub.');
        }
    };

    $result = $run($command, $environment);
    $assert($result['exit'] === 0 && str_contains($result['stdout'], 'baseline_matches_review'), 'Offline CLI accepts a valid fixture.');
    $result = $run([...$command, ...$live, '--repo=fixture/project'], $environment);
    $report = json_decode($result['stdout'], true);
    $assert($result['exit'] === 0 && is_array($report) && $report['scanner_run_id'] === 2 && $report['open_findings'] === [230]
        && $report['runtime_security_cleared'] === false, 'Local live CLI preserves open findings: ' . $result['stderr']);
    $actionsEnvironment = array_merge($environment, ['GITHUB_ACTIONS' => 'true', 'GITHUB_REPOSITORY' => 'fixture/project']);
    $result = $run([...$command, ...$live], $actionsEnvironment);
    $assert($result['exit'] === 0, 'Actions binds automatically to its publishing repository.');
    $reject($live, $environment, '--repo=owner/repository');
    $reject([...$live, '--repo=fixture/project'], array_merge($environment, ['GITHUB_ACTIONS' => 'true']), 'GITHUB_REPOSITORY is required');
    $reject([...$live, '--repo=other/project'], $actionsEnvironment, 'conflicts with GITHUB_REPOSITORY');
    $reject($live, array_merge($environment, ['GITHUB_REPOSITORY' => 'other/project']), 'does not match the publishing repository');
    $reject([...$live, '--repo=other/project'], $environment, 'does not match the publishing repository');
    $reject([...$live, '--repo=invalid'], $environment, '--repo=owner/repository');
    $reject(['--repo=fixture/project'], $environment, 'require --github');
    $reject(['--github', '--commit=' . str_repeat('b', 40), '--repo=fixture/project'], $environment, 'Checkout HEAD differs');

    foreach (['.github/security-review.json', 'docs/security-reviews/baseline.md', 'scripts/ci/security_review_policy.php', 'scripts/ci/security_review_github.php', 'scripts/ci/check_security_review.php'] as $path) {
        $original = file_get_contents($checkout . '/' . $path);
        file_put_contents($checkout . '/' . $path, $original . "\n");
        $reject([...$live, '--repo=fixture/project'], $environment, 'differs from the requested commit');
        file_put_contents($checkout . '/' . $path, $original);
    }
    file_put_contents($checkout . '/runtime/editor.js', 'changed fixture source');
    $reject([...$live, '--repo=fixture/project'], $environment, 'Reviewed source changed');
    file_put_contents($checkout . '/runtime/editor.js', 'ordinary fixture source');
    foreach ([
        [static function (array &$r): void { $r['repository']['full_name'] = 'other/project'; }, 'repository identity'],
        [static function (array &$r): void { $r['runs']['workflow_runs'][0]['conclusion'] = 'failure'; }, 'did not succeed'],
        [static function (array &$r): void { $r['runs']['workflow_runs'][0]['status'] = 'queued'; }, 'No completed successful scanner run'],
        [static function (array &$r): void { $r['analyses'][0]['created_at'] = '2026-09-25T09:59:00Z'; }, 'predates'],
        [static function (array &$r): void { $r['alerts'][0]['number'] = 999; }, 'Unreviewed open scanner finding'],
        [static function (array &$r): void { $r['alerts'] = []; }, 'disappeared'],
    ] as [$change, $message]) {
        $candidate = $responses;
        $change($candidate);
        $writeResponses($candidate);
        $reject([...$live, '--repo=fixture/project'], $environment, $message, false);
    }
    fwrite(STDOUT, "Security review CLI: {$assertions} assertions passed.\n");
} finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        if ($file->isDir() && ! $file->isLink()) {
            rmdir($file->getPathname());
        } else {
            unlink($file->getPathname());
        }
    }
    rmdir($fixture);
}
