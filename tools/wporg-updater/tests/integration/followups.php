<?php

declare(strict_types=1);

use WpOrgPluginUpdater\PullRequestBlocker;
use WpOrgPluginUpdater\SyncReport;

/**
 * @param callable(bool,string):void $assert
 */
function run_followup_integration_tests(callable $assert): void
{
    $report = SyncReport::build([], [], [
        [
            'component_key' => 'plugin:github-release:example',
            'target_version' => '2.0.0',
            'trust_state' => 'verified',
            'trust_details' => 'checksum sidecar matched',
        ],
    ]);
    $summary = SyncReport::renderSummary($report);
    $assert(str_contains($summary, 'Dependency Trust States'), 'Expected sync report summary to include trust-state section when trust records are present.');

    $eventPath = sys_get_temp_dir() . '/wporg-pr-blocker-followup-' . bin2hex(random_bytes(4)) . '.json';
    file_put_contents($eventPath, json_encode([
        'pull_request' => [
            'number' => 42,
            'body' => '<!-- wporg-update-metadata: {"component_key":"plugin:premium:example-vendor:demo-plugin","target_version":"2.0.0","blocked_by":[]} -->',
        ],
    ], JSON_THROW_ON_ERROR));
    putenv('GITHUB_EVENT_PATH=' . $eventPath);

    $reader = new FakePullRequestReader(
        openPullRequests: [
            [
                'number' => 42,
                'body' => '<!-- wporg-update-metadata: {"component_key":"plugin:premium:example-vendor:demo-plugin","target_version":"2.0.0","blocked_by":[]} -->',
            ],
            [
                'number' => 43,
                'body' => '<!-- wporg-update-metadata: {"component_key":"plugin:premium:example-vendor:demo-plugin","target_version":"1.9.0","blocked_by":[]} -->',
            ],
        ],
        pullRequestsByNumber: [
            42 => [
                'number' => 42,
                'state' => 'open',
                'body' => '<!-- wporg-update-metadata: {"component_key":"plugin:premium:example-vendor:demo-plugin","target_version":"2.0.0","blocked_by":[]} -->',
            ],
        ]
    );

    $resultByNumber = (new PullRequestBlocker($reader))->evaluatePullRequestNumberStatus(42);
    $assert($resultByNumber['pull_request_number'] === 42, 'Expected blocker number-based evaluation to include the evaluated pull request number.');
    $assert($resultByNumber['state'] === PullRequestBlocker::STATE_INTENTIONALLY_BLOCKED, 'Expected blocker number-based evaluation to include intentional blocked state when an older PR is open.');

    $legacyReader = new FakePullRequestReader(
        openPullRequests: [
            [
                'number' => 52,
                'body' => '<!-- wporg-update-metadata: {"kind":"plugin","source":"wordpress.org","slug":"legacy-plugin","target_version":"2.0.0","blocked_by":[]} -->',
            ],
            [
                'number' => 53,
                'body' => '<!-- wporg-update-metadata: {"kind":"plugin","source":"wordpress.org","slug":"legacy-plugin","target_version":"1.9.0","blocked_by":[]} -->',
            ],
        ],
        pullRequestsByNumber: [
            52 => [
                'number' => 52,
                'state' => 'open',
                'body' => '<!-- wporg-update-metadata: {"kind":"plugin","source":"wordpress.org","slug":"legacy-plugin","target_version":"2.0.0","blocked_by":[]} -->',
            ],
        ]
    );
    $legacyResult = (new PullRequestBlocker($legacyReader))->evaluatePullRequestNumberStatus(52);
    $assert($legacyResult['state'] === PullRequestBlocker::STATE_INTENTIONALLY_BLOCKED, 'Expected blocker to keep legacy metadata PRs blocked when an older same-identity PR remains open.');
    $assert(in_array('#53 (1.9.0)', (array) ($legacyResult['blockers'] ?? []), true), 'Expected blocker to list the older legacy metadata PR as a blocker.');

    $conflictingIdentityReader = new FakePullRequestReader(
        openPullRequests: [
            [
                'number' => 62,
                'body' => '<!-- wporg-update-metadata: {"component_key":"plugin:premium:example-vendor:demo-plugin","kind":"plugin","source":"premium","slug":"demo-plugin","provider":"example-vendor","target_version":"2.0.0","blocked_by":[]} -->',
            ],
            [
                'number' => 63,
                'body' => '<!-- wporg-update-metadata: {"component_key":"plugin:premium:example-vendor:demo-plugin","kind":"plugin","source":"premium","slug":"other-plugin","provider":"example-vendor","target_version":"1.9.0","blocked_by":[]} -->',
            ],
        ],
        pullRequestsByNumber: [
            62 => [
                'number' => 62,
                'state' => 'open',
                'body' => '<!-- wporg-update-metadata: {"component_key":"plugin:premium:example-vendor:demo-plugin","kind":"plugin","source":"premium","slug":"demo-plugin","provider":"example-vendor","target_version":"2.0.0","blocked_by":[]} -->',
            ],
        ]
    );
    $conflictingIdentityResult = (new PullRequestBlocker($conflictingIdentityReader))->evaluatePullRequestNumberStatus(62);
    $assert($conflictingIdentityResult['status'] === PullRequestBlocker::STATUS_CLEAR, 'Expected blocker to ignore PRs that share component_key but conflict on explicit identity fields.');

    $scanResult = (new PullRequestBlocker($reader))->evaluateOpenAutomationPullRequestsStatus();
    $assert($scanResult['status'] === PullRequestBlocker::STATUS_CLEAR, 'Expected blocker reconciliation scan to remain clear when no degraded evaluations exist.');
    $assert(count($scanResult['results']) >= 2, 'Expected blocker reconciliation scan to evaluate open automation PRs.');
}
