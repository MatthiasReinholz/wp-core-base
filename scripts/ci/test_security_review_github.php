<?php

declare(strict_types=1);

require __DIR__ . '/security_review_github.php';

use WpCoreBaseCi\SecurityReviewGitHub;

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$reject = static function (Closure $operation, string $message) use ($assert): void {
    try {
        $operation();
    } catch (RuntimeException) {
        $assert(true, $message);
        return;
    }
    $assert(false, $message);
};
$pages = [array_map(static fn (int $id): array => ['id' => $id], range(1, 100)), [['id' => 101]]];
$calls = [];
$client = new SecurityReviewGitHub(static function (string $path) use (&$calls, $pages): array {
    $calls[] = $path;
    return $pages[count($calls) - 1];
});
$assert(count($client->inventory('repos/example/site/analyses?ref=main')) === 101, 'Read all analysis pages.');
$assert($calls[1] === 'repos/example/site/analyses?ref=main&per_page=100&page=2', 'Preserve filters on subsequent pages.');
$empty = new SecurityReviewGitHub(static fn (): array => []);
$assert($empty->inventory('empty') === [], 'Empty complete inventory is valid input for policy evaluation.');
foreach ([['unexpected' => []], [['id' => 1], ['id' => 1]], [['id' => '1']], [null]] as $invalid) {
    $reject(static fn () => (new SecurityReviewGitHub(static fn (): array => $invalid))->inventory('invalid'), 'Reject malformed or duplicate inventory rows.');
}
$reject(static fn () => (new SecurityReviewGitHub(static fn (): array => ['total_count' => 2, 'runs' => [['id' => 1]]]))->inventory('truncated', 'runs'), 'Reject truncated totals.');
$number = 0;
$changing = new SecurityReviewGitHub(static function () use (&$number, $pages): array {
    return ['total_count' => ++$number === 1 ? 101 : 102, 'runs' => $pages[$number - 1]];
});
$reject(static fn () => $changing->inventory('changing', 'runs'), 'Reject changing pagination totals.');
$unbounded = new SecurityReviewGitHub(static function (string $path): array {
    preg_match('/page=(\d+)$/', $path, $match);
    return array_map(static fn (int $id): array => ['id' => $id], range(((int) $match[1] - 1) * 100 + 1, (int) $match[1] * 100));
});
$reject(static fn () => $unbounded->inventory('unbounded'), 'Reject inventory exceeding bounded pagination.');
$sha = str_repeat('a', 40);
$run = ['id' => 1, 'path' => 'dynamic/codeql', 'head_sha' => $sha, 'head_branch' => 'main', 'event' => 'dynamic',
    'created_at' => '2026-09-25T10:00:00Z', 'run_started_at' => '2026-09-25T10:00:01Z', 'run_attempt' => 1,
    'status' => 'completed', 'conclusion' => 'success'];
$assert($client->completedScan([$run], 'dynamic/codeql', $sha, 'main') === $run, 'Accept exact successful scanner identity.');
$assert($client->completedScan([], 'dynamic/codeql', $sha, 'main') === null, 'Missing scan must remain pending.');
$pending = array_replace($run, ['id' => 2, 'status' => 'in_progress', 'conclusion' => null]);
$assert($client->completedScan([$pending, $run], 'dynamic/codeql', $sha, 'main') === null, 'Newest pending run supersedes old success regardless of response order.');
$rerun = array_replace($run, ['run_attempt' => 2, 'status' => 'queued', 'conclusion' => null]);
$assert($client->completedScan([$run, $rerun], 'dynamic/codeql', $sha, 'main') === null, 'New attempt supersedes old successful attempt.');
$laterOriginal = array_replace($run, ['id' => 2, 'created_at' => '2026-09-25T11:00:00Z', 'run_started_at' => '2026-09-25T11:00:01Z']);
$olderRunRerun = array_replace($run, ['run_attempt' => 2, 'run_started_at' => '2026-09-25T13:00:00Z']);
foreach (['queued', 'in_progress', 'waiting', 'pending', 'requested'] as $status) {
    $unresolved = array_replace($olderRunRerun, ['status' => $status, 'conclusion' => null]);
    $assert($client->completedScan([$unresolved, $laterOriginal], 'dynamic/codeql', $sha, 'main') === null, 'An older run rerun later must block while its current attempt is unresolved: ' . $status);
}
$queuedWithoutStart = array_replace($olderRunRerun, ['status' => 'queued', 'conclusion' => null]);
unset($queuedWithoutStart['run_started_at']);
$assert($client->completedScan([$laterOriginal, $queuedWithoutStart], 'dynamic/codeql', $sha, 'main') === null, 'A queued rerun without a start timestamp must remain pending.');
$queuedWithOldStart = array_replace($queuedWithoutStart, ['run_started_at' => $run['run_started_at']]);
$assert($client->completedScan([$laterOriginal, $queuedWithOldStart], 'dynamic/codeql', $sha, 'main') === null, 'A queued rerun with its previous start timestamp must remain pending.');
$failedOlderRunRerun = array_replace($olderRunRerun, ['conclusion' => 'failure']);
$reject(static fn () => $client->completedScan([$laterOriginal, $failedOlderRunRerun], 'dynamic/codeql', $sha, 'main'), 'A later failed attempt of an older run supersedes a newer run original success.');
$assert($client->completedScan([$laterOriginal, $olderRunRerun], 'dynamic/codeql', $sha, 'main') === $olderRunRerun, 'Choose the most recently started successful attempt independently of original creation time.');
$oldFailure = array_replace($run, ['conclusion' => 'failure']);
$assert($client->completedScan([$oldFailure, $laterOriginal], 'dynamic/codeql', $sha, 'main') === $laterOriginal, 'A later successful attempt can supersede a completed older failure.');
foreach ([['id' => 2, 'conclusion' => 'failure'], ['head_sha' => str_repeat('b', 40)], ['path' => 'other'],
    ['head_branch' => 'other'], ['event' => 'pull_request'], ['created_at' => 'invalid'], ['created_at' => 'tomorrow'],
    ['run_started_at' => null], ['run_started_at' => '2026-02-30T12:00:00Z'], ['run_attempt' => 0], ['status' => 'unknown']] as $change) {
    $candidate = array_replace($run, $change);
    $reject(static fn () => $client->completedScan([$candidate], 'dynamic/codeql', $sha, 'main'), 'Reject failed or mismatched scanner evidence.');
}
$client->assertFreshAnalyses([['created_at' => '2026-09-25T10:01:00Z']], $run);
$assert(true, 'Fresh analysis follows scanner attempt start.');
$reject(static fn () => $client->assertFreshAnalyses([['created_at' => '2026-09-25T10:00:00Z']], $run), 'Reject old analyses borrowed by a rerun.');
$reject(static fn () => $client->assertFreshAnalyses([['created_at' => 'invalid']], $run), 'Reject malformed analysis timestamp.');
$reject(static fn () => $client->assertFreshAnalyses([], []), 'Reject missing scanner start timestamp.');
fwrite(STDOUT, "Security review GitHub reader: {$assertions} assertions passed.\n");
