<?php

declare(strict_types=1);

use WpOrgPluginUpdater\PullRequestBlocker;

/**
 * @param callable(bool,string):void $assert
 */
function run_blocker_state_tests(callable $assert): void
{
    $metadataComment = '<!-- wporg-update-metadata: {"component_key":"plugin:premium:example-vendor:demo-plugin","target_version":"2.0.0","blocked_by":[5]} -->';
    $eventPath = sys_get_temp_dir() . '/wporg-pr-blocker-' . bin2hex(random_bytes(4)) . '.json';
    file_put_contents($eventPath, json_encode([
        'pull_request' => [
            'number' => 9,
            'body' => $metadataComment,
        ],
    ], JSON_THROW_ON_ERROR));
    putenv('GITHUB_EVENT_PATH=' . $eventPath);

    $blockedReader = new FakePullRequestReader(
        openPullRequests: [
            [
                'number' => 7,
                'body' => '<!-- wporg-update-metadata: {"component_key":"plugin:premium:example-vendor:demo-plugin","target_version":"1.9.0"} -->',
            ],
        ],
        pullRequestsByNumber: [
            5 => ['number' => 5, 'state' => 'open', 'merged_at' => null],
        ]
    );
    $blockedStatus = (new PullRequestBlocker($blockedReader))->evaluateCurrentPullRequestStatus();
    $assert($blockedStatus['status'] === PullRequestBlocker::STATUS_BLOCKED, 'Expected pr-blocker to report a blocked state when a predecessor PR is still open.');
    $assert($blockedStatus['exit_code'] === 1, 'Expected pr-blocker blocked status to keep exit code 1.');

    $closedPredecessorReader = new FakePullRequestReader(
        openPullRequests: [],
        pullRequestsByNumber: [
            5 => ['number' => 5, 'state' => 'closed', 'merged_at' => null],
        ]
    );
    $closedPredecessorStatus = (new PullRequestBlocker($closedPredecessorReader))->evaluateCurrentPullRequestStatus();
    $assert($closedPredecessorStatus['status'] !== PullRequestBlocker::STATUS_BLOCKED, 'Expected pr-blocker to ignore closed predecessors that were not merged.');

    $degradedReader = new FakePullRequestReader(
        openPullRequests: [],
        listFailure: new RuntimeException('GitHub API unavailable.')
    );
    $degradedStatus = (new PullRequestBlocker($degradedReader))->evaluateCurrentPullRequestStatus();
    $assert($degradedStatus['status'] === PullRequestBlocker::STATUS_DEGRADED, 'Expected pr-blocker to report degraded status when GitHub verification fails.');
    $assert($degradedStatus['exit_code'] === 1, 'Expected degraded pr-blocker status to fail closed while GitHub verification is unavailable.');

    $degradedPredecessorReader = new FakePullRequestReader(
        openPullRequests: [],
        pullRequestFailures: [
            5 => new RuntimeException('Missing permission to read predecessor PR.'),
        ]
    );
    $degradedPredecessorStatus = (new PullRequestBlocker($degradedPredecessorReader))->evaluateCurrentPullRequestStatus();
    $assert($degradedPredecessorStatus['status'] === PullRequestBlocker::STATUS_DEGRADED, 'Expected pr-blocker to report degraded status when predecessor verification fails.');
    $assert($degradedPredecessorStatus['exit_code'] === 1, 'Expected degraded predecessor verification to fail closed.');
}
