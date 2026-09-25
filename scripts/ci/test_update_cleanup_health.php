<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/tools/wporg-updater/src/Autoload.php';

use WpOrgPluginUpdater\ManagedPullRequestBranchIdentity;
use WpOrgPluginUpdater\TempWorkspace;
use WpOrgPluginUpdater\UpdateHealthReport;

$repoRoot = dirname(__DIR__, 2);
$workspace = TempWorkspace::create($repoRoot, 'cleanup-health-tests');
$root = $workspace->path();
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

try {
    $policy = new UpdateHealthReport();
    $now = 1800000000;
    $candidate = ['number' => 42, 'url' => 'https://example.invalid/pull/42', 'closed_at' => gmdate(DATE_ATOM, $now - 7200),
        'branch' => 'codex/wporg-example', 'expected_head' => 'old', 'observed_head' => 'old', 'reused_by_open_pr' => false, 'identity_error' => null];
    foreach ([
        [[], 'cleanup_required', 1],
        [['observed_head' => null], 'cleaned', 0],
        [['closed_at' => gmdate(DATE_ATOM, $now - 3599)], 'pending', 0],
        [['closed_at' => gmdate(DATE_ATOM, $now - 3600)], 'cleanup_required', 1],
        [['observed_head' => 'new'], 'manual_review', 1],
        [['expected_head' => ''], 'manual_review', 1],
        [['identity_error' => 'unknown owner', 'observed_head' => null], 'manual_review', 1],
        [['reused_by_open_pr' => true], 'reused', 0],
    ] as [$changes, $state, $count]) {
        $report = $policy->evaluateCleanup([array_replace($candidate, $changes)], $now);
        $assert($report['pull_requests'][0]['state'] === $state && $report['actionable_count'] === $count, 'Unexpected cleanup policy result for ' . $state);
    }
    foreach (['invalid', gmdate(DATE_ATOM, $now + 1)] as $date) {
        try {
            $policy->evaluateCleanup([array_replace($candidate, ['closed_at' => $date])], $now);
            $assert(false, 'Invalid closure time must fail closed.');
        } catch (RuntimeException $exception) {
            $assert(str_contains($exception->getMessage(), 'closure timestamp'), 'Unexpected invalid-time diagnostic.');
        }
    }

    $metadata = ['kind' => 'plugin', 'component_key' => 'plugin:example', 'branch' => 'codex/wporg-example'];
    $pr = ['number' => 42, 'labels' => [['name' => 'automation:dependency-update']],
        'body' => '<!-- wporg-update-metadata: ' . json_encode($metadata, JSON_THROW_ON_ERROR) . ' -->',
        'head' => ['ref' => 'codex/wporg-example', 'sha' => 'old', 'repo' => ['full_name' => 'example/site']],
        'base' => ['ref' => 'main', 'repo' => ['full_name' => 'example/site']]];
    $assert(ManagedPullRequestBranchIdentity::validate($pr, 'main') === 'codex/wporg-example', 'Valid cleaner ownership must remain accepted.');
    foreach ([
        ['body' => 'missing metadata'], ['labels' => []], ['head' => ['repo' => ['full_name' => 'fork/site']]],
        ['head' => ['ref' => 'different']],
        ['labels' => [['name' => 'automation:dependency-update'], ['name' => 'automation:framework-update']]],
    ] as $changes) {
        $altered = array_replace_recursive($pr, $changes);
        if (array_key_exists('labels', $changes)) { $altered['labels'] = $changes['labels']; }
        $rejected = false;
        try {
            ManagedPullRequestBranchIdentity::validate($altered, 'main');
        } catch (RuntimeException) {
            $rejected = true;
        }
        $assert($rejected, 'Invalid cleanup ownership must remain rejected.');
    }
    foreach (['main', 'manual/branch', 'codex/wporg-'] as $branch) {
        $altered = $pr;
        $altered['head']['ref'] = $branch;
        $altered['body'] = '<!-- wporg-update-metadata: ' . json_encode(array_replace($metadata, ['branch' => $branch]), JSON_THROW_ON_ERROR) . ' -->';
        $rejected = false;
        try {
            ManagedPullRequestBranchIdentity::validate($altered, 'main');
        } catch (RuntimeException) {
            $rejected = true;
        }
        $assert($rejected, 'Protected or unmanaged branch must remain rejected.');
    }

    mkdir($root . '/bin');
    $fake = <<<'FAKE'
#!/usr/bin/env php
<?php
$args = array_slice($argv, 1);
file_put_contents(getenv('HEALTH_TEST_TRACE'), json_encode($args) . "\n", FILE_APPEND);
$case = getenv('HEALTH_TEST_CASE');
$sha = str_repeat('a', 40);
$branch = 'codex/wporg-example';
$metadata = ['kind' => 'plugin', 'component_key' => 'plugin:example', 'branch' => $branch];
$pr = ['number' => 42, 'state' => 'closed', 'closed_at' => gmdate(DATE_ATOM, time() - 7200), 'html_url' => 'https://example.invalid/pull/42',
    'body' => '<!-- wporg-update-metadata: ' . json_encode($metadata) . ' -->', 'labels' => [['name' => 'automation:dependency-update']],
    'head' => ['ref' => $branch, 'sha' => $sha, 'repo' => ['full_name' => 'example/site']],
    'base' => ['ref' => 'main', 'repo' => ['full_name' => 'example/site']]];
function emit($value): never { echo json_encode($value); exit(0); }
if (($args[0] ?? '') === 'pr' && ($args[1] ?? '') === 'list') {
    $state = $args[array_search('--state', $args, true) + 1];
    if ($state === 'open') { emit([]); }
    if ($state !== 'all' || ! in_array('--search', $args, true)) { fwrite(STDERR, 'Closed search must include merged PRs.'); exit(2); }
    $query = $args[array_search('--search', $args, true) + 1];
    if (! str_starts_with($query, 'is:closed closed:>=')) { exit(2); }
    if ($case === 'truncated') { emit(array_fill(0, 200, ['number' => 42])); }
    if ($case === 'no-closures') { emit([]); }
    if ($case === 'bad-inventory') { emit([['number' => '42']]); }
    emit([['number' => 42]]);
}
if (($args[0] ?? '') !== 'api' || count($args) > 3 || (isset($args[2]) && $args[2] !== '--include')) { fwrite(STDERR, 'Unexpected or mutating command.'); exit(2); }
$endpoint = $args[1];
if ($endpoint === 'repos/example/site') { emit(['default_branch' => 'main']); }
if (str_ends_with($endpoint, '/rules/branches/main')) { emit([['type' => 'required_status_checks', 'parameters' => ['required_status_checks' => [['context' => 'quality']]]]]); }
if (str_contains($endpoint, '/actions/workflows/')) { emit(['workflow_runs' => [['workflow_id' => str_contains($endpoint, 'reconcile') ? 2 : 1, 'id' => 10, 'event' => 'schedule',
    'created_at' => gmdate(DATE_ATOM, time() - 3600), 'name' => 'scheduled sync', 'status' => 'completed', 'conclusion' => 'success', 'html_url' => 'https://example.invalid/run']]]); }
if ($endpoint === 'repos/example/site/pulls/42') {
    if ($case === 'api-failure') { fwrite(STDERR, 'fixture API failure'); exit(1); }
    if ($case === 'bad-record') { unset($pr['base']); }
    if ($case === 'old-closure') { $pr['closed_at'] = gmdate(DATE_ATOM, time() - 32 * 86400); }
    if ($case === 'short-lookback') { $pr['closed_at'] = gmdate(DATE_ATOM, time() - 2 * 86400); }
    if ($case === 'invalid-time') { $pr['closed_at'] = 'invalid'; }
    if ($case === 'pending') { $pr['closed_at'] = gmdate(DATE_ATOM, time() - 1800); }
    if ($case === 'reopened') { $pr['state'] = 'open'; $pr['closed_at'] = null; }
    if ($case === 'bad-owner') { $pr['body'] = 'missing'; }
    emit($pr);
}
if (str_contains($endpoint, '/git/ref/heads/')) {
    $requested = rawurldecode(substr($endpoint, strrpos($endpoint, '/') + 1));
    if (($case === 'absent' && $requested === $branch) || ($case === 'no-ref-access' && $requested === 'main')) {
        echo "HTTP/2.0 404 Not Found\r\nContent-Type: application/json\r\n\r\n{\"message\":\"Not Found\"}"; exit(1);
    }
    if ($case === 'ref-forbidden' && $requested === $branch) { echo "HTTP/2.0 403 Forbidden\n\n{}"; exit(1); }
    if ($case === 'ref-transport' && $requested === $branch) { fwrite(STDERR, 'transport failed'); exit(1); }
    echo "HTTP/2.0 200 OK\r\nContent-Type: application/json\r\n\r\n";
    emit(['ref' => 'refs/heads/' . ($case === 'wrong-ref' && $requested === $branch ? 'other' : $requested), 'object' => ['type' => 'commit',
        'sha' => $case === 'changed-head' && $requested === $branch ? str_repeat('b', 40) : $sha]]);
}
if (str_contains($endpoint, '/pulls?state=open&head=')) {
    if ($case === 'reuse-truncated') { emit(array_fill(0, 100, $pr)); }
    if ($case === 'reuse-invalid') { emit([['state' => 'open']]); }
    if (in_array($case, ['reused', 'fork-reuse', 'different-branch-reuse'], true)) {
        $pr['number'] = 43; $pr['state'] = 'open';
        if ($case === 'fork-reuse') { $pr['head']['repo']['full_name'] = 'fork/site'; }
        if ($case === 'different-branch-reuse') { $pr['head']['ref'] = 'other'; }
        emit([$pr]);
    }
    emit([]);
}
fwrite(STDERR, 'Unexpected endpoint: ' . $endpoint); exit(2);
FAKE;
    file_put_contents($root . '/bin/gh', $fake);
    chmod($root . '/bin/gh', 0755);
    $environment = getenv();
    $environment['PATH'] = $root . '/bin:' . ($environment['PATH'] ?? '');
    foreach ([
        ['orphan', 1, 'cleanup_required'], ['absent', 0, 'cleaned'], ['pending', 0, 'pending'], ['changed-head', 1, 'manual_review'],
        ['bad-owner', 1, 'manual_review'], ['reused', 0, 'reused'], ['fork-reuse', 1, 'cleanup_required'], ['different-branch-reuse', 1, 'cleanup_required'],
        ['no-closures', 0, null], ['old-closure', 0, null], ['reopened', 0, null], ['short-lookback', 0, null], ['long-grace', 0, 'pending'],
        ['truncated', null, null], ['bad-inventory', null, null], ['bad-record', null, null], ['invalid-time', null, null],
        ['api-failure', null, null], ['no-ref-access', null, null], ['ref-forbidden', null, null], ['ref-transport', null, null],
        ['wrong-ref', null, null], ['reuse-truncated', null, null], ['reuse-invalid', null, null],
    ] as [$case, $expectedExit, $state]) {
        runCleanupHealthCase($case, $expectedExit, $state, $root, $repoRoot, $environment, $assert);
    }
    fwrite(STDOUT, sprintf("Cleanup health tests passed: %d assertions.\n", $assertions));
} finally {
    $workspace->close();
}


