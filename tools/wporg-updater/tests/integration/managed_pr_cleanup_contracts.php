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

    $sharedBranch = 'codex/wporg-plugin-akismet-5-0-20260831120000';
    foreach ([['open', []], ['opened', []], ['open', [['name' => 'automation:framework-update']]]] as [$openState, $labels]) {
        $client = new FakeGitHubAutomationClient();
        $client->pullRequestsByNumber[42] = $pullRequest();
        $client->openPullRequests = [$pullRequest([
            'number' => 43, 'state' => $openState, 'labels' => $labels,
            'base' => ['ref' => 'release'],
        ])];
        $git = new FakeGitRunner();
        $git->remoteBranches[$sharedBranch] = 'managed-sha';
        $result = (new ManagedPullRequestBranchCleaner($client, $git))->cleanupClosedPullRequest(42);
        $assert(! $result['deleted'] && $result['reason'] === 'reused-by-open-pr' && $result['reused_by_pull_request'] === 43,
            'Cleanup must explicitly preserve a head reused by an open PR regardless of labels or base branch.');
        $assert($git->remoteBranches[$sharedBranch] === 'managed-sha' && $git->actions === [], 'A reused branch must remain untouched.');
    }

    foreach ([
        ['number' => 43, 'state' => 'open', 'head' => ['repo' => ['full_name' => 'fork/site']]],
        ['number' => 43, 'state' => 'open', 'head' => ['ref' => 'other-branch']],
        ['number' => 43, 'state' => 'opened', 'head' => ['ref' => 'other-branch']],
        ['number' => 43, 'state' => 'closed'],
    ] as $other) {
        $client = new FakeGitHubAutomationClient();
        $client->pullRequestsByNumber[42] = $pullRequest();
        $client->openPullRequests = [$pullRequest($other)];
        $git = new FakeGitRunner();
        $git->remoteBranches[$sharedBranch] = 'managed-sha';
        $result = (new ManagedPullRequestBranchCleaner($client, $git))->cleanupClosedPullRequest(42);
        $assert($result['deleted'] && $result['reason'] === 'deleted', 'Unrelated fork heads, different heads, or closed PRs must not claim another open reuse.');
        $assert(in_array('delete-remote:' . $sharedBranch . ':managed-sha', $git->actions, true), 'Non-reused cleanup must retain exact-head leased deletion.');
    }

    foreach (['open', 'opened'] as $openState) {
        $client = new FakeGitHubAutomationClient();
        $client->pullRequestsByNumber[42] = $pullRequest();
        $client->openPullRequests = [$pullRequest(['state' => $openState])];
        $git = new FakeGitRunner();
        $git->remoteBranches[$sharedBranch] = 'managed-sha';
        $result = (new ManagedPullRequestBranchCleaner($client, $git))->cleanupClosedPullRequest(42);
        $assert(! $result['deleted'] && $result['reused_by_pull_request'] === 42 && $git->actions === [], 'A PR reopened after the closed snapshot must preserve its branch, even when it is the cleanup target.');
    }

    foreach ([
        $pullRequest(['number' => 43, 'state' => 'open', 'head' => ['repo' => ['full_name' => '']]]),
        $pullRequest(['number' => 43, 'state' => 'open', 'head' => ['ref' => '']]),
        $pullRequest(['number' => 0, 'state' => 'open']),
    ] as $incomplete) {
        $client = new FakeGitHubAutomationClient();
        $client->pullRequestsByNumber[42] = $pullRequest();
        $client->openPullRequests = [$incomplete];
        $git = new FakeGitRunner();
        $git->remoteBranches[$sharedBranch] = 'managed-sha';
        $failure = null;
        try { (new ManagedPullRequestBranchCleaner($client, $git))->cleanupClosedPullRequest(42); }
        catch (RuntimeException $exception) { $failure = $exception; }
        $assert($failure !== null && str_contains($failure->getMessage(), 'inventory'), 'Ambiguous open-PR identities must prevent branch deletion.');
        $assert($git->actions === [] && isset($git->remoteBranches[$sharedBranch]), 'Incomplete reuse evidence must preserve the branch.');
    }

    $client = new ManagedPrCleanupFailingInventoryClient($pullRequest());
    $git = new FakeGitRunner();
    $git->remoteBranches[$sharedBranch] = 'managed-sha';
    $failure = null;
    try { (new ManagedPullRequestBranchCleaner($client, $git))->cleanupClosedPullRequest(42); }
    catch (RuntimeException $exception) { $failure = $exception; }
    $assert($failure !== null && $failure->getMessage() === 'Open PR inventory unavailable.', 'Reuse inventory API failures must propagate.');
    $assert($git->actions === [] && isset($git->remoteBranches[$sharedBranch]), 'A failed inventory read must never authorize branch deletion.');

    run_managed_pr_refresh_guard_contract_tests($assert);
}

