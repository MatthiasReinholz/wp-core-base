<?php

declare(strict_types=1);

use WpOrgPluginUpdater\ManagedPullRequestBranchCleaner;

/**
 * @param callable(bool,string):void $assert
 */
function run_managed_pr_cleanup_contract_tests(callable $assert): void
{
    $pullRequest = static function (array $overrides = []): array {
        $metadata = $overrides['metadata'] ?? [
            'kind' => 'plugin',
            'component_key' => 'plugin:akismet',
            'branch' => 'codex/wporg-plugin-akismet-5-0-20260831120000',
        ];
        unset($overrides['metadata']);

        $result = array_replace_recursive([
            'number' => 42,
            'state' => 'closed',
            'body' => '<!-- wporg-update-metadata: ' . json_encode($metadata, JSON_THROW_ON_ERROR) . ' -->',
            'labels' => [['name' => 'automation:dependency-update']],
            'head' => [
                'ref' => 'codex/wporg-plugin-akismet-5-0-20260831120000',
                'sha' => 'managed-sha',
                'repo' => ['full_name' => 'example/site'],
            ],
            'base' => [
                'ref' => 'main',
                'repo' => ['full_name' => 'example/site'],
            ],
        ], $overrides);

        if (array_key_exists('labels', $overrides)) {
            $result['labels'] = $overrides['labels'];
        }

        return $result;
    };
    $expectRefusal = static function (array $candidate, string $message, ?string $remoteRevision = null) use ($assert): void {
        $client = new FakeGitHubAutomationClient();
        $client->pullRequestsByNumber[42] = $candidate;
        $git = new FakeGitRunner();
        $branch = (string) ($candidate['head']['ref'] ?? '');

        if ($branch !== '') {
            $git->remoteBranches[$branch] = $remoteRevision ?? (string) ($candidate['head']['sha'] ?? 'managed-sha');
        }

        try {
            (new ManagedPullRequestBranchCleaner($client, $git))->cleanupClosedPullRequest(42);
            $assert(false, $message);
        } catch (RuntimeException) {
            $deleted = array_filter($git->actions, static fn (string $action): bool => str_starts_with($action, 'delete-remote:' . $branch . ':'));
            $assert($deleted === [], $message);
        }
    };

    $client = new FakeGitHubAutomationClient();
    $client->pullRequestsByNumber[42] = $pullRequest();
    $git = new FakeGitRunner();
    $git->remoteBranches['codex/wporg-plugin-akismet-5-0-20260831120000'] = 'managed-sha';
    $result = (new ManagedPullRequestBranchCleaner($client, $git))->cleanupClosedPullRequest(42);
    $assert($result['deleted'] === true, 'Expected a closed same-repository managed PR branch to be deleted.');
    $assert(in_array('delete-remote:codex/wporg-plugin-akismet-5-0-20260831120000:managed-sha', $git->actions, true), 'Expected cleanup to delete the exact managed head ref with a revision lease.');

    $client = new FakeGitHubAutomationClient();
    $managedPullRequest = $pullRequest(['state' => 'open']);
    $git = new FakeGitRunner();
    $git->remoteBranches['codex/wporg-plugin-akismet-5-0-20260831120000'] = 'managed-sha';
    (new ManagedPullRequestBranchCleaner($client, $git))->closeAndCleanup($managedPullRequest, 'Superseded.');
    $assert(count($client->closedPullRequests) === 1, 'Expected updater-driven cleanup to close the PR before deleting its branch.');
    $assert(in_array('delete-remote:codex/wporg-plugin-akismet-5-0-20260831120000:managed-sha', $git->actions, true), 'Expected updater-driven PR closure to clean its managed branch with a revision lease.');

    $client = new FakeGitHubAutomationClient();
    $git = new FakeGitRunner();
    $git->remoteBranches['codex/wporg-plugin-akismet-5-0-20260831120000'] = 'managed-sha';
    $git->failDeleteRemoteBranch = true;
    try {
        (new ManagedPullRequestBranchCleaner($client, $git))->closeAndCleanup($managedPullRequest, 'Superseded.');
        $assert(false, 'Expected remote deletion failure to remain observable after PR closure.');
    } catch (RuntimeException) {
        // The PR close must remain recorded even though branch cleanup failed.
    }
    $assert(count($client->closedPullRequests) === 1, 'Expected cleanup failure not to mask or roll back a successful PR closure.');

    $client = new FakeGitHubAutomationClient();
    $client->pullRequestsByNumber[42] = $pullRequest();
    $result = (new ManagedPullRequestBranchCleaner($client, new FakeGitRunner()))->cleanupClosedPullRequest(42);
    $assert($result['deleted'] === false, 'Expected an already absent managed branch to be an idempotent success.');

    $expectRefusal($pullRequest(['state' => 'open']), 'Expected cleanup to reject an open PR.');
    $expectRefusal($pullRequest(['body' => 'no metadata']), 'Expected cleanup to reject missing metadata.');
    $expectRefusal($pullRequest(['labels' => []]), 'Expected cleanup to reject an unlabelled PR.');
    $expectRefusal($pullRequest(['head' => ['repo' => ['full_name' => 'fork/site']]]), 'Expected cleanup to reject a fork PR.');
    $expectRefusal($pullRequest(['metadata' => [
        'kind' => 'plugin',
        'component_key' => 'plugin:akismet',
        'branch' => 'codex/wporg-different',
    ]]), 'Expected cleanup to reject metadata/head branch disagreement.');
    $expectRefusal($pullRequest([
        'metadata' => ['kind' => 'plugin', 'component_key' => 'plugin:akismet', 'branch' => 'feature/manual'],
        'head' => ['ref' => 'feature/manual'],
    ]), 'Expected cleanup to reject branches outside the managed namespace.');
    $expectRefusal($pullRequest([
        'metadata' => ['kind' => 'plugin', 'component_key' => 'plugin:akismet', 'branch' => 'main'],
        'head' => ['ref' => 'main'],
    ]), 'Expected cleanup to reject the default branch.');
    $expectRefusal($pullRequest(['head' => ['sha' => 'old-sha']]), 'Expected cleanup to reject a branch whose remote SHA drifted.', 'new-sha');
    $expectRefusal($pullRequest(['head' => ['sha' => '']]), 'Expected cleanup to reject a live branch when the host omits its head SHA.');
    $expectRefusal($pullRequest(['labels' => [
        ['name' => 'automation:dependency-update'],
        ['name' => 'automation:framework-update'],
    ]]), 'Expected cleanup to reject ambiguous automation ownership labels.');
    $expectRefusal($pullRequest([
        'metadata' => ['component_key' => 'framework:unexpected', 'branch' => 'codex/framework-1-5-0-20260831120000'],
        'labels' => [['name' => 'automation:framework-update']],
        'head' => ['ref' => 'codex/framework-1-5-0-20260831120000'],
    ]), 'Expected cleanup to reject an unexpected framework component identity.');

    $frameworkBranch = 'codex/framework-1-5-0-20260831120000';
    $client = new FakeGitHubAutomationClient();
    $client->pullRequestsByNumber[42] = $pullRequest([
        'metadata' => ['component_key' => 'framework:wp-core-base', 'branch' => $frameworkBranch],
        'labels' => [['name' => 'automation:framework-update']],
        'head' => ['ref' => $frameworkBranch, 'sha' => 'framework-sha'],
    ]);
    $git = new FakeGitRunner();
    $git->remoteBranches[$frameworkBranch] = 'framework-sha';
    $assert((new ManagedPullRequestBranchCleaner($client, $git))->cleanupClosedPullRequest(42)['deleted'], 'Expected framework-managed branches to use their dedicated namespace.');

    $coreBranch = 'codex/wordpress-core-6-9-5-20260831120000';
    $client = new FakeGitHubAutomationClient();
    $client->pullRequestsByNumber[42] = $pullRequest([
        'metadata' => ['kind' => 'core', 'slug' => 'wordpress-core', 'branch' => $coreBranch],
        'head' => ['ref' => $coreBranch, 'sha' => 'core-sha'],
    ]);
    $git = new FakeGitRunner();
    $git->remoteBranches[$coreBranch] = 'core-sha';
    $assert((new ManagedPullRequestBranchCleaner($client, $git))->cleanupClosedPullRequest(42)['deleted'], 'Expected WordPress core branches to use their dedicated namespace.');
}