/**
 * @param array<string, string> $environment
 * @param callable(bool, string):void $assert
 */
function runCleanupHealthCase(string $case, ?int $expectedExit, ?string $state, string $root, string $repoRoot, array $environment, callable $assert): void
{
    $environment['HEALTH_TEST_CASE'] = $case;
    $environment['HEALTH_TEST_TRACE'] = $root . '/' . $case . '.jsonl';
    $extra = match ($case) { 'short-lookback' => ['--cleanup-lookback-days=1'], 'long-grace' => ['--cleanup-grace-hours=4'], default => [] };
    $process = proc_open([PHP_BINARY, $repoRoot . '/scripts/ci/check_update_health.php', '--repo=example/site', '--json', '--fail-on-actionable', ...$extra],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $repoRoot, $environment);
    if (! is_resource($process)) { throw new RuntimeException('Unable to run cleanup health fixture.'); }
    fclose($pipes[0]); $output = (string) stream_get_contents($pipes[1]); $error = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
    if ($expectedExit === null) {
        $assert($exit !== 0 && ! str_contains($output, '"status": "healthy"'), 'Incomplete API input must fail closed: ' . $case);
        $assert($error !== '', 'API failure must retain a diagnostic: ' . $case);
    } else {
        $report = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $assert($exit === $expectedExit, 'Wrong monitor exit for ' . $case . ': ' . $error);
        $assert($report['status'] === ($expectedExit === 0 ? 'healthy' : 'action_required'), 'Wrong combined health for ' . $case);
        $assert($report['cleanup']['lookback_days'] === ($case === 'short-lookback' ? 1 : 30)
            && $report['cleanup']['grace_hours'] === ($case === 'long-grace' ? 4 : 1)
            && $report['cleanup']['query_limit_per_label'] === 200, 'Cleanup scope must be machine-readable.');
        $assert($state === null ? $report['cleanup']['pull_requests'] === [] : $report['cleanup']['pull_requests'][0]['state'] === $state, 'Wrong closure classification for ' . $case);
    }
    $commands = file($environment['HEALTH_TEST_TRACE'], FILE_IGNORE_NEW_LINES) ?: [];
    $assert($commands !== [], 'Fixture must observe GitHub reads.');
    $detailReads = 0;
    foreach ($commands as $command) {
        $args = json_decode($command, true, 512, JSON_THROW_ON_ERROR);
        $assert(($args[0] === 'api' || array_slice($args, 0, 2) === ['pr', 'list']) && ! in_array('-X', $args, true), 'Health monitor must never request a remote mutation.');
        if (($args[1] ?? '') === 'repos/example/site/pulls/42') { $detailReads++; }
    }
    $assert($detailReads <= 1, 'A PR returned under both automation labels must be inspected once.');
}