final class ManagedPrCleanupFailingInventoryClient implements \WpOrgPluginUpdater\AutomationClient
{
    public function __construct(private readonly array $pullRequest) {}
    public function getDefaultBranch(): string { return 'main'; }
    public function getPullRequest(int $number): array { return $this->pullRequest; }
    public function listOpenPullRequests(?string $label = null): array { throw new RuntimeException('Open PR inventory unavailable.'); }
    public function ensureLabels(array $definitions): void {}
    public function listOpenIssues(?string $label = null): array { return []; }
    public function createIssue(string $title, string $body, array $labels = []): array { throw new LogicException('Unexpected write.'); }
    public function updateIssue(int $number, string $title, string $body): array { throw new LogicException('Unexpected write.'); }
    public function closeIssue(int $number, ?string $comment = null): void { throw new LogicException('Unexpected write.'); }
    public function createPullRequest(string $title, string $head, string $base, string $body, bool $draft): array { throw new LogicException('Unexpected write.'); }
    public function updatePullRequest(int $number, string $title, string $body): array { throw new LogicException('Unexpected write.'); }
    public function closePullRequest(int $number, ?string $comment = null): void { throw new LogicException('Unexpected write.'); }
    public function setIssueLabels(int $number, array $labels): void { throw new LogicException('Unexpected write.'); }
    public function setPullRequestLabels(int $number, array $labels): void { throw new LogicException('Unexpected write.'); }
    public function convertToDraft(int $number): void { throw new LogicException('Unexpected write.'); }
    public function markReadyForReview(int $number): void { throw new LogicException('Unexpected write.'); }
}

/** @param callable(bool,string):void $assert */
function run_managed_pr_refresh_guard_contract_tests(callable $assert): void
{
    $repoRoot = dirname(__DIR__, 4);
    $config = \WpOrgPluginUpdater\Config::load($repoRoot);
    $framework = \WpOrgPluginUpdater\FrameworkConfig::load($repoRoot);
    $dependency = $config->managedDependencies()[0];
    $http = new \WpOrgPluginUpdater\HttpClient();
    $releaseAt = '2026-09-25T08:00:00Z';
    $release = ['version' => '99.0.1', 'release_at' => $releaseAt,
        'release' => ['version' => '99.0.1'],
        'download_url' => 'https://example.test/release.zip', 'release_url' => 'https://example.test/release',
        'release_text' => 'A fixture release.', 'release_html' => '<p>A fixture release.</p>',
        'target_wordpress_core' => $framework->baseline['wordpress_core'], 'notes_sections' => []];
    $source = new class($release) implements \WpOrgPluginUpdater\ManagedDependencySource {
        public function __construct(private readonly array $release) {}
        public function key(): string { return 'wordpress.org'; }
        public function fetchCatalog(array $dependency): array { return ['latest_version' => $this->release['version'], 'latest_release_at' => $this->release['release_at']]; }
        public function releaseDataForVersion(array $dependency, array $catalog, string $targetVersion, string $fallbackReleaseAt): array { return $this->release; }
        public function downloadReleaseToFile(array $dependency, array $releaseData, string $destination): void { throw new LogicException('Unexpected fixture download.'); }
        public function supportsForumSync(array $dependency): bool { return false; }
    };
    $frameworkSource = new class($release) implements \WpOrgPluginUpdater\FrameworkReleaseSource {
        public function __construct(private readonly array $release) {}
        public function fetchStableReleases(\WpOrgPluginUpdater\FrameworkConfig $framework): array { return [$this->release]; }
        public function releaseData(\WpOrgPluginUpdater\FrameworkConfig $framework, array $release): array { return $release; }
        public function downloadVerifiedReleaseAsset(\WpOrgPluginUpdater\FrameworkConfig $framework, array $release, string $destination): void {
            $metadata = $framework->toArray();
            $metadata['version'] = $this->release['version'];
            $zip = new ZipArchive();
            if ($zip->open($destination, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { throw new RuntimeException('Unable to build local metadata fixture.'); }
            $zip->addFromString('wp-core-base/.wp-core-base/framework.php', '<?php return ' . var_export($metadata, true) . ';');
            $zip->close();
        }
    };
    $makeUpdater = static function (string $kind, FakeGitHubAutomationClient $client, FakeGitRunner $git) use ($repoRoot, $config, $framework, $http, $source, $frameworkSource): object {
        $classifier = new \WpOrgPluginUpdater\ReleaseClassifier();
        $renderer = new \WpOrgPluginUpdater\PrBodyRenderer();
        $inspector = new \WpOrgPluginUpdater\RuntimeInspector($config->runtime);
        return match ($kind) {
            'dependency' => new \WpOrgPluginUpdater\Updater($config, new \WpOrgPluginUpdater\DependencyScanner(),
                new \WpOrgPluginUpdater\WordPressOrgClient($http), new \WpOrgPluginUpdater\GitHubReleaseClient($http),
                new \WpOrgPluginUpdater\ManagedSourceRegistry($source), new \WpOrgPluginUpdater\SupportForumClient($http, 10),
                $classifier, $renderer, $client, $git, $inspector, new \WpOrgPluginUpdater\ManifestWriter(), $http),
            'core' => new \WpOrgPluginUpdater\CoreUpdater($config, new \WpOrgPluginUpdater\CoreScanner(),
                new \WpOrgPluginUpdater\WordPressCoreClient($http), $classifier, $renderer, $client, $git),
            'framework' => new \WpOrgPluginUpdater\FrameworkSyncer($framework, $repoRoot, $config, $frameworkSource,
                $classifier, $renderer, $client, $git, $inspector),
        };
    };
    $invokeRefresh = static function (object $updater, string $kind, array $pr) use ($dependency, $release, $framework): bool {
        $method = new ReflectionMethod($updater, 'refreshPullRequest');
        return match ($kind) {
            'dependency' => $method->invoke($updater, $dependency, ['version' => '1.0.0', 'name' => 'Fixture', 'path' => $dependency['path']],
                ['latest_version' => $release['version'], 'latest_release_at' => $release['release_at']], $pr, [], 'main', 'main-sha'),
            'core' => $method->invoke($updater, '1.0.0', $release, $pr, [], 'main', 'main-sha'),
            'framework' => $method->invoke($updater, $pr, $release, [], 'main', 'main-sha', false, $framework),
        };
    };

    foreach (['dependency', 'core', 'framework'] as $kind) {
        $branch = 'codex/' . $kind . '-refresh-fixture';
        $pr = ['number' => 42, 'state' => 'open', 'draft' => false,
            'metadata' => ['branch' => $branch, 'base_version' => '1.0.0', 'target_version' => '99.0.1', 'release_at' => $releaseAt],
            'head' => ['ref' => $branch, 'sha' => 'owned-sha', 'repo' => ['full_name' => 'example/site']],
            'base' => ['ref' => 'main', 'repo' => ['full_name' => 'example/site']],
            'planned_target_version' => '99.0.1', 'planned_release_at' => $releaseAt, 'planned_scope' => 'major',
            'requires_branch_refresh' => true, 'requires_code_update' => true];
        foreach ([
            [['metadata' => ['branch' => 'feature/unrelated']], 'instead of its pull request head'],
            [['metadata' => ['branch' => 'feature/unrelated'], 'planned_target_version' => '0.0.0'], 'instead of its pull request head'],
            [['metadata' => ['branch' => 'main']], 'protected branch'],
            [['metadata' => ['branch' => 'release'], 'head' => ['ref' => 'release'], 'base' => ['ref' => 'release']], 'protected branch'],
            [['head' => ['repo' => ['full_name' => 'fork/site']]], 'same-repository'],
            [['head' => ['ref' => '']], 'same-repository'],
            [['metadata' => ['branch' => ''], 'head' => ['ref' => '']], 'missing a branch name'],
        ] as [$overrides, $message]) {
            $client = new FakeGitHubAutomationClient();
            $git = new FakeGitRunner();
            $git->remoteBranches[$branch] = 'owned-sha';
            $git->remoteBranches['feature/unrelated'] = 'unrelated-sentinel';
            $before = $git->remoteBranches;
            $failure = null;
            try { $invokeRefresh($makeUpdater($kind, $client, $git), $kind, array_replace_recursive($pr, $overrides)); }
            catch (Throwable $exception) { $failure = $exception; }
            $assert($failure instanceof RuntimeException && str_contains($failure->getMessage(), $message), $kind . ' refresh must reject unsafe branch identity before reaching mutation code: ' . ($failure?->getMessage() ?? 'no failure'));
            $assert($git->actions === [] && $git->remoteBranches === $before, $kind . ' refresh must preserve unrelated branches without checkout, push or rollback.');
            $assert($client->updatedPullRequests === [] && $client->closedPullRequests === [] && $client->labelUpdates === [], $kind . ' refresh must not update or close a PR when branch ownership fails.');
        }
        foreach ([false, true] as $legacyMissingBranch) {
            $client = new FakeGitHubAutomationClient();
            $git = new FakeGitRunner();
            $safePr = $pr;
            $safePr['requires_branch_refresh'] = $safePr['requires_code_update'] = false;
            if ($legacyMissingBranch) { unset($safePr['metadata']['branch']); }
            $assert($invokeRefresh($makeUpdater($kind, $client, $git), $kind, $safePr), $kind . ' must still refresh a same-head PR, including legacy metadata without a branch.');
            $assert(count($client->updatedPullRequests) === 1 && $git->actions === [], $kind . ' same-head metadata refresh must complete without unnecessary Git mutations.');
        }
    }

    // Queue validation must precede duplicate closure, which occurs before refresh.
    $client = new FakeGitHubAutomationClient();
    $git = new FakeGitRunner();
    $metadata = ['component_key' => 'framework:wp-core-base', 'branch' => 'codex/framework-fixture',
        'base_version' => $framework->version, 'target_version' => '99.0.1', 'release_at' => $releaseAt];
    $basePr = ['number' => 42, 'state' => 'open', 'labels' => [['name' => 'automation:framework-update']],
        'body' => '<!-- wporg-update-metadata: ' . json_encode($metadata, JSON_THROW_ON_ERROR) . ' -->',
        'head' => ['ref' => 'codex/framework-fixture', 'sha' => 'owned-sha', 'repo' => ['full_name' => 'example/site']],
        'base' => ['ref' => 'main', 'repo' => ['full_name' => 'example/site']]];
    $forged = $basePr;
    $forged['number'] = 43;
    $metadata['branch'] = 'feature/unrelated';
    $forged['body'] = '<!-- wporg-update-metadata: ' . json_encode($metadata, JSON_THROW_ON_ERROR) . ' -->';
    $client->openPullRequests = [$basePr, $forged];
    $failure = null;
    try { $makeUpdater('framework', $client, $git)->sync(); }
    catch (RuntimeException $exception) { $failure = $exception; }
    $assert($failure !== null && str_contains($failure->getMessage(), 'instead of its pull request head'), 'An invalid queued head must be rejected before duplicate selection and closure.');
    $assert($git->actions === [] && $client->closedPullRequests === [] && $client->updatedPullRequests === [], 'Queue validation must preserve all PRs and branches when duplicate metadata names an unrelated head.');
}
