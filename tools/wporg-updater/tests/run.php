<?php

declare(strict_types=1);

use WpOrgPluginUpdater\Config;
use WpOrgPluginUpdater\CommandHelp;
use WpOrgPluginUpdater\ConfigWriter;
use WpOrgPluginUpdater\CoreScanner;
use WpOrgPluginUpdater\AbstractPremiumManagedSource;
use WpOrgPluginUpdater\AdminGovernanceExporter;
use WpOrgPluginUpdater\BranchRollbackGuard;
use WpOrgPluginUpdater\DependencyAuthoringService;
use WpOrgPluginUpdater\DependencyMetadataResolver;
use WpOrgPluginUpdater\DependencyScanner;
use WpOrgPluginUpdater\DownstreamScaffolder;
use WpOrgPluginUpdater\ExtractedPayloadLocator;
use WpOrgPluginUpdater\FileChecksum;
use WpOrgPluginUpdater\FrameworkConfig;
use WpOrgPluginUpdater\FrameworkInstaller;
use WpOrgPluginUpdater\FrameworkReleaseNotes;
use WpOrgPluginUpdater\FrameworkReleasePreparer;
use WpOrgPluginUpdater\FrameworkReleaseSignature;
use WpOrgPluginUpdater\FrameworkPublicContractVerifier;
use WpOrgPluginUpdater\FrameworkReleaseArtifactBuilder;
use WpOrgPluginUpdater\FrameworkReleaseVerifier;
use WpOrgPluginUpdater\FrameworkRuntimeFiles;
use WpOrgPluginUpdater\FrameworkWriter;
use WpOrgPluginUpdater\GitHubAutomationClient;
use WpOrgPluginUpdater\GitHubReleaseClient;
use WpOrgPluginUpdater\GitHubReleaseManagedSource;
use WpOrgPluginUpdater\GitHubPullRequestReader;
use WpOrgPluginUpdater\GitHubReleaseSource;
use WpOrgPluginUpdater\GitRunnerInterface;
use WpOrgPluginUpdater\HttpStatusRuntimeException;
use WpOrgPluginUpdater\HttpClient;
use WpOrgPluginUpdater\InteractivePrompter;
use WpOrgPluginUpdater\ManagedDependencySource;
use WpOrgPluginUpdater\ManagedPullRequestCanonicalizer;
use WpOrgPluginUpdater\ManagedSourceRegistry;
use WpOrgPluginUpdater\ManifestWriter;
use WpOrgPluginUpdater\ManifestSuggester;
use WpOrgPluginUpdater\LabelHelper;
use WpOrgPluginUpdater\OutputRedactor;
use WpOrgPluginUpdater\PremiumProviderRegistry;
use WpOrgPluginUpdater\PremiumProviderScaffolder;
use WpOrgPluginUpdater\PremiumCredentialsStore;
use WpOrgPluginUpdater\PrBodyRenderer;
use WpOrgPluginUpdater\PullRequestBlocker;
use WpOrgPluginUpdater\ReleaseClassifier;
use WpOrgPluginUpdater\ReleaseSignatureKeyStore;
use WpOrgPluginUpdater\RuntimeInspector;
use WpOrgPluginUpdater\RuntimeOwnershipInspector;
use WpOrgPluginUpdater\RuntimeStager;
use WpOrgPluginUpdater\SupportForumClient;
use WpOrgPluginUpdater\SyncReport;
use WpOrgPluginUpdater\ArchiveDownloader;
use WpOrgPluginUpdater\WordPressCoreClient;
use WpOrgPluginUpdater\WordPressOrgManagedSource;
use WpOrgPluginUpdater\WordPressOrgSource;
use WpOrgPluginUpdater\WordPressOrgClient;
use WpOrgPluginUpdater\ZipExtractor;

require dirname(__DIR__) . '/src/Autoload.php';

final class ExamplePremiumManagedSource extends AbstractPremiumManagedSource
{
    public function key(): string
    {
        return 'example-vendor';
    }

    public function fetchCatalog(array $dependency): array
    {
        $this->validateCredentialConfiguration($dependency);

        return [
            'source' => $this->key(),
            'latest_version' => (string) ($dependency['version'] ?? '1.0.0'),
            'latest_release_at' => gmdate(DATE_ATOM),
            'payload' => [
                'download_url' => 'https://example.com/example-vendor.zip',
            ],
        ];
    }

    public function releaseDataForVersion(array $dependency, array $catalog, string $targetVersion, string $fallbackReleaseAt): array
    {
        return [
            'source' => $this->key(),
            'version' => $targetVersion,
            'release_at' => (string) ($catalog['latest_release_at'] ?? $fallbackReleaseAt),
            'archive_subdir' => trim((string) $dependency['archive_subdir'], '/'),
            'download_url' => 'https://example.com/example-vendor.zip',
            'notes_markup' => '<p>Release notes unavailable.</p>',
            'notes_text' => 'Release notes unavailable.',
            'source_reference' => 'https://example.com/example-vendor',
            'source_details' => [
                ['label' => 'Update contract', 'value' => $this->updateContractDescription($dependency)],
            ],
        ];
    }

    public function downloadReleaseToFile(array $dependency, array $releaseData, string $destination): void
    {
        throw new RuntimeException('Not used in tests.');
    }

    protected function requiredCredentialFields(): array
    {
        return ['license_key'];
    }
}

final class FakeGitRunner implements GitRunnerInterface
{
    public ?string $currentBranch = 'main';
    public string $currentRevision = 'main-sha';
    public bool $clean = true;
    public bool $failCommit = false;

    /** @var array<string, string> */
    public array $localBranches = ['main' => 'main-sha'];

    /** @var array<string, string> */
    public array $remoteBranches = ['main' => 'main-sha'];

    /** @var list<string> */
    public array $actions = [];

    public function checkoutBranch(string $baseBranch, string $branch, bool $resetToBase = false): void
    {
        $baseRevision = $this->remoteBranches[$baseBranch] ?? ($this->localBranches[$baseBranch] ?? $this->currentRevision);
        $startingRevision = ($resetToBase || ! isset($this->remoteBranches[$branch]))
            ? $baseRevision
            : $this->remoteBranches[$branch];

        $this->localBranches[$branch] = $startingRevision;
        $this->currentBranch = $branch;
        $this->currentRevision = $startingRevision;
        $this->clean = true;
        $this->actions[] = sprintf('checkout:%s', $branch);
    }

    public function commitAndPush(string $branch, string $message, array $paths, bool $force = false): bool
    {
        $this->actions[] = sprintf('commit:%s', $branch);

        if ($this->failCommit) {
            throw new RuntimeException('Simulated commit failure.');
        }

        $revision = $branch . '-commit-' . (count($this->actions) + 1);
        $this->localBranches[$branch] = $revision;
        $this->remoteBranches[$branch] = $revision;
        $this->currentBranch = $branch;
        $this->currentRevision = $revision;
        $this->clean = true;

        return true;
    }

    public function remoteRevision(string $branch): string
    {
        return $this->remoteBranches[$branch] ?? throw new RuntimeException(sprintf('Unknown remote branch %s.', $branch));
    }

    public function currentBranch(): ?string
    {
        return $this->currentBranch;
    }

    public function currentRevision(): string
    {
        return $this->currentRevision;
    }

    public function localBranchRevision(string $branch): ?string
    {
        return $this->localBranches[$branch] ?? null;
    }

    public function remoteBranchRevision(string $branch): ?string
    {
        return $this->remoteBranches[$branch] ?? null;
    }

    public function checkoutRef(string $ref): void
    {
        $this->currentBranch = $ref;
        $this->currentRevision = $this->localBranches[$ref] ?? $this->currentRevision;
        $this->clean = true;
        $this->actions[] = sprintf('checkout-ref:%s', $ref);
    }

    public function checkoutDetached(string $revision): void
    {
        $this->currentBranch = null;
        $this->currentRevision = $revision;
        $this->clean = true;
        $this->actions[] = sprintf('detach:%s', $revision);
    }

    public function hardReset(string $revision): void
    {
        if ($this->currentBranch !== null) {
            $this->localBranches[$this->currentBranch] = $revision;
        }

        $this->currentRevision = $revision;
        $this->clean = true;
        $this->actions[] = sprintf('reset:%s', $revision);
    }

    public function cleanUntracked(): void
    {
        $this->clean = true;
        $this->actions[] = 'clean-untracked';
    }

    public function forceBranchToRevision(string $branch, string $revision): void
    {
        $this->localBranches[$branch] = $revision;
        $this->actions[] = sprintf('force-branch:%s:%s', $branch, $revision);
    }

    public function deleteLocalBranch(string $branch): void
    {
        unset($this->localBranches[$branch]);
        $this->actions[] = sprintf('delete-local:%s', $branch);
    }

    public function forcePushRevision(string $branch, string $revision): void
    {
        $this->remoteBranches[$branch] = $revision;
        $this->actions[] = sprintf('push:%s:%s', $branch, $revision);
    }

    public function deleteRemoteBranch(string $branch): void
    {
        unset($this->remoteBranches[$branch]);
        $this->actions[] = sprintf('delete-remote:%s', $branch);
    }

    public function assertCleanWorktree(): void
    {
        if (! $this->clean) {
            throw new RuntimeException('Git worktree must be clean before running automation sync commands.');
        }
    }
}

final class FakePullRequestReader implements GitHubPullRequestReader
{
    /** @param list<array<string, mixed>> $openPullRequests */
    public function __construct(
        private readonly array $openPullRequests,
        /** @var array<int, array<string, mixed>> */
        private readonly array $pullRequestsByNumber = [],
        private readonly ?RuntimeException $listFailure = null,
        /** @var array<int, RuntimeException> */
        private readonly array $pullRequestFailures = [],
    ) {
    }

    public function listOpenPullRequests(): array
    {
        if ($this->listFailure !== null) {
            throw $this->listFailure;
        }

        return $this->openPullRequests;
    }

    public function getPullRequest(int $number): array
    {
        if (isset($this->pullRequestFailures[$number])) {
            throw $this->pullRequestFailures[$number];
        }

        return $this->pullRequestsByNumber[$number] ?? throw new RuntimeException(sprintf('Missing pull request #%d.', $number));
    }
}

final class FakeGitHubAutomationClient implements GitHubAutomationClient
{
    /** @var list<array<string, mixed>> */
    public array $openPullRequests = [];
    /** @var array<int, array<string, mixed>> */
    public array $pullRequestsByNumber = [];
    /** @var list<array<string, mixed>> */
    public array $openIssues = [];
    /** @var list<array<string, mixed>> */
    public array $createdIssues = [];
    /** @var list<array<string, mixed>> */
    public array $updatedIssues = [];
    /** @var list<array<string, mixed>> */
    public array $closedIssues = [];
    /** @var list<array<string, mixed>> */
    public array $createdPullRequests = [];
    /** @var list<array<string, mixed>> */
    public array $updatedPullRequests = [];
    /** @var list<array<string, mixed>> */
    public array $closedPullRequests = [];
    /** @var list<array<string, mixed>> */
    public array $labelUpdates = [];
    public string $defaultBranch = 'main';

    public function getDefaultBranch(): string
    {
        return $this->defaultBranch;
    }

    public function ensureLabels(array $definitions): void
    {
    }

    public function listOpenPullRequests(): array
    {
        return $this->openPullRequests;
    }

    public function getPullRequest(int $number): array
    {
        return $this->pullRequestsByNumber[$number] ?? throw new RuntimeException(sprintf('Missing pull request #%d.', $number));
    }

    public function listOpenIssues(?string $label = null): array
    {
        return $this->openIssues;
    }

    public function createIssue(string $title, string $body, array $labels = []): array
    {
        $issue = ['number' => count($this->createdIssues) + 1, 'title' => $title, 'body' => $body, 'labels' => $labels];
        $this->createdIssues[] = $issue;
        return $issue;
    }

    public function updateIssue(int $number, string $title, string $body): array
    {
        $issue = ['number' => $number, 'title' => $title, 'body' => $body];
        $this->updatedIssues[] = $issue;
        return $issue;
    }

    public function closeIssue(int $number, ?string $comment = null): void
    {
        $this->closedIssues[] = ['number' => $number, 'comment' => $comment];
    }

    public function createPullRequest(string $title, string $head, string $base, string $body, bool $draft): array
    {
        $pullRequest = ['number' => count($this->createdPullRequests) + 1, 'title' => $title, 'head' => $head, 'base' => $base, 'body' => $body, 'draft' => $draft];
        $this->createdPullRequests[] = $pullRequest;
        return $pullRequest;
    }

    public function updatePullRequest(int $number, string $title, string $body): array
    {
        $pullRequest = ['number' => $number, 'title' => $title, 'body' => $body];
        $this->updatedPullRequests[] = $pullRequest;
        return $pullRequest;
    }

    public function closePullRequest(int $number, ?string $comment = null): void
    {
        $this->closedPullRequests[] = ['number' => $number, 'comment' => $comment];
    }

    public function setLabels(int $number, array $labels): void
    {
        $this->labelUpdates[] = ['number' => $number, 'labels' => $labels];
    }

    public function convertToDraft(string $nodeId): void
    {
    }

    public function markReadyForReview(string $nodeId): void
    {
    }
}

$fixtureDir = __DIR__ . '/fixtures';
$repoRoot = dirname(__DIR__, 3);
$classifier = new ReleaseClassifier();
$httpClient = new HttpClient();
$wpClient = new WordPressOrgClient($httpClient);
$gitHubReleaseClient = new GitHubReleaseClient($httpClient);
$coreClient = new WordPressCoreClient($httpClient);
$supportClient = new SupportForumClient($httpClient, 30);
$renderer = new PrBodyRenderer();
$premiumCredentialsStore = new PremiumCredentialsStore('{}');
$makeManagedSourceRegistry = static function (
    WordPressOrgSource $wordPressOrgSource,
    GitHubReleaseSource $gitHubReleaseSource,
    ArchiveDownloader $archiveDownloader,
    ?HttpClient $httpClientOverride = null
): ManagedSourceRegistry {
    $http = $httpClientOverride ?? new HttpClient();

    $wpOrgManagedSource = new class($wordPressOrgSource, $archiveDownloader) implements ManagedDependencySource
    {
        public function __construct(
            private readonly WordPressOrgSource $source,
            private readonly ArchiveDownloader $downloader,
        ) {
        }

        public function key(): string
        {
            return 'wordpress.org';
        }

        public function fetchCatalog(array $dependency): array
        {
            $info = $this->source->fetchComponentInfo((string) $dependency['kind'], (string) $dependency['slug']);

            return [
                'source' => 'wordpress.org',
                'info' => $info,
                'latest_version' => $this->source->latestVersion((string) $dependency['kind'], $info),
                'latest_release_at' => gmdate(DATE_ATOM),
            ];
        }

        public function releaseDataForVersion(array $dependency, array $catalog, string $targetVersion, string $fallbackReleaseAt): array
        {
            $info = (array) ($catalog['info'] ?? []);

            return [
                'source' => 'wordpress.org',
                'version' => $targetVersion,
                'download_url' => $this->source->downloadUrlForVersion((string) $dependency['kind'], $info, $targetVersion),
                'archive_subdir' => trim((string) $dependency['archive_subdir'], '/'),
                'release_at' => $fallbackReleaseAt,
                'notes_markup' => '<p>Release notes unavailable.</p>',
                'notes_text' => 'Release notes unavailable.',
                'source_reference' => $this->source->downloadUrlForVersion((string) $dependency['kind'], $info, $targetVersion),
                'source_details' => [],
            ];
        }

        public function downloadReleaseToFile(array $dependency, array $releaseData, string $destination): void
        {
            $this->downloader->downloadToFile((string) $releaseData['download_url'], $destination);
        }

        public function supportsForumSync(array $dependency): bool
        {
            return (string) $dependency['kind'] === 'plugin';
        }
    };

    $gitHubManagedSource = new class($gitHubReleaseSource) implements ManagedDependencySource
    {
        public function __construct(
            private readonly GitHubReleaseSource $source,
        ) {
        }

        public function key(): string
        {
            return 'github-release';
        }

        public function fetchCatalog(array $dependency): array
        {
            $releases = $this->source->fetchStableReleases($dependency);
            $releasesByVersion = [];

            foreach ($releases as $release) {
                $version = $this->source->latestVersion($release, $dependency);
                $releasesByVersion[$version] = $release;
            }

            return [
                'source' => 'github-release',
                'latest_version' => $this->source->latestVersion($releases[0], $dependency),
                'latest_release_at' => gmdate(DATE_ATOM),
                'releases_by_version' => $releasesByVersion,
            ];
        }

        public function releaseDataForVersion(array $dependency, array $catalog, string $targetVersion, string $fallbackReleaseAt): array
        {
            $release = $catalog['releases_by_version'][$targetVersion] ?? null;

            if (! is_array($release)) {
                throw new RuntimeException('Missing fake GitHub release.');
            }

            return [
                'source' => 'github-release',
                'version' => $targetVersion,
                'release' => $release,
                'archive_subdir' => trim((string) $dependency['archive_subdir'], '/'),
                'release_at' => $fallbackReleaseAt,
                'notes_markup' => '_Release notes unavailable._',
                'notes_text' => 'Release notes unavailable.',
                'source_reference' => 'fake-github-release',
                'source_details' => [],
            ];
        }

        public function downloadReleaseToFile(array $dependency, array $releaseData, string $destination): void
        {
            $this->source->downloadReleaseToFile((array) $releaseData['release'], $dependency, $destination);
        }

        public function supportsForumSync(array $dependency): bool
        {
            return false;
        }
    };

    return new ManagedSourceRegistry(
        $wpOrgManagedSource,
        $gitHubManagedSource,
        new ExamplePremiumManagedSource($http, new PremiumCredentialsStore('{}'))
    );
};

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$runtimeDefaults = [
    'stage_dir' => '.wp-core-base/build/runtime',
    'manifest_mode' => 'strict',
    'validation_mode' => 'source-clean',
    'ownership_roots' => ['cms/plugins', 'cms/themes', 'cms/mu-plugins'],
    'staged_kinds' => ['plugin', 'theme', 'mu-plugin-package', 'mu-plugin-file', 'runtime-file', 'runtime-directory'],
    'validated_kinds' => ['plugin', 'theme', 'mu-plugin-package', 'mu-plugin-file', 'runtime-file', 'runtime-directory'],
    'forbidden_paths' => ['.git', '.github', '.gitlab', '.circleci', '.wordpress-org', 'node_modules', 'docs', 'doc', 'tests', 'test', '__tests__', 'examples', 'example', 'demo', 'screenshots'],
    'forbidden_files' => ['README*', 'CHANGELOG*', '.gitignore', '.gitattributes', 'phpunit.xml*', 'composer.json', 'composer.lock', 'package.json', 'package-lock.json', 'pnpm-lock.yaml', 'yarn.lock'],
    'allow_runtime_paths' => [],
    'strip_paths' => [],
    'strip_files' => [],
    'managed_sanitize_paths' => ['cms/plugins/docs', 'cms/plugins/tests', 'cms/themes/docs', 'cms/themes/tests', 'cms/mu-plugins/docs', 'cms/mu-plugins/tests'],
    'managed_sanitize_files' => ['README*', 'CHANGELOG*', 'composer.json', 'composer.lock', 'package.json', 'package-lock.json', 'pnpm-lock.yaml', 'yarn.lock'],
];
$checkoutActionSha = 'actions/checkout@11bd71901bbe5b1630ceea73d27597364c9af683';
$setupPhpActionSha = 'shivammathur/setup-php@accd6127cb78bee3e8082180cb391013d204ef9f';
$legacyRuntimeDefaults = $runtimeDefaults;
unset(
    $legacyRuntimeDefaults['manifest_mode'],
    $legacyRuntimeDefaults['validation_mode'],
    $legacyRuntimeDefaults['ownership_roots'],
    $legacyRuntimeDefaults['staged_kinds'],
    $legacyRuntimeDefaults['validated_kinds'],
    $legacyRuntimeDefaults['strip_paths'],
    $legacyRuntimeDefaults['strip_files'],
    $legacyRuntimeDefaults['managed_sanitize_paths'],
    $legacyRuntimeDefaults['managed_sanitize_files'],
);

$writeManifest = static function (string $root, array $dependencies = []) use ($runtimeDefaults): void {
    if (! is_dir($root . '/.wp-core-base')) {
        mkdir($root . '/.wp-core-base', 0777, true);
    }

    file_put_contents(
        $root . '/.wp-core-base/manifest.php',
        "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export([
            'profile' => 'content-only',
            'paths' => [
                'content_root' => 'cms',
                'plugins_root' => 'cms/plugins',
                'themes_root' => 'cms/themes',
                'mu_plugins_root' => 'cms/mu-plugins',
            ],
            'core' => [
                'mode' => 'external',
                'enabled' => false,
            ],
            'runtime' => $runtimeDefaults,
            'github' => [
                'api_base' => 'https://api.github.com',
            ],
            'automation' => [
                'base_branch' => null,
                'dry_run' => false,
                'managed_kinds' => ['plugin', 'theme'],
            ],
            'dependencies' => $dependencies,
        ], true) . ";\n"
    );
};

$createPluginArchive = static function (string $archivePath, string $outerDirectory, string $slug, string $version, bool $includeReadme = true): void {
    $zip = new ZipArchive();
    $opened = $zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    if ($opened !== true) {
        throw new RuntimeException(sprintf('Failed to create archive fixture: %s', $archivePath));
    }

    $prefix = trim($outerDirectory, '/');
    $base = $prefix === '' ? $slug : $prefix . '/' . $slug;
    $zip->addEmptyDir($prefix === '' ? $slug : $prefix);
    $zip->addEmptyDir($base);
    $zip->addFromString(
        $base . '/' . $slug . '.php',
        "<?php\n/*\nPlugin Name: " . ucwords(str_replace('-', ' ', $slug)) . "\nVersion: " . $version . "\n*/\n"
    );

    if ($includeReadme) {
        $zip->addFromString($base . '/README.txt', "Readme for {$slug}\n");
    }

    $zip->close();
};

$normalizeWorkflowExample = static function (string $contents): string {
    return ltrim((string) preg_replace('/^(?:#.*\R)+\R*/', '', $contents));
};

$assert($classifier->classifyScope('5.3.6', '5.3.7') === 'patch', 'Expected patch classification.');
$assert($classifier->classifyScope('5.3.7', '5.4.0') === 'minor', 'Expected minor classification.');
$assert($classifier->classifyScope('5.4.0', '6.0.0') === 'major', 'Expected major classification.');

$labels = $classifier->deriveLabels('source:wordpress.org', 'patch', 'Security fix for comment validation.', []);
$assert(in_array('type:security-bugfix', $labels, true), 'Patch releases must be labeled as security-bugfix.');

$longLabel = 'plugin:this-is-an-extremely-long-plugin-slug-that-would-exceed-github-label-limits-by-a-wide-margin';
$normalizedLongLabel = LabelHelper::normalize($longLabel);
$assert(strlen($normalizedLongLabel) <= LabelHelper::MAX_LENGTH, 'Expected normalized labels to respect the GitHub label-length limit.');
$assert(str_starts_with($normalizedLongLabel, 'plugin:'), 'Expected normalized labels to preserve short semantic prefixes when possible.');
$assert(LabelHelper::normalize($normalizedLongLabel) === $normalizedLongLabel, 'Expected label normalization to be idempotent.');

$pluginInfo = json_decode((string) file_get_contents($fixtureDir . '/akismet-plugin-info.json'), true, 512, JSON_THROW_ON_ERROR);
$changelog = $wpClient->extractReleaseNotes('plugin', $pluginInfo, '5.6');
$assert(str_contains($changelog, 'Release Date'), 'Expected release notes to include the release date.');

$feedItems = $supportClient->parseFeed((string) file_get_contents($fixtureDir . '/akismet-support-feed.xml'));
$assert(count($feedItems) > 1, 'Expected support feed fixture to contain topics.');
$assert($feedItems[0]['title'] === 'Akismet Flagging Gravity Forms Submissions as Spam', 'Expected feed parser to strip markup from titles.');

$listingTopics = $supportClient->parseSupportListing((string) file_get_contents($fixtureDir . '/akismet-support.html'));
$assert(count($listingTopics) > 10, 'Expected support listing parser to find topics.');

$openedAt = $supportClient->extractTopicPublishedAt((string) file_get_contents($fixtureDir . '/akismet-topic.html'));
$assert($openedAt->format('Y-m-d\TH:i:sP') === '2026-03-12T11:00:14+00:00', 'Expected topic page parser to extract article:published_time.');

$body = $renderer->renderDependencyUpdate(
    dependencyName: 'Akismet Anti-spam',
    dependencySlug: 'akismet',
    dependencyKind: 'plugin',
    dependencyPath: 'wp-content/plugins/akismet',
    currentVersion: '5.5',
    targetVersion: '5.6',
    releaseScope: 'minor',
    releaseAt: '2025-11-12T16:31:00+00:00',
    labels: ['automation:dependency-update', 'kind:plugin', 'release:minor', 'type:feature'],
    sourceDetails: [
        ['label' => 'WordPress.org page', 'value' => '[Open](https://wordpress.org/plugins/akismet/)'],
        ['label' => 'WordPress.org support forum', 'value' => '[Open](https://wordpress.org/support/plugin/akismet/)'],
    ],
    releaseNotesHeading: 'Release Notes',
    releaseNotesBody: $changelog,
    supportTopics: [$feedItems[0]],
    metadata: [
        'slug' => 'akismet',
        'kind' => 'plugin',
        'source' => 'wordpress.org',
        'target_version' => '5.6',
        'release_at' => '2025-11-12T16:31:00+00:00',
        'scope' => 'minor',
        'branch' => 'codex/wporg-akismet-5-6',
        'blocked_by' => [],
    ],
);
$metadata = PrBodyRenderer::extractMetadata($body);
$assert(is_array($metadata) && $metadata['slug'] === 'akismet', 'Expected PR body metadata round-trip to work.');
$supportTopicsFromBody = PrBodyRenderer::extractSupportTopics($body);
$assert(count($supportTopicsFromBody) === 1, 'Expected PR body support topics to round-trip.');

$gitHubRelease = [
    'tag_name' => 'v2.3.4',
    'published_at' => '2026-03-18T12:45:00Z',
    'html_url' => 'https://github.com/example/example-plugin/releases/tag/v2.3.4',
    'zipball_url' => 'https://api.github.com/repos/example/example-plugin/zipball/v2.3.4',
    'body' => "## Changes\n\n- Fix fatal error on PHP 8.4\n- Add new shortcode option",
    'assets' => [
        [
            'name' => 'example-plugin.zip',
            'url' => 'https://api.github.com/repos/example/example-plugin/releases/assets/1',
        ],
    ],
];
$dependencyConfig = [
    'slug' => 'example-plugin',
    'source_config' => [
        'github_repository' => 'example/example-plugin',
        'github_release_asset_pattern' => '*.zip',
        'github_token_env' => null,
    ],
    'archive_subdir' => '',
];
$assert($gitHubReleaseClient->latestVersion($gitHubRelease, $dependencyConfig) === '2.3.4', 'Expected GitHub release tags to normalize into semver-like versions.');
$assert($gitHubReleaseClient->repository($dependencyConfig) === 'example/example-plugin', 'Expected GitHub repository config to load.');
$gitHubLabels = $classifier->deriveLabels('source:github-release', 'minor', $gitHubReleaseClient->markdownToText((string) $gitHubRelease['body']), []);
$assert(in_array('type:security-bugfix', $gitHubLabels, true), 'Expected GitHub release notes with fix language to set the bugfix label.');
$assert(in_array('type:feature', $gitHubLabels, true), 'Expected GitHub release notes with add language to set the feature label.');
$httpStatusException = new HttpStatusRuntimeException(404, 'Example 404.');
$assert($httpStatusException->status() === 404, 'Expected HTTP status exceptions to retain the structured status code.');

$frameworkConfig = FrameworkConfig::load($repoRoot);
$currentFrameworkVersion = $frameworkConfig->version;
$assert(preg_match('/^\d+\.\d+\.\d+$/', $currentFrameworkVersion) === 1, 'Expected framework metadata to load a valid current framework version.');
$assert($frameworkConfig->distributionPath() === '.', 'Expected upstream framework metadata to point at the repository root.');
$assert($frameworkConfig->checksumSignatureAssetName() === 'wp-core-base-vendor-snapshot.zip.sha256.sig', 'Expected framework metadata to derive the checksum-signature asset name.');
$assert(str_ends_with(ReleaseSignatureKeyStore::defaultPublicKeyPath($frameworkConfig), 'tools/wporg-updater/keys/framework-release-public.pem'), 'Expected the default release public key path to resolve inside the framework distribution.');
$agentsDoc = (string) file_get_contents($repoRoot . '/AGENTS.md');
$assert($agentsDoc !== '', 'Expected upstream AGENTS.md to exist.');
$assert(str_contains($agentsDoc, 'source_config.checksum_asset_pattern'), 'Expected AGENTS.md to document checksum sidecar settings for GitHub release dependencies.');
$assert(str_contains($agentsDoc, 'Do not invent checksum asset patterns'), 'Expected AGENTS.md to warn agents against guessing checksum asset patterns.');
$managingDependenciesDoc = (string) file_get_contents($repoRoot . '/docs/managing-dependencies.md');
$assert(str_contains($managingDependenciesDoc, 'Agent-ready GitHub release hardening workflow'), 'Expected managing-dependencies.md to include an explicit agent workflow for GitHub release hardening.');
$assert(str_contains($managingDependenciesDoc, "verification_mode' => 'checksum-sidecar-required'"), 'Expected managing-dependencies.md to show the exact hardened manifest shape for GitHub release verification.');
$downstreamUsageDoc = (string) file_get_contents($repoRoot . '/docs/downstream-usage.md');
$assert(str_contains($downstreamUsageDoc, 'download-time trust controls'), 'Expected downstream-usage.md to explain GitHub release trust controls.');
$releaseNotesMarkdown = (string) file_get_contents($repoRoot . '/docs/releases/' . $currentFrameworkVersion . '.md');
$assert($releaseNotesMarkdown !== '', 'Expected framework release notes to exist.');
$assert(FrameworkReleaseNotes::missingRequiredSections($releaseNotesMarkdown) === [], 'Expected framework release notes to include all required sections.');
$assert((new FrameworkReleaseVerifier($repoRoot))->verify() === 'v' . $currentFrameworkVersion, 'Expected framework release verification to succeed.');
$contractReport = (new FrameworkPublicContractVerifier($repoRoot))->verify($frameworkConfig, $releaseNotesMarkdown);
$assert($contractReport['framework_version'] === $currentFrameworkVersion, 'Expected framework public-contract verification to report the current framework version.');
$upstreamUpdatesWorkflow = (string) file_get_contents($repoRoot . '/.github/workflows/wporg-updates.yml');
$upstreamReconcileWorkflow = (string) file_get_contents($repoRoot . '/.github/workflows/wporg-updates-reconcile.yml');
$upstreamValidateWorkflow = (string) file_get_contents($repoRoot . '/.github/workflows/wporg-validate-runtime.yml');
$upstreamFinalizeWorkflow = (string) file_get_contents($repoRoot . '/.github/workflows/finalize-wp-core-base-release.yml');
$upstreamRecoveryReleaseWorkflow = (string) file_get_contents($repoRoot . '/.github/workflows/release-wp-core-base.yml');
$assert(str_contains($upstreamUpdatesWorkflow, $checkoutActionSha), 'Expected upstream updates workflow to pin actions/checkout by full commit SHA.');
$assert(str_contains($upstreamUpdatesWorkflow, $setupPhpActionSha), 'Expected upstream updates workflow to pin setup-php by full commit SHA.');
$assert(! str_contains($upstreamUpdatesWorkflow, 'pull_request_target:'), 'Expected upstream updates workflow to keep scheduled/manual execution separate from PR reconciliation.');
$assert(str_contains($upstreamReconcileWorkflow, $checkoutActionSha), 'Expected upstream reconciliation workflow to pin actions/checkout by full commit SHA.');
$assert(str_contains($upstreamReconcileWorkflow, $setupPhpActionSha), 'Expected upstream reconciliation workflow to pin setup-php by full commit SHA.');
$assert(str_contains($upstreamReconcileWorkflow, "github.event.pull_request.merged == true"), 'Expected upstream reconciliation workflow to narrow closed-PR reconciliation to merged PRs.');
$assert(str_contains($upstreamReconcileWorkflow, "automation:framework-update"), 'Expected upstream reconciliation workflow to limit closed-PR reconciliation to framework automation PRs.');
$assert(str_contains($upstreamFinalizeWorkflow, 'wp-core-base-vendor-snapshot.zip.sha256'), 'Expected finalize release workflow to publish a SHA-256 checksum asset.');
$assert(str_contains($upstreamFinalizeWorkflow, 'wp-core-base-vendor-snapshot.zip.sha256.sig'), 'Expected finalize release workflow to publish a detached checksum signature asset.');
$assert(str_contains($upstreamFinalizeWorkflow, 'build-release-artifact'), 'Expected finalize release workflow to build the vendored snapshot through the framework artifact builder.');
$assert(str_contains($upstreamFinalizeWorkflow, 'release-sign'), 'Expected finalize release workflow to create a detached release signature.');
$assert(str_contains($upstreamFinalizeWorkflow, "git push --delete origin"), 'Expected finalize release workflow to roll back the pushed tag when release publishing fails.');
$assert(str_contains($upstreamRecoveryReleaseWorkflow, 'wp-core-base-vendor-snapshot.zip.sha256'), 'Expected manual release workflow to publish a SHA-256 checksum asset.');
$assert(str_contains($upstreamRecoveryReleaseWorkflow, 'wp-core-base-vendor-snapshot.zip.sha256.sig'), 'Expected manual release workflow to publish a detached checksum signature asset.');
$assert(str_contains($upstreamRecoveryReleaseWorkflow, 'build-release-artifact'), 'Expected manual release workflow to build the vendored snapshot through the framework artifact builder.');
$assert(str_contains($upstreamRecoveryReleaseWorkflow, 'release-sign'), 'Expected manual release workflow to create a detached release signature.');
$assert(str_contains($upstreamRecoveryReleaseWorkflow, 'GitHub Release ${{ steps.version.outputs.value }} already exists; nothing to publish.'), 'Expected manual recovery release workflow to exit cleanly when the GitHub Release already exists.');
$assert(str_contains($upstreamValidateWorkflow, '--artifact=dist/wp-core-base-vendor-snapshot.zip'), 'Expected CI release verification to validate the built release artifact, not only release metadata.');
$assert(str_contains($upstreamValidateWorkflow, '--checksum-file=dist/wp-core-base-vendor-snapshot.zip.sha256'), 'Expected CI release verification to validate the built checksum sidecar.');
$assert(str_contains($upstreamValidateWorkflow, '--signature-file=dist/wp-core-base-vendor-snapshot.zip.sha256.sig'), 'Expected CI release verification to validate the detached checksum signature.');
$assert(str_contains($upstreamValidateWorkflow, 'phpstan analyse --configuration=phpstan.neon.dist'), 'Expected CI to run PHPStan as a framework integrity check.');
$assert(str_contains($upstreamValidateWorkflow, '/tmp/actionlint -color'), 'Expected CI to lint GitHub workflows with actionlint.');
$assert(str_contains($upstreamValidateWorkflow, 'verify_downstream_fixture.php --profile=${{ matrix.profile }}'), 'Expected CI to exercise both downstream fixture profiles.');

$signatureFixtureRoot = sys_get_temp_dir() . '/wporg-release-signature-' . bin2hex(random_bytes(4));
mkdir($signatureFixtureRoot, 0777, true);
$checksumFixturePath = $signatureFixtureRoot . '/artifact.zip.sha256';
$signatureFixturePath = $signatureFixtureRoot . '/artifact.zip.sha256.sig';
file_put_contents($checksumFixturePath, str_repeat('a', 64) . "  artifact.zip\n");
$privateFixtureKey = (string) file_get_contents($repoRoot . '/tools/wporg-updater/tests/fixtures/release-signing/private.pem');
$publicFixtureKeyPath = $repoRoot . '/tools/wporg-updater/tests/fixtures/release-signing/public.pem';
$signatureDocument = FrameworkReleaseSignature::signChecksumFile($checksumFixturePath, $signatureFixturePath, $privateFixtureKey);
$assert($signatureDocument['signed_file'] === 'artifact.zip.sha256', 'Expected release signing to bind the checksum sidecar filename.');
$verifiedSignatureDocument = FrameworkReleaseSignature::verifyChecksumFile($checksumFixturePath, $signatureFixturePath, $publicFixtureKeyPath);
$assert($verifiedSignatureDocument['key_id'] === $signatureDocument['key_id'], 'Expected release signature verification to report the same key identifier.');
$signatureTamperRejected = false;
file_put_contents($checksumFixturePath, str_repeat('b', 64) . "  artifact.zip\n");

try {
    FrameworkReleaseSignature::verifyChecksumFile($checksumFixturePath, $signatureFixturePath, $publicFixtureKeyPath);
} catch (RuntimeException $exception) {
    $signatureTamperRejected = str_contains($exception->getMessage(), 'digest mismatch');
}

$assert($signatureTamperRejected, 'Expected release signature verification to reject tampered checksum sidecars.');

$releasePrepRoot = sys_get_temp_dir() . '/wporg-framework-release-' . bin2hex(random_bytes(4));
mkdir($releasePrepRoot . '/.wp-core-base', 0777, true);
mkdir($releasePrepRoot . '/docs/releases', 0777, true);
(new FrameworkWriter())->write($frameworkConfig->withInstalledRelease(
    version: $frameworkConfig->version,
    wordPressCoreVersion: $frameworkConfig->baseline['wordpress_core'],
    managedComponents: $frameworkConfig->baseline['managed_components'],
    managedFiles: $frameworkConfig->managedFiles(),
    repoRoot: $releasePrepRoot,
    path: $releasePrepRoot . '/.wp-core-base/framework.php',
));
$preparedRelease = (new FrameworkReleasePreparer($releasePrepRoot))->prepare('patch');
$expectedPreparedVersion = 'v' . preg_replace_callback('/^(\d+)\.(\d+)\.(\d+)$/', static fn (array $m): string => sprintf('%d.%d.%d', (int) $m[1], (int) $m[2], (int) $m[3] + 1), $currentFrameworkVersion);
$assert($preparedRelease['version'] === $expectedPreparedVersion, 'Expected prepare-framework-release to derive the next patch version.');
$assert($preparedRelease['release_notes_created'] === true, 'Expected prepare-framework-release to scaffold release notes.');
$preparedFramework = FrameworkConfig::load($releasePrepRoot);
$preparedPlainVersion = ltrim($expectedPreparedVersion, 'v');
$assert($preparedFramework->version === $preparedPlainVersion, 'Expected prepare-framework-release to bump framework.php.');
$preparedNotes = (string) file_get_contents($releasePrepRoot . '/docs/releases/' . $preparedPlainVersion . '.md');
$assert($preparedNotes !== '', 'Expected scaffolded release notes to be written.');
$assert(FrameworkReleaseNotes::missingRequiredSections($preparedNotes) === [], 'Expected scaffolded release notes to include required sections.');
$assert(str_contains($preparedNotes, sprintf('This is the `patch` framework release from `v%s` to `%s`', $currentFrameworkVersion, $expectedPreparedVersion)), 'Expected scaffolded release notes summary to be prefilled with the version transition.');
$assert(str_contains($preparedNotes, sprintf('Downstream repositories pinned to an older `wp-core-base` release can update to `%s`', $expectedPreparedVersion)), 'Expected scaffolded release notes to include downstream impact guidance.');
$assert(str_contains($preparedNotes, 'The published framework asset for this release is `wp-core-base-vendor-snapshot.zip`.'), 'Expected scaffolded release notes to include operational asset details.');
$assert(
    str_contains(
        $preparedNotes,
        sprintf(
            '- %s `%s`',
            $frameworkConfig->baseline['managed_components'][0]['name'],
            $frameworkConfig->baseline['managed_components'][0]['version']
        )
    ),
    'Expected scaffolded release notes to include the bundled baseline component list.'
);
$assert(! str_contains($preparedNotes, 'Describe the framework changes in this release.'), 'Expected scaffolded release notes to avoid placeholder prose.');
$refreshedRelease = (new FrameworkReleasePreparer($releasePrepRoot))->prepare('custom', $expectedPreparedVersion, true);
$assert($refreshedRelease['version'] === $expectedPreparedVersion, 'Expected prepare-framework-release to allow refreshing the current version when explicitly requested.');
$assert($refreshedRelease['release_notes_created'] === false, 'Expected refresh of existing release notes to avoid recreating the file.');

$config = Config::load($repoRoot);
$assert($config->profile === 'full-core', 'Expected repository manifest to load as full-core.');
$assert($config->coreManaged(), 'Expected repository manifest to manage WordPress core.');
$assert($config->manifestMode() === 'strict', 'Expected repository manifest to default to strict runtime ownership.');
$assert($config->validationMode() === 'source-clean', 'Expected repository manifest to default to source-clean validation.');
$assert(in_array('runtime-file', $config->stagedKinds(), true), 'Expected runtime-file to be stageable by default.');
$assert(in_array('runtime-directory', $config->stagedKinds(), true), 'Expected runtime-directory to be stageable by default.');
$assert(in_array('plugin', $config->managedKinds(), true), 'Expected plugins to remain managed by default.');
$assert(count($config->managedDependencies()) === 4, 'Expected four managed baseline dependencies.');

$longLabelManifestRoot = sys_get_temp_dir() . '/wporg-long-label-' . bin2hex(random_bytes(4));
mkdir($longLabelManifestRoot . '/.wp-core-base', 0777, true);
file_put_contents(
    $longLabelManifestRoot . '/.wp-core-base/manifest.php',
    "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export([
        'profile' => 'content-only',
        'paths' => [
            'content_root' => 'cms',
            'plugins_root' => 'cms/plugins',
            'themes_root' => 'cms/themes',
            'mu_plugins_root' => 'cms/mu-plugins',
        ],
        'core' => [
            'mode' => 'external',
            'enabled' => false,
        ],
        'runtime' => $runtimeDefaults,
        'github' => [
            'api_base' => 'https://api.github.com',
        ],
        'automation' => [
            'base_branch' => null,
            'dry_run' => false,
            'managed_kinds' => ['plugin', 'theme'],
        ],
        'dependencies' => [[
            'name' => 'Example Long Label Plugin',
            'slug' => 'example-long-label-plugin',
            'kind' => 'plugin',
            'management' => 'local',
            'source' => 'local',
            'path' => 'cms/plugins/example-long-label-plugin',
            'main_file' => 'example-long-label-plugin.php',
            'version' => null,
            'checksum' => null,
            'archive_subdir' => '',
            'extra_labels' => [$longLabel],
            'source_config' => [
                'github_repository' => null,
                'github_release_asset_pattern' => null,
                'github_token_env' => null,
            ],
            'policy' => [
                'class' => 'local-owned',
                'allow_runtime_paths' => [],
                'strip_paths' => [],
                'strip_files' => [],
            ],
        ]],
    ], true) . ";\n"
);
$longLabelConfig = Config::load($longLabelManifestRoot);
$normalizedManifestLabel = $longLabelConfig->dependencies()[0]['extra_labels'][0];
$assert(strlen($normalizedManifestLabel) <= LabelHelper::MAX_LENGTH, 'Expected manifest extra_labels to be normalized on load.');
$assert($normalizedManifestLabel === $normalizedLongLabel, 'Expected manifest label normalization to match the shared helper output.');

$scanner = new DependencyScanner();
$woocommerce = $config->dependencyByKey('plugin:wordpress.org:woocommerce');
$woocommerceState = $scanner->inspect($repoRoot, $woocommerce);
$assert($woocommerceState['version'] === $woocommerce['version'], 'Expected bundled WooCommerce version to match the manifest.');

$runtimeInspector = new RuntimeInspector($config->runtime);
$runtimeInspector->assertTreeIsClean($repoRoot . '/wp-content/plugins/woocommerce');
$assert(
    $runtimeInspector->computeTreeChecksum($repoRoot . '/wp-content/plugins/woocommerce') === $woocommerce['checksum'],
    'Expected managed dependency checksum to match the sanitized tree.'
);
$checksumSymlinkRoot = sys_get_temp_dir() . '/wporg-checksum-symlink-' . bin2hex(random_bytes(4));
mkdir($checksumSymlinkRoot, 0777, true);
file_put_contents($checksumSymlinkRoot . '/real.php', "<?php\n");
@symlink($checksumSymlinkRoot . '/real.php', $checksumSymlinkRoot . '/link.php');
$checksumSymlinkRejected = false;

try {
    $runtimeInspector->computeTreeChecksum($checksumSymlinkRoot);
} catch (RuntimeException $exception) {
    $checksumSymlinkRejected = str_contains($exception->getMessage(), 'Symlink detected in checksum tree');
}

$runtimeInspector->clearPath($checksumSymlinkRoot);
$assert($checksumSymlinkRejected, 'Expected checksum calculation to reject symlinked runtime trees directly.');

$nestedSanitizeRoot = sys_get_temp_dir() . '/wporg-nested-sanitize-' . bin2hex(random_bytes(4));
mkdir($nestedSanitizeRoot . '/packages/blueprint/src/docs', 0777, true);
file_put_contents($nestedSanitizeRoot . '/packages/blueprint/src/docs/notes.md', "# Notes\n");
file_put_contents($nestedSanitizeRoot . '/woocommerce.php', "<?php\n");
$assert(
    $runtimeInspector->matchingStrippedEntries($nestedSanitizeRoot, ['**/docs']) === [
        'packages/blueprint/src/docs',
        'packages/blueprint/src/docs/notes.md',
    ],
    'Expected wildcard sanitize paths to match nested documentation directories.'
);
$runtimeInspector->stripPath($nestedSanitizeRoot, ['**/docs']);
$assert(! is_dir($nestedSanitizeRoot . '/packages/blueprint/src/docs'), 'Expected wildcard sanitize paths to strip nested documentation directories.');
$runtimeInspector->assertPathIsClean($nestedSanitizeRoot, [], [], ['**/docs']);
$runtimeInspector->clearPath($nestedSanitizeRoot);

$stageDir = '.wp-core-base/build/test-runtime';
$stagedPaths = (new RuntimeStager($config, $runtimeInspector))->stage($stageDir);
$assert(in_array('wp-content/plugins/woocommerce', $stagedPaths, true), 'Expected runtime staging to include managed plugin paths.');
$runtimeInspector->clearDirectory($repoRoot . '/' . $stageDir);

$legacyRoot = sys_get_temp_dir() . '/wporg-legacy-' . bin2hex(random_bytes(4));
mkdir($legacyRoot . '/.github', 0777, true);
file_put_contents($legacyRoot . '/.github/wporg-updates.php', "<?php\nreturn [];\n");
$legacyFailed = false;

try {
    Config::load($legacyRoot);
} catch (RuntimeException $exception) {
    $legacyFailed = str_contains($exception->getMessage(), '.wp-core-base/manifest.php');
}

$assert($legacyFailed, 'Expected legacy config loading to fail with migration guidance.');

$strictDuplicateRejected = false;

try {
    Config::fromArray($repoRoot, [
        'profile' => 'content-only',
        'paths' => [
            'content_root' => 'cms',
            'plugins_root' => 'cms/plugins',
            'themes_root' => 'cms/themes',
            'mu_plugins_root' => 'cms/mu-plugins',
        ],
        'core' => [
            'mode' => 'external',
            'enabled' => false,
        ],
        'runtime' => array_merge($runtimeDefaults, ['manifest_mode' => 'strict']),
        'github' => [
            'api_base' => 'https://api.github.com',
        ],
        'automation' => [
            'base_branch' => null,
            'dry_run' => false,
            'managed_kinds' => ['plugin', 'theme'],
        ],
        'dependencies' => [
            [
                'name' => 'First Shared Plugin',
                'slug' => 'first-shared-plugin',
                'kind' => 'plugin',
                'management' => 'local',
                'source' => 'local',
                'path' => 'cms/plugins/shared-plugin',
                'main_file' => 'shared-plugin.php',
                'version' => null,
                'checksum' => null,
                'archive_subdir' => '',
                'extra_labels' => [],
                'source_config' => [],
                'policy' => ['class' => 'local-owned', 'allow_runtime_paths' => [], 'strip_paths' => [], 'strip_files' => [], 'sanitize_paths' => [], 'sanitize_files' => []],
            ],
            [
                'name' => 'Second Shared Plugin',
                'slug' => 'second-shared-plugin',
                'kind' => 'plugin',
                'management' => 'local',
                'source' => 'local',
                'path' => 'cms/plugins/shared-plugin',
                'main_file' => 'shared-plugin.php',
                'version' => null,
                'checksum' => null,
                'archive_subdir' => '',
                'extra_labels' => [],
                'source_config' => [],
                'policy' => ['class' => 'local-owned', 'allow_runtime_paths' => [], 'strip_paths' => [], 'strip_files' => [], 'sanitize_paths' => [], 'sanitize_files' => []],
            ],
        ],
    ]);
} catch (RuntimeException $exception) {
    $strictDuplicateRejected = str_contains($exception->getMessage(), 'Strict manifest mode');
}

$assert($strictDuplicateRejected, 'Expected strict manifest mode to reject duplicate dependency runtime paths.');

$strictOverlapRejected = false;

try {
    Config::fromArray($repoRoot, [
        'profile' => 'content-only',
        'paths' => [
            'content_root' => 'cms',
            'plugins_root' => 'cms/plugins',
            'themes_root' => 'cms/themes',
            'mu_plugins_root' => 'cms/mu-plugins',
        ],
        'core' => [
            'mode' => 'external',
            'enabled' => false,
        ],
        'runtime' => array_merge($runtimeDefaults, ['manifest_mode' => 'strict']),
        'github' => [
            'api_base' => 'https://api.github.com',
        ],
        'automation' => [
            'base_branch' => null,
            'dry_run' => false,
            'managed_kinds' => ['plugin', 'theme'],
        ],
        'dependencies' => [
            [
                'name' => 'Plugin Parent',
                'slug' => 'plugin-parent',
                'kind' => 'plugin',
                'management' => 'local',
                'source' => 'local',
                'path' => 'cms/plugins/parent',
                'main_file' => 'parent.php',
                'version' => null,
                'checksum' => null,
                'archive_subdir' => '',
                'extra_labels' => [],
                'source_config' => [],
                'policy' => ['class' => 'local-owned', 'allow_runtime_paths' => [], 'strip_paths' => [], 'strip_files' => [], 'sanitize_paths' => [], 'sanitize_files' => []],
            ],
            [
                'name' => 'Plugin Nested',
                'slug' => 'plugin-nested',
                'kind' => 'plugin',
                'management' => 'local',
                'source' => 'local',
                'path' => 'cms/plugins/parent/nested',
                'main_file' => 'nested.php',
                'version' => null,
                'checksum' => null,
                'archive_subdir' => '',
                'extra_labels' => [],
                'source_config' => [],
                'policy' => ['class' => 'local-owned', 'allow_runtime_paths' => [], 'strip_paths' => [], 'strip_files' => [], 'sanitize_paths' => [], 'sanitize_files' => []],
            ],
        ],
    ]);
} catch (RuntimeException $exception) {
    $strictOverlapRejected = str_contains($exception->getMessage(), 'overlapping dependency runtime paths');
}

$assert($strictOverlapRejected, 'Expected strict manifest mode to reject overlapping dependency runtime paths.');

$relaxedOverlapConfig = Config::fromArray($repoRoot, [
    'profile' => 'content-only',
    'paths' => [
        'content_root' => 'cms',
        'plugins_root' => 'cms/plugins',
        'themes_root' => 'cms/themes',
        'mu_plugins_root' => 'cms/mu-plugins',
    ],
    'core' => [
        'mode' => 'external',
        'enabled' => false,
    ],
    'runtime' => array_merge($runtimeDefaults, ['manifest_mode' => 'relaxed']),
    'github' => [
        'api_base' => 'https://api.github.com',
    ],
    'automation' => [
        'base_branch' => null,
        'dry_run' => false,
        'managed_kinds' => ['plugin', 'theme'],
    ],
    'dependencies' => [
        [
            'name' => 'Relaxed Parent',
            'slug' => 'relaxed-parent',
            'kind' => 'plugin',
            'management' => 'local',
            'source' => 'local',
            'path' => 'cms/plugins/shared-plugin',
            'main_file' => 'shared-plugin.php',
            'version' => null,
            'checksum' => null,
            'archive_subdir' => '',
            'extra_labels' => [],
            'source_config' => [],
            'policy' => ['class' => 'local-owned', 'allow_runtime_paths' => [], 'strip_paths' => [], 'strip_files' => [], 'sanitize_paths' => [], 'sanitize_files' => []],
        ],
        [
            'name' => 'Relaxed Nested',
            'slug' => 'relaxed-nested',
            'kind' => 'plugin',
            'management' => 'local',
            'source' => 'local',
            'path' => 'cms/plugins/shared-plugin/nested',
            'main_file' => 'nested.php',
            'version' => null,
            'checksum' => null,
            'archive_subdir' => '',
            'extra_labels' => [],
            'source_config' => [],
            'policy' => ['class' => 'local-owned', 'allow_runtime_paths' => [], 'strip_paths' => [], 'strip_files' => [], 'sanitize_paths' => [], 'sanitize_files' => []],
        ],
    ],
]);

$assert($relaxedOverlapConfig->manifestMode() === 'relaxed', 'Expected relaxed manifest mode to keep migration-era overlap tolerance.');

$contentRoot = sys_get_temp_dir() . '/wporg-content-only-' . bin2hex(random_bytes(4));
mkdir($contentRoot . '/cms/plugins/example-plugin', 0777, true);
mkdir($contentRoot . '/cms/plugins/untracked-plugin', 0777, true);
mkdir($contentRoot . '/cms/themes/example-theme', 0777, true);
mkdir($contentRoot . '/cms/mu-plugins/bootstrap', 0777, true);
mkdir($contentRoot . '/cms/shared', 0777, true);
mkdir($contentRoot . '/cms/shared-assets/icons', 0777, true);
file_put_contents($contentRoot . '/cms/plugins/example-plugin/example-plugin.php', <<<'PHP'
<?php
/*
Plugin Name: Example Plugin
Version: 1.2.3
*/
PHP);
file_put_contents($contentRoot . '/cms/plugins/untracked-plugin/untracked-plugin.php', <<<'PHP'
<?php
/*
Plugin Name: Untracked Plugin
Version: 9.9.9
*/
PHP);
file_put_contents($contentRoot . '/cms/themes/example-theme/style.css', <<<'CSS'
/*
Theme Name: Example Theme
Version: 4.5.6
*/
CSS);
file_put_contents($contentRoot . '/cms/mu-plugins/bootstrap/loader.php', <<<'PHP'
<?php
/*
Plugin Name: Bootstrap Loader
Version: 1.0.0
*/
PHP);
file_put_contents($contentRoot . '/cms/mu-plugins/project-loader.php', <<<'PHP'
<?php
/*
Plugin Name: Project Loader
Version: 2.0.0
*/
PHP);
file_put_contents($contentRoot . '/cms/mu-plugins/untracked-loader.php', <<<'PHP'
<?php
/*
Plugin Name: Untracked Loader
Version: 9.9.9
*/
PHP);
file_put_contents($contentRoot . '/cms/shared/object-cache.php', "<?php\n");
file_put_contents($contentRoot . '/cms/shared-assets/icons/icon.svg', "<svg></svg>\n");

$contentManifest = [
    'profile' => 'content-only',
    'paths' => [
        'content_root' => 'cms',
        'plugins_root' => 'cms/plugins',
        'themes_root' => 'cms/themes',
        'mu_plugins_root' => 'cms/mu-plugins',
    ],
    'core' => [
        'mode' => 'external',
        'enabled' => false,
    ],
    'runtime' => $legacyRuntimeDefaults,
    'github' => ['api_base' => 'https://api.github.com'],
    'automation' => ['base_branch' => null, 'dry_run' => false],
    'dependencies' => [
        [
            'name' => 'Example Plugin',
            'slug' => 'example-plugin',
            'kind' => 'plugin',
            'management' => 'local',
            'source' => 'local',
            'path' => 'cms/plugins/example-plugin',
            'main_file' => 'example-plugin.php',
            'version' => '1.2.3',
            'checksum' => null,
            'archive_subdir' => '',
            'extra_labels' => [],
            'source_config' => ['github_repository' => null, 'github_release_asset_pattern' => null, 'github_token_env' => null],
            'policy' => ['class' => 'local-owned', 'allow_runtime_paths' => []],
        ],
        [
            'name' => 'Example Theme',
            'slug' => 'example-theme',
            'kind' => 'theme',
            'management' => 'local',
            'source' => 'local',
            'path' => 'cms/themes/example-theme',
            'main_file' => 'style.css',
            'version' => '4.5.6',
            'checksum' => null,
            'archive_subdir' => '',
            'extra_labels' => [],
            'source_config' => ['github_repository' => null, 'github_release_asset_pattern' => null, 'github_token_env' => null],
            'policy' => ['class' => 'local-owned', 'allow_runtime_paths' => []],
        ],
        [
            'name' => 'Bootstrap Loader',
            'slug' => 'bootstrap',
            'kind' => 'mu-plugin-package',
            'management' => 'local',
            'source' => 'local',
            'path' => 'cms/mu-plugins/bootstrap',
            'main_file' => 'loader.php',
            'version' => '1.0.0',
            'checksum' => null,
            'archive_subdir' => '',
            'extra_labels' => [],
            'source_config' => ['github_repository' => null, 'github_release_asset_pattern' => null, 'github_token_env' => null],
            'policy' => ['class' => 'local-owned', 'allow_runtime_paths' => []],
        ],
        [
            'name' => 'Project Loader',
            'slug' => 'project-loader',
            'kind' => 'mu-plugin-file',
            'management' => 'local',
            'source' => 'local',
            'path' => 'cms/mu-plugins/project-loader.php',
            'version' => '2.0.0',
            'checksum' => null,
            'archive_subdir' => '',
            'extra_labels' => [],
            'source_config' => ['github_repository' => null, 'github_release_asset_pattern' => null, 'github_token_env' => null],
            'policy' => ['class' => 'local-owned', 'allow_runtime_paths' => []],
        ],
        [
            'name' => 'Object Cache',
            'slug' => 'object-cache',
            'kind' => 'runtime-file',
            'management' => 'local',
            'source' => 'local',
            'path' => 'cms/shared/object-cache.php',
            'version' => '1.0.0',
            'checksum' => null,
            'archive_subdir' => '',
            'extra_labels' => [],
            'source_config' => ['github_repository' => null, 'github_release_asset_pattern' => null, 'github_token_env' => null],
            'policy' => ['class' => 'local-owned', 'allow_runtime_paths' => []],
        ],
        [
            'name' => 'Shared Assets',
            'slug' => 'shared-assets',
            'kind' => 'runtime-directory',
            'management' => 'local',
            'source' => 'local',
            'path' => 'cms/shared-assets',
            'version' => null,
            'checksum' => null,
            'archive_subdir' => '',
            'extra_labels' => [],
            'source_config' => ['github_repository' => null, 'github_release_asset_pattern' => null, 'github_token_env' => null],
            'policy' => ['class' => 'local-owned', 'allow_runtime_paths' => [], 'strip_paths' => [], 'strip_files' => []],
        ],
    ],
];
mkdir($contentRoot . '/.wp-core-base', 0777, true);
file_put_contents(
    $contentRoot . '/.wp-core-base/manifest.php',
    "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($contentManifest, true) . ";\n"
);
$loadedContentConfig = Config::load($contentRoot);
$assert($loadedContentConfig->profile === 'content-only', 'Expected content-only manifest to load.');
$assert(! $loadedContentConfig->coreManaged(), 'Expected content-only manifest to keep core external.');
$assert($loadedContentConfig->managedKinds() === ['plugin', 'theme', 'mu-plugin-package'], 'Expected older manifests to receive default managed_kinds.');
$assert($loadedContentConfig->manifestMode() === 'strict', 'Expected older manifests to receive strict manifest mode by default.');
$assert($loadedContentConfig->validationMode() === 'source-clean', 'Expected older manifests to receive source-clean validation by default.');
$undeclaredStrict = (new RuntimeOwnershipInspector($loadedContentConfig))->undeclaredRuntimePaths();
$assert(count($undeclaredStrict) === 2, 'Expected strict runtime ownership scan to find undeclared plugin and MU paths.');
$assert(in_array('cms/plugins/untracked-plugin', array_column($undeclaredStrict, 'path'), true), 'Expected strict scan to find undeclared plugin path.');
$assert(in_array('cms/mu-plugins/untracked-loader.php', array_column($undeclaredStrict, 'path'), true), 'Expected strict scan to find undeclared MU plugin file.');
$assert(in_array('cms/mu-plugins/project-loader.php', array_column($undeclaredStrict, 'path'), true) === false, 'Expected declared MU file not to appear as undeclared.');
$contentStager = new RuntimeStager($loadedContentConfig, new RuntimeInspector($loadedContentConfig->runtime));
$contentPaths = $contentStager->stage('.wp-core-base/build/runtime');
$assert(in_array('cms/plugins/example-plugin', $contentPaths, true), 'Expected content-only runtime staging to include plugin path.');
$assert(in_array('cms/mu-plugins/project-loader.php', $contentPaths, true), 'Expected declared local MU plugin file to stage.');
$assert(in_array('cms/shared/object-cache.php', $contentPaths, true), 'Expected declared local runtime file to stage.');
$assert(in_array('cms/shared-assets', $contentPaths, true), 'Expected declared local runtime directory to stage.');
$assert(! in_array('cms/plugins/untracked-plugin', $contentPaths, true), 'Expected strict mode not to stage undeclared plugin paths.');

$relaxedRoot = sys_get_temp_dir() . '/wporg-content-relaxed-' . bin2hex(random_bytes(4));
mkdir($relaxedRoot . '/cms/plugins/example-plugin', 0777, true);
mkdir($relaxedRoot . '/cms/plugins/untracked-plugin', 0777, true);
mkdir($relaxedRoot . '/cms/themes/example-theme', 0777, true);
mkdir($relaxedRoot . '/cms/mu-plugins', 0777, true);
mkdir($relaxedRoot . '/cms/shared', 0777, true);
mkdir($relaxedRoot . '/cms/languages/de_DE', 0777, true);
file_put_contents($relaxedRoot . '/cms/plugins/example-plugin/example-plugin.php', "<?php\n/*\nPlugin Name: Example Plugin\nVersion: 1.2.3\n*/\n");
file_put_contents($relaxedRoot . '/cms/plugins/untracked-plugin/untracked-plugin.php', "<?php\n/*\nPlugin Name: Untracked Plugin\nVersion: 3.0.0\n*/\n");
file_put_contents($relaxedRoot . '/cms/themes/example-theme/style.css', "/*\nTheme Name: Example Theme\nVersion: 4.5.6\n*/\n");
file_put_contents($relaxedRoot . '/cms/mu-plugins/local-loader.php', "<?php\n/*\nPlugin Name: Local Loader\nVersion: 1.0.0\n*/\n");
file_put_contents($relaxedRoot . '/cms/shared/object-cache.php', "<?php\n");
file_put_contents($relaxedRoot . '/cms/languages/de_DE/messages.mo', "binary\n");
mkdir($relaxedRoot . '/.wp-core-base', 0777, true);
$relaxedManifest = [
    'profile' => 'content-only',
    'paths' => [
        'content_root' => 'cms',
        'plugins_root' => 'cms/plugins',
        'themes_root' => 'cms/themes',
        'mu_plugins_root' => 'cms/mu-plugins',
    ],
    'core' => [
        'mode' => 'external',
        'enabled' => false,
    ],
    'runtime' => array_merge($runtimeDefaults, [
        'manifest_mode' => 'relaxed',
        'ownership_roots' => ['cms/plugins', 'cms/themes', 'cms/mu-plugins', 'cms/languages'],
        'staged_kinds' => ['plugin', 'mu-plugin-file', 'runtime-file', 'runtime-directory'],
        'validated_kinds' => ['plugin', 'runtime-file', 'runtime-directory'],
    ]),
    'github' => ['api_base' => 'https://api.github.com'],
    'automation' => ['base_branch' => null, 'dry_run' => false, 'managed_kinds' => ['plugin']],
    'dependencies' => [
        [
            'name' => 'Example Plugin',
            'slug' => 'example-plugin',
            'kind' => 'plugin',
            'management' => 'managed',
            'source' => 'github-release',
            'path' => 'cms/plugins/example-plugin',
            'main_file' => 'example-plugin.php',
            'version' => '1.2.3',
            'checksum' => (new RuntimeInspector(array_merge($runtimeDefaults, [
                'manifest_mode' => 'relaxed',
                'ownership_roots' => ['cms/plugins', 'cms/themes', 'cms/mu-plugins', 'cms/languages'],
                'staged_kinds' => ['plugin', 'mu-plugin-file', 'runtime-file', 'runtime-directory'],
                'validated_kinds' => ['plugin', 'runtime-file', 'runtime-directory'],
            ])))->computeChecksum($relaxedRoot . '/cms/plugins/example-plugin'),
            'archive_subdir' => '',
            'extra_labels' => [],
            'source_config' => ['github_repository' => 'owner/example-plugin', 'github_release_asset_pattern' => '*.zip', 'github_token_env' => 'EXAMPLE_TOKEN'],
            'policy' => ['class' => 'managed-private', 'allow_runtime_paths' => []],
        ],
        [
            'name' => 'Object Cache',
            'slug' => 'object-cache',
            'kind' => 'runtime-file',
            'management' => 'local',
            'source' => 'local',
            'path' => 'cms/shared/object-cache.php',
            'version' => '1.0.0',
            'checksum' => null,
            'archive_subdir' => '',
            'extra_labels' => [],
            'source_config' => ['github_repository' => null, 'github_release_asset_pattern' => null, 'github_token_env' => null],
            'policy' => ['class' => 'local-owned', 'allow_runtime_paths' => []],
        ],
    ],
];
file_put_contents(
    $relaxedRoot . '/.wp-core-base/manifest.php',
    "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($relaxedManifest, true) . ";\n"
);
$loadedRelaxedConfig = Config::load($relaxedRoot);
$assert($loadedRelaxedConfig->isRelaxedManifestMode(), 'Expected relaxed manifest mode to load.');
$assert(count($loadedRelaxedConfig->managedDependencies()) === 1, 'Expected managed_kinds to limit sync scope.');
$assert(count($loadedRelaxedConfig->validatedDependencies()) === 2, 'Expected validated dependency scope to exclude non-listed kinds.');
$relaxedUndeclared = (new RuntimeOwnershipInspector($loadedRelaxedConfig))->undeclaredRuntimePaths();
$assert(in_array('cms/plugins/untracked-plugin', array_column($relaxedUndeclared, 'path'), true), 'Expected relaxed ownership scan to report undeclared plugin path.');
$assert(in_array('cms/mu-plugins/local-loader.php', array_column($relaxedUndeclared, 'path'), true), 'Expected relaxed ownership scan to report undeclared MU plugin file.');
$assert(in_array('cms/languages/de_DE', array_column($relaxedUndeclared, 'path'), true), 'Expected custom ownership roots to report undeclared runtime directories.');
$relaxedStager = new RuntimeStager($loadedRelaxedConfig, new RuntimeInspector($loadedRelaxedConfig->runtime));
$relaxedPaths = $relaxedStager->stage('.wp-core-base/build/runtime');
$assert(in_array('cms/plugins/untracked-plugin', $relaxedPaths, true), 'Expected relaxed mode to stage undeclared plugin paths when plugin kind is staged.');
$assert(in_array('cms/mu-plugins/local-loader.php', $relaxedPaths, true), 'Expected relaxed mode to stage undeclared MU plugin files when MU file kind is staged.');
$assert(in_array('cms/languages/de_DE', $relaxedPaths, true), 'Expected relaxed mode to stage undeclared runtime directories when runtime-directory is staged.');
$assert(! in_array('cms/themes/example-theme', $relaxedPaths, true), 'Expected staged_kinds to prevent theme staging.');
$assert(in_array('cms/shared/object-cache.php', $relaxedPaths, true), 'Expected runtime-file entries to stage in relaxed mode.');
$suggestions = (new ManifestSuggester($loadedRelaxedConfig))->render();
$assert(str_contains($suggestions, 'cms/languages/de_DE'), 'Expected manifest suggestions to include undeclared runtime directories.');
$assert(str_contains($suggestions, "'kind' => 'runtime-directory'"), 'Expected manifest suggestions to infer runtime-directory kinds.');

$nestedOwnershipRoot = sys_get_temp_dir() . '/wporg-ownership-nested-' . bin2hex(random_bytes(4));
mkdir($nestedOwnershipRoot . '/cms/runtime/container/config', 0777, true);
mkdir($nestedOwnershipRoot . '/.wp-core-base', 0777, true);
file_put_contents($nestedOwnershipRoot . '/cms/runtime/container/config/app.php', "<?php\n");
$nestedOwnershipManifest = [
    'profile' => 'content-only',
    'paths' => [
        'content_root' => 'cms',
        'plugins_root' => 'cms/plugins',
        'themes_root' => 'cms/themes',
        'mu_plugins_root' => 'cms/mu-plugins',
    ],
    'core' => [
        'mode' => 'external',
        'enabled' => false,
    ],
    'runtime' => array_merge($runtimeDefaults, [
        'ownership_roots' => ['cms/runtime'],
        'staged_kinds' => ['runtime-directory', 'runtime-file'],
        'validated_kinds' => ['runtime-directory', 'runtime-file'],
    ]),
    'github' => ['api_base' => 'https://api.github.com'],
    'automation' => ['base_branch' => null, 'dry_run' => false, 'managed_kinds' => []],
    'dependencies' => [[
        'name' => 'App Config',
        'slug' => 'app-config',
        'kind' => 'runtime-file',
        'management' => 'local',
        'source' => 'local',
        'path' => 'cms/runtime/container/config/app.php',
        'version' => null,
        'checksum' => null,
        'archive_subdir' => '',
        'extra_labels' => [],
        'source_config' => ['github_repository' => null, 'github_release_asset_pattern' => null, 'github_token_env' => null, 'credential_key' => null, 'provider' => null, 'provider_product_id' => null],
        'policy' => ['class' => 'local-owned', 'allow_runtime_paths' => [], 'strip_paths' => [], 'strip_files' => [], 'sanitize_paths' => [], 'sanitize_files' => []],
    ]],
];
file_put_contents(
    $nestedOwnershipRoot . '/.wp-core-base/manifest.php',
    "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($nestedOwnershipManifest, true) . ";\n"
);
$nestedOwnershipConfig = Config::load($nestedOwnershipRoot);
$nestedOwnershipUndeclared = (new RuntimeOwnershipInspector($nestedOwnershipConfig))->undeclaredRuntimePaths();
$assert(
    in_array('cms/runtime/container', array_column($nestedOwnershipUndeclared, 'path'), true),
    'Expected undeclared parent runtime directories not to be hidden by declared child paths.'
);

$stagedCleanRoot = sys_get_temp_dir() . '/wporg-staged-clean-' . bin2hex(random_bytes(4));
mkdir($stagedCleanRoot . '/cms/plugins/custom-plugin/tests', 0777, true);
mkdir($stagedCleanRoot . '/.wp-core-base', 0777, true);
file_put_contents($stagedCleanRoot . '/cms/plugins/custom-plugin/custom-plugin.php', "<?php\n/*\nPlugin Name: Custom Plugin\nVersion: 1.0.0\n*/\n");
file_put_contents($stagedCleanRoot . '/cms/plugins/custom-plugin/README.md', "# Docs\n");
file_put_contents($stagedCleanRoot . '/cms/plugins/custom-plugin/package.json', "{}\n");
file_put_contents($stagedCleanRoot . '/cms/plugins/custom-plugin/tests/test.php', "<?php\n");
$stagedCleanManifest = [
    'profile' => 'content-only',
    'paths' => [
        'content_root' => 'cms',
        'plugins_root' => 'cms/plugins',
        'themes_root' => 'cms/themes',
        'mu_plugins_root' => 'cms/mu-plugins',
    ],
    'core' => ['mode' => 'external', 'enabled' => false],
    'runtime' => array_merge($runtimeDefaults, [
        'validation_mode' => 'staged-clean',
        'strip_files' => ['README*', 'package.json'],
    ]),
    'github' => ['api_base' => 'https://api.github.com'],
    'automation' => ['base_branch' => null, 'dry_run' => false],
    'dependencies' => [[
        'name' => 'Custom Plugin',
        'slug' => 'custom-plugin',
        'kind' => 'plugin',
        'management' => 'local',
        'source' => 'local',
        'path' => 'cms/plugins/custom-plugin',
        'main_file' => 'custom-plugin.php',
        'version' => '1.0.0',
        'checksum' => null,
        'archive_subdir' => '',
        'extra_labels' => [],
        'source_config' => ['github_repository' => null, 'github_release_asset_pattern' => null, 'github_token_env' => null],
        'policy' => ['class' => 'local-owned', 'allow_runtime_paths' => [], 'strip_paths' => ['tests'], 'strip_files' => []],
    ]],
];
file_put_contents(
    $stagedCleanRoot . '/.wp-core-base/manifest.php',
    "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($stagedCleanManifest, true) . ";\n"
);
$loadedStagedClean = Config::load($stagedCleanRoot);
$assert($loadedStagedClean->validationMode() === 'staged-clean', 'Expected staged-clean validation mode to load.');
$stagedCleanStager = new RuntimeStager($loadedStagedClean, new RuntimeInspector($loadedStagedClean->runtime));
$stagedCleanPaths = $stagedCleanStager->stage('.wp-core-base/build/runtime');
$assert(in_array('cms/plugins/custom-plugin', $stagedCleanPaths, true), 'Expected staged-clean runtime staging to include the custom plugin.');
$stagedCleanOutput = $stagedCleanRoot . '/.wp-core-base/build/runtime/cms/plugins/custom-plugin';
$assert(is_file($stagedCleanOutput . '/custom-plugin.php'), 'Expected staged-clean output to keep the runtime plugin file.');
$assert(! file_exists($stagedCleanOutput . '/README.md'), 'Expected staged-clean output to strip README files.');
$assert(! file_exists($stagedCleanOutput . '/package.json'), 'Expected staged-clean output to strip package.json.');
$assert(! file_exists($stagedCleanOutput . '/tests'), 'Expected staged-clean output to strip declared test directories.');

$managedSanitizeRoot = sys_get_temp_dir() . '/wporg-managed-sanitize-' . bin2hex(random_bytes(4));
mkdir($managedSanitizeRoot . '/cms/plugins/managed-plugin/tests', 0777, true);
mkdir($managedSanitizeRoot . '/.wp-core-base', 0777, true);
file_put_contents($managedSanitizeRoot . '/cms/plugins/managed-plugin/managed-plugin.php', "<?php\n/*\nPlugin Name: Managed Plugin\nVersion: 2.0.0\n*/\n");
file_put_contents($managedSanitizeRoot . '/cms/plugins/managed-plugin/README.md', "# Docs\n");
file_put_contents($managedSanitizeRoot . '/cms/plugins/managed-plugin/package.json', "{}\n");
file_put_contents($managedSanitizeRoot . '/cms/plugins/managed-plugin/tests/test.php', "<?php\n");
$managedRuntimeConfig = array_merge($runtimeDefaults, [
    'managed_sanitize_paths' => ['cms/plugins/managed-plugin/tests'],
    'managed_sanitize_files' => ['README*', 'package.json'],
]);
$managedChecksum = (new RuntimeInspector($managedRuntimeConfig))->computeChecksum(
    $managedSanitizeRoot . '/cms/plugins/managed-plugin',
    [],
    ['tests'],
    ['README*', 'package.json']
);
$managedManifest = [
    'profile' => 'content-only',
    'paths' => [
        'content_root' => 'cms',
        'plugins_root' => 'cms/plugins',
        'themes_root' => 'cms/themes',
        'mu_plugins_root' => 'cms/mu-plugins',
    ],
    'core' => ['mode' => 'external', 'enabled' => false],
    'runtime' => $managedRuntimeConfig,
    'github' => ['api_base' => 'https://api.github.com'],
    'automation' => ['base_branch' => null, 'dry_run' => false, 'managed_kinds' => ['plugin']],
    'dependencies' => [[
        'name' => 'Managed Plugin',
        'slug' => 'managed-plugin',
        'kind' => 'plugin',
        'management' => 'managed',
        'source' => 'github-release',
        'path' => 'cms/plugins/managed-plugin',
        'main_file' => 'managed-plugin.php',
        'version' => '2.0.0',
        'checksum' => $managedChecksum,
        'archive_subdir' => '',
        'extra_labels' => [],
        'source_config' => ['github_repository' => 'owner/managed-plugin', 'github_release_asset_pattern' => '*.zip', 'github_token_env' => 'MANAGED_PLUGIN_TOKEN'],
        'policy' => ['class' => 'managed-private', 'allow_runtime_paths' => [], 'sanitize_paths' => ['tests'], 'sanitize_files' => []],
    ]],
];
file_put_contents(
    $managedSanitizeRoot . '/.wp-core-base/manifest.php',
    "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($managedManifest, true) . ";\n"
);
$loadedManagedSanitize = Config::load($managedSanitizeRoot);
$managedInspector = new RuntimeInspector($loadedManagedSanitize->runtime);
$managedInspector->assertPathIsClean(
    $managedSanitizeRoot . '/cms/plugins/managed-plugin',
    [],
    [],
    ['tests'],
    ['README*', 'package.json']
);
$managedMatches = $managedInspector->matchingStrippedEntries(
    $managedSanitizeRoot . '/cms/plugins/managed-plugin',
    ['tests'],
    ['README*', 'package.json']
);
$assert(in_array('README.md', $managedMatches, true), 'Expected managed sanitization to detect sanitizable README files.');
$assert(in_array('tests', $managedMatches, true), 'Expected managed sanitization to detect sanitizable directories.');
$assert(
    $managedInspector->computeChecksum($managedSanitizeRoot . '/cms/plugins/managed-plugin', [], ['tests'], ['README*', 'package.json']) === $loadedManagedSanitize->dependencyByKey('plugin:github-release:managed-plugin')['checksum'],
    'Expected managed dependency checksum to reflect the sanitized runtime snapshot.'
);
$managedStager = new RuntimeStager($loadedManagedSanitize, $managedInspector);
$managedStager->stage('.wp-core-base/build/runtime');
$managedOutput = $managedSanitizeRoot . '/.wp-core-base/build/runtime/cms/plugins/managed-plugin';
$assert(is_file($managedOutput . '/managed-plugin.php'), 'Expected managed staging output to retain runtime files.');
$assert(! file_exists($managedOutput . '/README.md'), 'Expected managed staging output to strip sanitizable README files.');
$assert(! file_exists($managedOutput . '/package.json'), 'Expected managed staging output to strip sanitizable package.json.');
$assert(! file_exists($managedOutput . '/tests'), 'Expected managed staging output to strip sanitizable directories.');

$invalidKindRoot = sys_get_temp_dir() . '/wporg-invalid-kind-' . bin2hex(random_bytes(4));
mkdir($invalidKindRoot . '/.wp-core-base', 0777, true);
file_put_contents(
    $invalidKindRoot . '/.wp-core-base/manifest.php',
    "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export([
        'profile' => 'content-only',
        'paths' => [
            'content_root' => 'cms',
            'plugins_root' => 'cms/plugins',
            'themes_root' => 'cms/themes',
            'mu_plugins_root' => 'cms/mu-plugins',
        ],
        'core' => ['mode' => 'external', 'enabled' => false],
        'runtime' => $legacyRuntimeDefaults,
        'github' => ['api_base' => 'https://api.github.com'],
        'automation' => ['base_branch' => null, 'dry_run' => false],
        'dependencies' => [[
            'name' => 'Broken Entry',
            'slug' => 'broken-entry',
            'kind' => 'widget',
            'management' => 'local',
            'source' => 'local',
            'path' => 'cms/widgets/broken-entry',
            'version' => '1.0.0',
            'checksum' => null,
            'archive_subdir' => '',
            'extra_labels' => [],
            'source_config' => ['github_repository' => null, 'github_release_asset_pattern' => null, 'github_token_env' => null],
            'policy' => ['class' => 'local-owned', 'allow_runtime_paths' => []],
        ]],
    ], true) . ";\n"
);
$invalidKindFailed = false;
try {
    Config::load($invalidKindRoot);
} catch (RuntimeException $exception) {
    $invalidKindFailed = str_contains($exception->getMessage(), 'must be one of');
}
$assert($invalidKindFailed, 'Expected invalid dependency kinds to be rejected.');

$contentOnlyManagedCoreRejected = false;
try {
    Config::fromArray($repoRoot, [
        'profile' => 'content-only',
        'paths' => [
            'content_root' => 'cms',
            'plugins_root' => 'cms/plugins',
            'themes_root' => 'cms/themes',
            'mu_plugins_root' => 'cms/mu-plugins',
        ],
        'core' => ['mode' => 'managed', 'enabled' => true],
        'runtime' => $runtimeDefaults,
        'github' => ['api_base' => 'https://api.github.com'],
        'automation' => ['base_branch' => null, 'dry_run' => false, 'managed_kinds' => ['plugin', 'theme']],
        'dependencies' => [],
    ]);
} catch (RuntimeException $exception) {
    $contentOnlyManagedCoreRejected = str_contains($exception->getMessage(), 'content-only profile may not manage WordPress core');
}
$assert($contentOnlyManagedCoreRejected, 'Expected content-only manifests with managed core to be rejected.');

$dangerousStageDirRejected = false;
try {
    $dangerousRuntime = $runtimeDefaults;
    $dangerousRuntime['stage_dir'] = 'cms/plugins/build/runtime';
    Config::fromArray($repoRoot, [
        'profile' => 'content-only',
        'paths' => [
            'content_root' => 'cms',
            'plugins_root' => 'cms/plugins',
            'themes_root' => 'cms/themes',
            'mu_plugins_root' => 'cms/mu-plugins',
        ],
        'core' => ['mode' => 'external', 'enabled' => false],
        'runtime' => $dangerousRuntime,
        'github' => ['api_base' => 'https://api.github.com'],
        'automation' => ['base_branch' => null, 'dry_run' => false, 'managed_kinds' => ['plugin', 'theme']],
        'dependencies' => [],
    ]);
} catch (RuntimeException $exception) {
    $dangerousStageDirRejected = str_contains($exception->getMessage(), 'runtime.stage_dir');
}
$assert($dangerousStageDirRejected, 'Expected runtime.stage_dir overlap with live runtime roots to be rejected.');

$dangerousControlStageDirRejected = false;
try {
    $dangerousRuntime = $runtimeDefaults;
    $dangerousRuntime['stage_dir'] = '.wp-core-base/runtime';
    Config::fromArray($repoRoot, [
        'profile' => 'content-only',
        'paths' => [
            'content_root' => 'cms',
            'plugins_root' => 'cms/plugins',
            'themes_root' => 'cms/themes',
            'mu_plugins_root' => 'cms/mu-plugins',
        ],
        'core' => ['mode' => 'external', 'enabled' => false],
        'runtime' => $dangerousRuntime,
        'github' => ['api_base' => 'https://api.github.com'],
        'automation' => ['base_branch' => null, 'dry_run' => false, 'managed_kinds' => ['plugin', 'theme']],
        'dependencies' => [],
    ]);
} catch (RuntimeException $exception) {
    $dangerousControlStageDirRejected = str_contains($exception->getMessage(), 'runtime.stage_dir');
}
$assert($dangerousControlStageDirRejected, 'Expected runtime.stage_dir overlap with framework control paths to be rejected.');

$broadAllowRuntimePathRejected = false;
try {
    $dangerousRuntime = $runtimeDefaults;
    $dangerousRuntime['allow_runtime_paths'] = ['cms/plugins'];
    Config::fromArray($repoRoot, [
        'profile' => 'content-only',
        'paths' => [
            'content_root' => 'cms',
            'plugins_root' => 'cms/plugins',
            'themes_root' => 'cms/themes',
            'mu_plugins_root' => 'cms/mu-plugins',
        ],
        'core' => ['mode' => 'external', 'enabled' => false],
        'runtime' => $dangerousRuntime,
        'github' => ['api_base' => 'https://api.github.com'],
        'automation' => ['base_branch' => null, 'dry_run' => false, 'managed_kinds' => ['plugin', 'theme']],
        'dependencies' => [],
    ]);
} catch (RuntimeException $exception) {
    $broadAllowRuntimePathRejected = str_contains($exception->getMessage(), 'runtime.allow_runtime_paths');
}
$assert($broadAllowRuntimePathRejected, 'Expected broad runtime.allow_runtime_paths entries to be rejected.');

$outsideContentAllowRuntimePathRejected = false;
try {
    $dangerousRuntime = $runtimeDefaults;
    $dangerousRuntime['allow_runtime_paths'] = ['.github'];
    Config::fromArray($repoRoot, [
        'profile' => 'content-only',
        'paths' => [
            'content_root' => 'cms',
            'plugins_root' => 'cms/plugins',
            'themes_root' => 'cms/themes',
            'mu_plugins_root' => 'cms/mu-plugins',
        ],
        'core' => ['mode' => 'external', 'enabled' => false],
        'runtime' => $dangerousRuntime,
        'github' => ['api_base' => 'https://api.github.com'],
        'automation' => ['base_branch' => null, 'dry_run' => false, 'managed_kinds' => ['plugin', 'theme']],
        'dependencies' => [],
    ]);
} catch (RuntimeException $exception) {
    $outsideContentAllowRuntimePathRejected = str_contains($exception->getMessage(), 'runtime.allow_runtime_paths');
}
$assert($outsideContentAllowRuntimePathRejected, 'Expected runtime.allow_runtime_paths outside content_root to be rejected.');

$tempScaffoldRoot = sys_get_temp_dir() . '/wporg-scaffold-' . bin2hex(random_bytes(4));
mkdir($tempScaffoldRoot, 0777, true);
(new DownstreamScaffolder(dirname(__DIR__, 3), $tempScaffoldRoot))->scaffold('vendor/wp-core-base', 'content-only', 'cms', true);
$scaffoldedManifest = (string) file_get_contents($tempScaffoldRoot . '/.wp-core-base/manifest.php');
$scaffoldedUsage = (string) file_get_contents($tempScaffoldRoot . '/.wp-core-base/USAGE.md');
$scaffoldedAgents = (string) file_get_contents($tempScaffoldRoot . '/AGENTS.md');
$scaffoldedWorkflow = (string) file_get_contents($tempScaffoldRoot . '/.github/workflows/wporg-updates.yml');
$scaffoldedReconcileWorkflow = (string) file_get_contents($tempScaffoldRoot . '/.github/workflows/wporg-updates-reconcile.yml');
$scaffoldedBlocker = (string) file_get_contents($tempScaffoldRoot . '/.github/workflows/wporg-update-pr-blocker.yml');
$scaffoldedValidate = (string) file_get_contents($tempScaffoldRoot . '/.github/workflows/wporg-validate-runtime.yml');
$documentedWorkflow = (string) file_get_contents($repoRoot . '/docs/examples/downstream-workflow.yml');
$documentedReconcileWorkflow = (string) file_get_contents($repoRoot . '/docs/examples/downstream-updates-reconcile-workflow.yml');
$documentedBlockerWorkflow = (string) file_get_contents($repoRoot . '/docs/examples/downstream-pr-blocker-workflow.yml');
$documentedValidateWorkflow = (string) file_get_contents($repoRoot . '/docs/examples/downstream-validate-runtime-workflow.yml');
$assert(str_contains($scaffoldedManifest, "'profile' => 'content-only'"), 'Expected scaffolded manifest to set the requested profile.');
$assert(str_contains($scaffoldedManifest, "'content_root' => 'cms'"), 'Expected scaffolded manifest to set the requested content root.');
$assert(str_contains($scaffoldedManifest, "'manifest_mode' => 'strict'"), 'Expected scaffolded manifest to include manifest mode.');
$assert(str_contains($scaffoldedManifest, "'validation_mode' => 'source-clean'"), 'Expected scaffolded manifest to include validation mode.');
$assert(str_contains($scaffoldedManifest, "'ownership_roots' =>"), 'Expected scaffolded manifest to include ownership roots.');
$assert(str_contains($scaffoldedManifest, "'managed_kinds' => ["), 'Expected scaffolded manifest to include managed_kinds.');
$assert(str_contains($scaffoldedManifest, "'kind' => 'mu-plugin-file'"), 'Expected scaffolded manifest to document local MU plugin files.');
$assert(str_contains($scaffoldedManifest, "'kind' => 'runtime-directory'"), 'Expected scaffolded manifest to document runtime directories.');
$assert(str_contains($scaffoldedUsage, 'vendor/wp-core-base/bin/wp-core-base add-dependency'), 'Expected scaffolded usage guide to point at the vendored wrapper for routine dependency authoring.');
$assert(str_contains($scaffoldedUsage, '.wp-core-base/manifest.php'), 'Expected scaffolded usage guide to explain the manifest source of truth.');
$assert(str_contains($scaffoldedAgents, '.wp-core-base/USAGE.md'), 'Expected scaffolded downstream AGENTS.md to point agents at the local usage guide first.');
$assert(str_contains($scaffoldedAgents, 'Do not start by hand-editing `.wp-core-base/manifest.php`'), 'Expected scaffolded downstream AGENTS.md to steer agents toward the CLI-first workflow.');
$assert(str_contains($scaffoldedAgents, 'GitHub Release Trust Checks'), 'Expected scaffolded downstream AGENTS.md to include GitHub release trust-check guidance.');
$assert(str_contains($scaffoldedAgents, 'source_config.checksum_asset_pattern'), 'Expected scaffolded downstream AGENTS.md to tell agents where checksum sidecar patterns belong.');
$assert(str_contains($scaffoldedAgents, 'Do not guess checksum patterns from tag names alone.'), 'Expected scaffolded downstream AGENTS.md to warn agents against guessing checksum patterns.');
$assert(str_contains($scaffoldedWorkflow, 'php vendor/wp-core-base/tools/wporg-updater/bin/wporg-updater.php sync'), 'Expected scaffolded workflow to target the configured tool path.');
$assert(str_contains($scaffoldedWorkflow, 'WPORG_REPO_ROOT: ${{ github.workspace }}'), 'Expected scaffolded workflow to set WPORG_REPO_ROOT so sync runs against the downstream repo.');
$assert(! str_contains($scaffoldedWorkflow, 'pull_request_target:'), 'Expected scaffolded updates workflow to keep scheduled/manual execution separate from PR reconciliation.');
$assert(str_contains($scaffoldedBlocker, 'contents: read'), 'Expected scaffolded blocker workflow to grant contents: read for actions/checkout.');
$assert(str_contains($scaffoldedValidate, 'stage-runtime'), 'Expected scaffolded validation workflow to stage runtime output.');
$assert(str_contains($scaffoldedWorkflow, $checkoutActionSha), 'Expected scaffolded updates workflow to pin actions/checkout by full commit SHA.');
$assert(str_contains($scaffoldedWorkflow, $setupPhpActionSha), 'Expected scaffolded updates workflow to pin setup-php by full commit SHA.');
$assert(str_contains($scaffoldedReconcileWorkflow, $checkoutActionSha), 'Expected scaffolded reconciliation workflow to pin actions/checkout by full commit SHA.');
$assert(str_contains($scaffoldedReconcileWorkflow, $setupPhpActionSha), 'Expected scaffolded reconciliation workflow to pin setup-php by full commit SHA.');
$assert(str_contains($scaffoldedReconcileWorkflow, "github.event.pull_request.merged == true"), 'Expected scaffolded reconciliation workflow to narrow closed-PR reconciliation to merged PRs.');
$assert(str_contains($scaffoldedReconcileWorkflow, "automation:dependency-update"), 'Expected scaffolded reconciliation workflow to gate merged-PR reconciliation to framework automation PRs.');
$assert(str_contains($scaffoldedBlocker, $checkoutActionSha), 'Expected scaffolded blocker workflow to pin actions/checkout by full commit SHA.');
$assert(str_contains($scaffoldedBlocker, $setupPhpActionSha), 'Expected scaffolded blocker workflow to pin setup-php by full commit SHA.');
$assert(str_contains($scaffoldedValidate, $checkoutActionSha), 'Expected scaffolded validation workflow to pin actions/checkout by full commit SHA.');
$assert(str_contains($scaffoldedValidate, $setupPhpActionSha), 'Expected scaffolded validation workflow to pin setup-php by full commit SHA.');
$assert($normalizeWorkflowExample($scaffoldedWorkflow) === $normalizeWorkflowExample($documentedWorkflow), 'Expected downstream updates example workflow to match scaffolded output.');
$assert($normalizeWorkflowExample($scaffoldedReconcileWorkflow) === $normalizeWorkflowExample($documentedReconcileWorkflow), 'Expected downstream reconciliation example workflow to match scaffolded output.');
$assert($normalizeWorkflowExample($scaffoldedBlocker) === $normalizeWorkflowExample($documentedBlockerWorkflow), 'Expected downstream blocker example workflow to match scaffolded output.');
$assert($normalizeWorkflowExample($scaffoldedValidate) === $normalizeWorkflowExample($documentedValidateWorkflow), 'Expected downstream validation example workflow to match scaffolded output.');
$scaffoldedFramework = FrameworkConfig::load($tempScaffoldRoot);
$assert($scaffoldedFramework->distributionPath() === 'vendor/wp-core-base', 'Expected scaffolded framework metadata to point at the vendored framework path.');
$scaffoldedFrameworkWorkflow = (string) file_get_contents($tempScaffoldRoot . '/.github/workflows/wp-core-base-self-update.yml');
$documentedFrameworkWorkflow = (string) file_get_contents($repoRoot . '/docs/examples/downstream-framework-self-update-workflow.yml');
$assert(str_contains($scaffoldedFrameworkWorkflow, 'framework-sync --repo-root=.'), 'Expected scaffolded self-update workflow to run framework-sync.');
$assert(str_contains($scaffoldedFrameworkWorkflow, $checkoutActionSha), 'Expected scaffolded framework self-update workflow to pin actions/checkout by full commit SHA.');
$assert(str_contains($scaffoldedFrameworkWorkflow, $setupPhpActionSha), 'Expected scaffolded framework self-update workflow to pin setup-php by full commit SHA.');
$assert($normalizeWorkflowExample($scaffoldedFrameworkWorkflow) === $normalizeWorkflowExample($documentedFrameworkWorkflow), 'Expected downstream framework self-update example workflow to match scaffolded output.');
$renderedManagedFiles = (new DownstreamScaffolder(dirname(__DIR__, 3), $tempScaffoldRoot))->renderFrameworkManagedFiles('vendor/wp-core-base');
$workflowExampleMap = [
    'docs/examples/downstream-workflow.yml' => '.github/workflows/wporg-updates.yml',
    'docs/examples/downstream-updates-reconcile-workflow.yml' => '.github/workflows/wporg-updates-reconcile.yml',
    'docs/examples/downstream-pr-blocker-workflow.yml' => '.github/workflows/wporg-update-pr-blocker.yml',
    'docs/examples/downstream-validate-runtime-workflow.yml' => '.github/workflows/wporg-validate-runtime.yml',
    'docs/examples/downstream-framework-self-update-workflow.yml' => '.github/workflows/wp-core-base-self-update.yml',
];
$extractWorkflowPermissions = static function (string $contents): string {
    if (preg_match('/^permissions:\n(?:(?:  .*\n)+)/m', $contents, $matches) === 1) {
        return trim($matches[0]);
    }

    throw new RuntimeException('Workflow did not contain a permissions block.');
};
$extractRunCommands = static function (string $contents): array {
    preg_match_all('/^\s*run:\s*(.+)$/m', $contents, $matches);

    return array_values(array_map(static fn (string $command): string => trim($command), $matches[1]));
};

foreach ($workflowExampleMap as $examplePath => $managedPath) {
    $exampleContents = (string) file_get_contents($repoRoot . '/' . $examplePath);
    $managedContents = (string) $renderedManagedFiles[$managedPath];
    $assert(
        $extractWorkflowPermissions($exampleContents) === $extractWorkflowPermissions($managedContents),
        sprintf('Expected example workflow %s to keep permissions in parity with %s.', $examplePath, $managedPath)
    );

    foreach ($extractRunCommands($managedContents) as $command) {
        $assert(
            in_array($command, $extractRunCommands($exampleContents), true),
            sprintf('Expected example workflow %s to retain command parity for `%s`.', $examplePath, $command)
        );
    }
}

$repoWorkflowExpectations = [
    '.github/workflows/wporg-updates.yml' => ['contents: write', 'pull-requests: write', 'issues: write'],
    '.github/workflows/wporg-updates-reconcile.yml' => ['contents: write', 'pull-requests: write', 'issues: write'],
    '.github/workflows/wporg-update-pr-blocker.yml' => ['contents: read', 'pull-requests: read', 'issues: read'],
    '.github/workflows/wporg-validate-runtime.yml' => ['contents: read'],
];

foreach ($repoWorkflowExpectations as $workflowPath => $snippets) {
    $workflowContents = (string) file_get_contents($repoRoot . '/' . $workflowPath);

    foreach ($snippets as $snippet) {
        $assert(
            str_contains($workflowContents, $snippet),
            sprintf('Expected repository workflow %s to retain permission snippet `%s`.', $workflowPath, $snippet)
        );
    }
}

$conflictScaffoldRoot = sys_get_temp_dir() . '/wporg-scaffold-conflict-' . bin2hex(random_bytes(4));
mkdir($conflictScaffoldRoot . '/.github/workflows', 0777, true);
file_put_contents($conflictScaffoldRoot . '/.github/workflows/wporg-updates.yml', "# local workflow customization\n");
$conflictRejected = false;

try {
    (new DownstreamScaffolder(dirname(__DIR__, 3), $conflictScaffoldRoot))->scaffold('vendor/wp-core-base', 'content-only', 'cms', false);
} catch (RuntimeException $exception) {
    $conflictRejected = str_contains($exception->getMessage(), '--adopt-existing-managed-files');
}

$assert($conflictRejected, 'Expected scaffold-downstream to reject conflicting managed files unless force or adopt-existing-managed-files is used.');
(new DownstreamScaffolder(dirname(__DIR__, 3), $conflictScaffoldRoot))->scaffold('vendor/wp-core-base', 'content-only', 'cms', false, true);
$assert(
    (string) file_get_contents($conflictScaffoldRoot . '/.github/workflows/wporg-updates.yml') === "# local workflow customization\n",
    'Expected scaffold-downstream --adopt-existing-managed-files to preserve local managed-file contents.'
);
$adoptedFramework = FrameworkConfig::load($conflictScaffoldRoot);
$assert(
    $adoptedFramework->managedFiles()['.github/workflows/wporg-updates.yml'] === 'sha256:' . hash('sha256', "# local workflow customization\n"),
    'Expected adopted framework-managed files to be recorded using the current local contents checksum.'
);
$premiumSourceDetailsWithoutNotes = [
    'version' => '6.3.0',
    'release_at' => gmdate(DATE_ATOM),
    'download_url' => 'https://example.com/example-vendor.zip',
    'source_reference' => 'https://example.com/example-vendor',
    'source_details' => [
        ['label' => 'Update contract', 'value' => '`premium` provider `example-vendor`'],
    ],
];
$assert(
    ! isset($premiumSourceDetailsWithoutNotes['notes_markup']) && ! isset($premiumSourceDetailsWithoutNotes['notes_text']),
    'Expected the premium fixture without notes fields to model providers that do not return release notes.'
);

$migrationScaffoldRoot = sys_get_temp_dir() . '/wporg-scaffold-migration-' . bin2hex(random_bytes(4));
mkdir($migrationScaffoldRoot, 0777, true);
(new DownstreamScaffolder(dirname(__DIR__, 3), $migrationScaffoldRoot))->scaffold('vendor/wp-core-base', 'content-only-migration', 'cms', true);
$migrationManifest = (string) file_get_contents($migrationScaffoldRoot . '/.wp-core-base/manifest.php');
$assert(str_contains($migrationManifest, "'manifest_mode' => 'relaxed'"), 'Expected migration scaffold preset to use relaxed ownership mode.');

$imageFirstScaffoldRoot = sys_get_temp_dir() . '/wporg-scaffold-image-first-' . bin2hex(random_bytes(4));
mkdir($imageFirstScaffoldRoot, 0777, true);
(new DownstreamScaffolder(dirname(__DIR__, 3), $imageFirstScaffoldRoot))->scaffold('vendor/wp-core-base', 'content-only-image-first', 'cms', true);
$imageFirstManifest = (string) file_get_contents($imageFirstScaffoldRoot . '/.wp-core-base/manifest.php');
$assert(str_contains($imageFirstManifest, "'validation_mode' => 'staged-clean'"), 'Expected image-first scaffold preset to use staged-clean validation.');
$assert(str_contains($imageFirstManifest, "'cms/languages'"), 'Expected image-first scaffold preset to include languages ownership roots.');
$assert(str_contains($imageFirstManifest, "'managed_sanitize_paths' =>"), 'Expected image-first scaffold preset to include managed sanitation paths.');

$compactScaffoldRoot = sys_get_temp_dir() . '/wporg-scaffold-image-first-compact-' . bin2hex(random_bytes(4));
mkdir($compactScaffoldRoot, 0777, true);
(new DownstreamScaffolder(dirname(__DIR__, 3), $compactScaffoldRoot))->scaffold('vendor/wp-core-base', 'content-only-image-first-compact', 'cms', true);
$assert(! file_exists($compactScaffoldRoot . '/.github/workflows/wporg-validate-runtime.yml'), 'Expected compact image-first scaffold profile to omit the standalone runtime-validation workflow.');
$compactReconcileWorkflow = (string) file_get_contents($compactScaffoldRoot . '/.github/workflows/wporg-updates-reconcile.yml');
$assert(str_contains($compactReconcileWorkflow, "automation:framework-update"), 'Expected compact scaffold to keep merged automation PR reconciliation in the dedicated reconciliation workflow.');

$updaterReflection = new ReflectionClass(\WpOrgPluginUpdater\Updater::class);
$normalizedReleaseData = $updaterReflection->getMethod('normalizedReleaseData');
$normalizedReleaseData->setAccessible(true);
$updaterWithoutConstructor = $updaterReflection->newInstanceWithoutConstructor();
$normalizedFallback = $normalizedReleaseData->invoke($updaterWithoutConstructor, $premiumSourceDetailsWithoutNotes, '6.3.0');
$assert(
    $normalizedFallback['notes_markup'] === '_Release notes unavailable for version 6.3.0._',
    'Expected the updater to synthesize fallback notes markup when a source omits release notes.'
);
$assert(
    $normalizedFallback['notes_text'] === 'Release notes unavailable for version 6.3.0.',
    'Expected the updater to synthesize fallback notes text when a source omits release notes.'
);
$branchRefreshRequired = $updaterReflection->getMethod('branchRefreshRequired');
$branchRefreshRequired->setAccessible(true);
$assert(
    $branchRefreshRequired->invoke($updaterWithoutConstructor, [], 'abc123') === true,
    'Expected updater PR metadata without a recorded base revision to refresh once against the current base branch.'
);
$assert(
    $branchRefreshRequired->invoke($updaterWithoutConstructor, ['base_revision' => 'abc123'], 'abc123') === false,
    'Expected updater PR metadata with a matching base revision to avoid unnecessary branch refreshes.'
);
$assert(
    $branchRefreshRequired->invoke($updaterWithoutConstructor, ['base_revision' => 'stale456'], 'abc123') === true,
    'Expected updater PR metadata with a stale base revision to require branch refresh.'
);
$partitionPullRequestsByTargetVersion = $updaterReflection->getMethod('partitionPullRequestsByTargetVersion');
$partitionPullRequestsByTargetVersion->setAccessible(true);
[$canonicalPrs, $duplicatePrs] = $partitionPullRequestsByTargetVersion->invoke($updaterWithoutConstructor, [
    ['number' => 38, 'planned_target_version' => '0.1.0', 'planned_release_at' => '2026-04-01T00:00:00+00:00', 'updated_at' => '2026-04-02T00:00:00+00:00', 'metadata' => ['branch' => 'codex/update-a'], 'head' => ['ref' => 'codex/update-a', 'repo' => ['full_name' => 'example/repo']], 'base' => ['repo' => ['full_name' => 'example/repo']]],
    ['number' => 37, 'planned_target_version' => '0.1.0', 'planned_release_at' => '2026-04-01T00:00:00+00:00', 'updated_at' => '2026-04-01T00:00:00+00:00', 'metadata' => ['branch' => 'codex/update-b'], 'head' => ['ref' => 'codex/update-b', 'repo' => ['full_name' => 'fork/repo']], 'base' => ['repo' => ['full_name' => 'example/repo']]],
    ['number' => 39, 'planned_target_version' => '0.2.0', 'planned_release_at' => '2026-04-03T00:00:00+00:00', 'updated_at' => '2026-04-03T00:00:00+00:00', 'metadata' => ['branch' => 'codex/update-c'], 'head' => ['ref' => 'codex/update-c', 'repo' => ['full_name' => 'example/repo']], 'base' => ['repo' => ['full_name' => 'example/repo']]],
]);
$assert(count($canonicalPrs) === 2, 'Expected updater duplicate partitioning to keep one canonical PR per target version.');
$assert((int) $canonicalPrs[0]['number'] === 38, 'Expected updater duplicate partitioning to prefer the healthiest duplicate candidate, not simply the oldest PR.');
$assert(count($duplicatePrs) === 1 && (int) $duplicatePrs[0]['number'] === 37, 'Expected updater duplicate partitioning to quarantine the weaker duplicate candidate.');
$pullRequestAlreadySatisfied = $updaterReflection->getMethod('pullRequestAlreadySatisfied');
$pullRequestAlreadySatisfied->setAccessible(true);
$assert(
    $pullRequestAlreadySatisfied->invoke($updaterWithoutConstructor, '0.1.0', '0.1.0') === true,
    'Expected updater to treat matching base and target versions as already satisfied.'
);
$assert(
    $pullRequestAlreadySatisfied->invoke($updaterWithoutConstructor, '0.1.0', '0.0.9') === true,
    'Expected updater to treat older target versions as stale once base is newer.'
);
$assert(
    $pullRequestAlreadySatisfied->invoke($updaterWithoutConstructor, '0.1.0', '0.2.0') === false,
    'Expected updater to keep PRs open when the target version is still ahead of base.'
);
$coreUpdaterReflection = new ReflectionClass(\WpOrgPluginUpdater\CoreUpdater::class);
$coreUpdaterWithoutConstructor = $coreUpdaterReflection->newInstanceWithoutConstructor();
$corePartition = $coreUpdaterReflection->getMethod('partitionPullRequestsByTargetVersion');
$corePartition->setAccessible(true);
[$coreCanonical, $coreDuplicates] = $corePartition->invoke($coreUpdaterWithoutConstructor, [
    ['number' => 11, 'planned_target_version' => '6.9.4', 'planned_release_at' => '2026-04-01T00:00:00+00:00', 'updated_at' => '2026-04-01T00:00:00+00:00', 'metadata' => ['branch' => 'codex/core-a'], 'head' => ['ref' => 'codex/core-a', 'repo' => ['full_name' => 'fork/repo']], 'base' => ['repo' => ['full_name' => 'example/repo']]],
    ['number' => 12, 'planned_target_version' => '6.9.4', 'planned_release_at' => '2026-04-01T00:00:00+00:00', 'updated_at' => '2026-04-02T00:00:00+00:00', 'metadata' => ['branch' => 'codex/core-b'], 'head' => ['ref' => 'codex/core-b', 'repo' => ['full_name' => 'example/repo']], 'base' => ['repo' => ['full_name' => 'example/repo']]],
    ['number' => 13, 'planned_target_version' => '7.0.0', 'planned_release_at' => '2026-04-03T00:00:00+00:00', 'updated_at' => '2026-04-03T00:00:00+00:00', 'metadata' => ['branch' => 'codex/core-c'], 'head' => ['ref' => 'codex/core-c', 'repo' => ['full_name' => 'example/repo']], 'base' => ['repo' => ['full_name' => 'example/repo']]],
]);
$assert(count($coreCanonical) === 2, 'Expected core updater duplicate partitioning to keep one canonical PR per target version.');
$assert((int) $coreCanonical[0]['number'] === 12, 'Expected core updater duplicate partitioning to prefer the healthiest duplicate candidate.');
$assert(count($coreDuplicates) === 1 && (int) $coreDuplicates[0]['number'] === 11, 'Expected core updater duplicate partitioning to mark the weaker duplicate candidate.');
$coreSatisfied = $coreUpdaterReflection->getMethod('pullRequestAlreadySatisfied');
$coreSatisfied->setAccessible(true);
$assert(
    $coreSatisfied->invoke($coreUpdaterWithoutConstructor, '6.9.4', '6.9.4') === true,
    'Expected core updater to treat matching base and target versions as already satisfied.'
);
$assert(
    $coreSatisfied->invoke($coreUpdaterWithoutConstructor, '6.9.4', '7.0.0') === false,
    'Expected core updater to keep PRs open when the target version is still ahead of base.'
);
$frameworkSyncerReflection = new ReflectionClass(\WpOrgPluginUpdater\FrameworkSyncer::class);
$frameworkSyncerWithoutConstructor = $frameworkSyncerReflection->newInstanceWithoutConstructor();
$frameworkPartition = $frameworkSyncerReflection->getMethod('partitionPullRequestsByTargetVersion');
$frameworkPartition->setAccessible(true);
[$frameworkCanonical, $frameworkDuplicates] = $frameworkPartition->invoke($frameworkSyncerWithoutConstructor, [
    ['number' => 21, 'planned_target_version' => '1.3.1', 'planned_release_at' => '2026-04-01T00:00:00+00:00', 'updated_at' => '2026-04-01T00:00:00+00:00', 'metadata' => ['branch' => 'codex/framework-a'], 'head' => ['ref' => 'codex/framework-a', 'repo' => ['full_name' => 'fork/repo']], 'base' => ['repo' => ['full_name' => 'example/repo']]],
    ['number' => 22, 'planned_target_version' => '1.3.1', 'planned_release_at' => '2026-04-01T00:00:00+00:00', 'updated_at' => '2026-04-02T00:00:00+00:00', 'metadata' => ['branch' => 'codex/framework-b'], 'head' => ['ref' => 'codex/framework-b', 'repo' => ['full_name' => 'example/repo']], 'base' => ['repo' => ['full_name' => 'example/repo']]],
    ['number' => 23, 'planned_target_version' => '1.4.0', 'planned_release_at' => '2026-04-03T00:00:00+00:00', 'updated_at' => '2026-04-03T00:00:00+00:00', 'metadata' => ['branch' => 'codex/framework-c'], 'head' => ['ref' => 'codex/framework-c', 'repo' => ['full_name' => 'example/repo']], 'base' => ['repo' => ['full_name' => 'example/repo']]],
]);
$assert(count($frameworkCanonical) === 2, 'Expected framework sync duplicate partitioning to keep one canonical PR per target version.');
$assert((int) $frameworkCanonical[0]['number'] === 22, 'Expected framework sync duplicate partitioning to prefer the healthiest duplicate candidate.');
$assert(count($frameworkDuplicates) === 1 && (int) $frameworkDuplicates[0]['number'] === 21, 'Expected framework sync duplicate partitioning to mark the weaker duplicate candidate.');
$frameworkSatisfied = $frameworkSyncerReflection->getMethod('pullRequestAlreadySatisfied');
$frameworkSatisfied->setAccessible(true);
$assert(
    $frameworkSatisfied->invoke($frameworkSyncerWithoutConstructor, '1.3.1', '1.3.1') === true,
    'Expected framework sync to treat matching base and target versions as already satisfied.'
);
$assert(
    $frameworkSatisfied->invoke($frameworkSyncerWithoutConstructor, '1.3.1', '1.4.0') === false,
    'Expected framework sync to keep PRs open when the target version is still ahead of base.'
);
$scaffoldedUpdatesWorkflow = (string) file_get_contents($imageFirstScaffoldRoot . '/.github/workflows/wporg-updates.yml');
$scaffoldedReconcileWorkflow = (string) file_get_contents($imageFirstScaffoldRoot . '/.github/workflows/wporg-updates-reconcile.yml');
$assert(str_contains($scaffoldedUpdatesWorkflow, 'group: wp-core-base-dependency-sync'), 'Expected scaffolded updates workflow to use the shared dependency-sync concurrency group.');
$assert(str_contains($scaffoldedReconcileWorkflow, 'group: wp-core-base-dependency-sync'), 'Expected scaffolded reconcile workflow to share the dependency-sync concurrency group.');
$assert(str_contains($scaffoldedUpdatesWorkflow, '--report-json=.wp-core-base/build/sync-report.json --fail-on-source-errors'), 'Expected scaffolded updates workflow to fail after the run when dependency-source warnings were recorded.');
$assert(str_contains($scaffoldedUpdatesWorkflow, 'render-sync-report'), 'Expected scaffolded updates workflow to publish a sync summary.');
$assert(str_contains($scaffoldedUpdatesWorkflow, 'sync-report-issue'), 'Expected scaffolded updates workflow to synchronize the dependency source-failure issue.');
$assert(str_contains($scaffoldedReconcileWorkflow, '--report-json=.wp-core-base/build/sync-report.json --fail-on-source-errors'), 'Expected scaffolded reconcile workflow to fail after the run when dependency-source warnings were recorded.');
$assert(str_contains($scaffoldedReconcileWorkflow, 'sync-report-issue'), 'Expected scaffolded reconcile workflow to synchronize the dependency source-failure issue.');
$syncReport = SyncReport::build([], ['plugin:premium:example-vendor:private-plugin: Invalid access credentials.']);
$assert($syncReport['status'] === SyncReport::STATUS_WARNING, 'Expected sync report builder to mark dependency-source warnings as warning status.');
$assert(SyncReport::renderSummary($syncReport) !== '', 'Expected sync report renderer to produce summary markdown.');
$syncReportPath = sys_get_temp_dir() . '/wporg-sync-report-' . bin2hex(random_bytes(4)) . '.json';
SyncReport::write($syncReport, $syncReportPath);
$assert(SyncReport::exists($syncReportPath), 'Expected sync report writer to create the requested report file.');
$reloadedSyncReport = SyncReport::read($syncReportPath);
$assert(($reloadedSyncReport['warning_count'] ?? null) === 1, 'Expected sync report reader to reload the written warning count.');
$fakeIssueClient = new FakeGitHubAutomationClient();
$fakeIssueClient->openIssues = [
    ['number' => 8, 'title' => 'wp-core-base dependency source failures'],
    ['number' => 9, 'title' => 'wp-core-base dependency source failures'],
];
SyncReport::syncIssue($fakeIssueClient, $syncReport, 'https://example.com/run');
$assert(count($fakeIssueClient->updatedIssues) === 1, 'Expected sync-report issue sync to update the canonical open issue when warnings exist.');
$assert(count($fakeIssueClient->closedIssues) === 1 && (int) $fakeIssueClient->closedIssues[0]['number'] === 9, 'Expected sync-report issue sync to close duplicate open issues.');
$clearIssueClient = new FakeGitHubAutomationClient();
$clearIssueClient->openIssues = [['number' => 10, 'title' => 'wp-core-base dependency source failures']];
SyncReport::syncIssue($clearIssueClient, SyncReport::build([], []), null);
$assert(count($clearIssueClient->closedIssues) === 1 && (int) $clearIssueClient->closedIssues[0]['number'] === 10, 'Expected sync-report issue sync to close stale failure issues after recovery.');

$branchGuardRoot = sys_get_temp_dir() . '/wporg-branch-guard-' . bin2hex(random_bytes(4));
mkdir($branchGuardRoot . '/.wp-core-base/build/leftover', 0777, true);
file_put_contents($branchGuardRoot . '/.wp-core-base/build/leftover/temp.txt', "leftover\n");
$fakeGitRunner = new FakeGitRunner();
$fakeGitRunner->localBranches['codex/test-update'] = 'old-local-sha';
$fakeGitRunner->remoteBranches['codex/test-update'] = 'old-remote-sha';
$guard = new BranchRollbackGuard($branchGuardRoot, $fakeGitRunner);
$guard->begin();
$guard->trackBranch('codex/test-update');
$guard->trackCleanupPath($branchGuardRoot . '/.wp-core-base/build/leftover');
$fakeGitRunner->currentBranch = 'codex/test-update';
$fakeGitRunner->currentRevision = 'new-sha';
$fakeGitRunner->localBranches['codex/test-update'] = 'new-sha';
$fakeGitRunner->remoteBranches['codex/test-update'] = 'new-sha';
$fakeGitRunner->clean = false;
$rollbackRaised = false;

try {
    $guard->rollback(new RuntimeException('Simulated failure after branch mutation.'));
} catch (RuntimeException $exception) {
    $rollbackRaised = str_contains($exception->getMessage(), 'Simulated failure');
}

$assert($rollbackRaised, 'Expected branch rollback guard to rethrow the original failure after restoring branch state.');
$assert($fakeGitRunner->currentBranch === 'main', 'Expected branch rollback guard to restore the original checked-out branch.');
$assert(($fakeGitRunner->localBranches['codex/test-update'] ?? null) === 'old-local-sha', 'Expected branch rollback guard to restore the local automation branch revision.');
$assert(($fakeGitRunner->remoteBranches['codex/test-update'] ?? null) === 'old-remote-sha', 'Expected branch rollback guard to restore the remote automation branch revision.');
$assert(! file_exists($branchGuardRoot . '/.wp-core-base/build/leftover/temp.txt'), 'Expected branch rollback guard to clean tool-created untracked residue.');
$assert(in_array('clean-untracked', $fakeGitRunner->actions, true), 'Expected branch rollback guard to clean untracked repository files during rollback.');

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
$assert($degradedStatus['exit_code'] === 0, 'Expected degraded pr-blocker status to preserve the current fail-open exit code.');

$premiumSingleConfig = Config::fromArray($repoRoot, [
    'profile' => 'content-only',
    'paths' => [
        'content_root' => 'cms',
        'plugins_root' => 'cms/plugins',
        'themes_root' => 'cms/themes',
        'mu_plugins_root' => 'cms/mu-plugins',
    ],
    'core' => ['enabled' => false, 'mode' => 'external'],
    'dependencies' => [
        [
            'name' => 'Premium Plugin',
            'slug' => 'premium-plugin',
            'kind' => 'plugin',
            'management' => 'managed',
            'source' => 'premium',
            'path' => 'cms/plugins/premium-plugin',
            'main_file' => 'premium-plugin.php',
            'version' => '1.0.0',
            'checksum' => 'sha256:test',
            'source_config' => ['provider' => 'example-vendor'],
            'policy' => ['class' => 'managed-premium', 'allow_runtime_paths' => [], 'sanitize_paths' => [], 'sanitize_files' => []],
        ],
    ],
]);
$assert($premiumSingleConfig->dependencyByKey('plugin:premium:example-vendor:premium-plugin')['source_config']['provider'] === 'example-vendor', 'Expected provider-aware premium component keys to resolve directly.');
$assert($premiumSingleConfig->dependencyByKey('plugin:premium:premium-plugin')['source_config']['provider'] === 'example-vendor', 'Expected legacy premium component keys to remain readable during migration.');
$premiumAmbiguousConfig = Config::fromArray($repoRoot, [
    'profile' => 'content-only',
    'paths' => [
        'content_root' => 'cms',
        'plugins_root' => 'cms/plugins',
        'themes_root' => 'cms/themes',
        'mu_plugins_root' => 'cms/mu-plugins',
    ],
    'core' => ['enabled' => false, 'mode' => 'external'],
    'dependencies' => [
        [
            'name' => 'Premium Plugin A',
            'slug' => 'shared-plugin',
            'kind' => 'plugin',
            'management' => 'managed',
            'source' => 'premium',
            'path' => 'cms/plugins/shared-plugin-a',
            'main_file' => 'shared-plugin.php',
            'version' => '1.0.0',
            'checksum' => 'sha256:test-a',
            'source_config' => ['provider' => 'vendor-a'],
            'policy' => ['class' => 'managed-premium', 'allow_runtime_paths' => [], 'sanitize_paths' => [], 'sanitize_files' => []],
        ],
        [
            'name' => 'Premium Plugin B',
            'slug' => 'shared-plugin',
            'kind' => 'plugin',
            'management' => 'managed',
            'source' => 'premium',
            'path' => 'cms/plugins/shared-plugin-b',
            'main_file' => 'shared-plugin.php',
            'version' => '1.0.0',
            'checksum' => 'sha256:test-b',
            'source_config' => ['provider' => 'vendor-b'],
            'policy' => ['class' => 'managed-premium', 'allow_runtime_paths' => [], 'sanitize_paths' => [], 'sanitize_files' => []],
        ],
    ],
]);
$ambiguousLegacyPremiumKey = false;

try {
    $premiumAmbiguousConfig->dependencyByKey('plugin:premium:shared-plugin');
} catch (RuntimeException $exception) {
    $ambiguousLegacyPremiumKey = str_contains($exception->getMessage(), 'ambiguous');
}

$assert($ambiguousLegacyPremiumKey, 'Expected legacy premium component keys to become ambiguous once multiple providers share the same slug.');
$credentialsStore = new PremiumCredentialsStore(json_encode([
    'plugin:premium:premium-plugin' => ['license_key' => 'legacy-secret'],
], JSON_THROW_ON_ERROR));
$resolvedCredentials = $credentialsStore->credentialsFor([
    'component_key' => 'plugin:premium:example-vendor:premium-plugin',
    'kind' => 'plugin',
    'source' => 'premium',
    'slug' => 'premium-plugin',
    'source_config' => ['provider' => 'example-vendor'],
]);
$assert(($resolvedCredentials['license_key'] ?? null) === 'legacy-secret', 'Expected premium credentials lookup to fall back to legacy premium keys during migration.');
$redacted = OutputRedactor::redact('Authorization: Bearer very-secret-token https://user:pass@example.com/path');
$assert(! str_contains($redacted, 'very-secret-token'), 'Expected output redaction to scrub bearer tokens.');
$assert(! str_contains($redacted, 'user:pass'), 'Expected output redaction to scrub basic-auth URL credentials.');
$benignUrlRedaction = OutputRedactor::redact('See https://wordpress.org/plugins/example-plugin/ for details.');
$assert(str_contains($benignUrlRedaction, 'https://wordpress.org/plugins/example-plugin/'), 'Expected benign HTTPS URLs to remain visible in diagnostics.');
$securityConfig = Config::fromArray($repoRoot, [
    'profile' => 'content-only',
    'paths' => [
        'content_root' => 'cms',
        'plugins_root' => 'cms/plugins',
        'themes_root' => 'cms/themes',
        'mu_plugins_root' => 'cms/mu-plugins',
    ],
    'core' => ['enabled' => false, 'mode' => 'external'],
    'security' => [
        'managed_release_min_age_hours' => 12,
        'github_release_verification' => 'checksum-sidecar-required',
    ],
    'dependencies' => [
        [
            'name' => 'Security Plugin',
            'slug' => 'security-plugin',
            'kind' => 'plugin',
            'management' => 'managed',
            'source' => 'github-release',
            'path' => 'cms/plugins/security-plugin',
            'main_file' => 'security-plugin.php',
            'version' => '1.0.0',
            'checksum' => 'sha256:test',
            'source_config' => [
                'github_repository' => 'owner/security-plugin',
                'github_release_asset_pattern' => 'security-plugin-*.zip',
                'checksum_asset_pattern' => 'security-plugin-*.zip.sha256',
                'verification_mode' => 'inherit',
                'min_release_age_hours' => 6,
            ],
            'policy' => ['class' => 'managed-private', 'allow_runtime_paths' => [], 'sanitize_paths' => [], 'sanitize_files' => []],
        ],
        [
            'name' => 'WordPress Org Plugin',
            'slug' => 'wordpress-org-plugin',
            'kind' => 'plugin',
            'management' => 'managed',
            'source' => 'wordpress.org',
            'path' => 'cms/plugins/wordpress-org-plugin',
            'main_file' => 'wordpress-org-plugin.php',
            'version' => '1.0.0',
            'checksum' => 'sha256:test',
            'policy' => ['class' => 'managed-upstream', 'allow_runtime_paths' => [], 'sanitize_paths' => [], 'sanitize_files' => []],
        ],
    ],
]);
$securityDependency = $securityConfig->dependencyByKey('plugin:github-release:security-plugin');
$assert($securityConfig->managedReleaseMinAgeHours() === 12, 'Expected security.managed_release_min_age_hours to round-trip through config normalization.');
$assert($securityConfig->githubReleaseVerificationMode() === 'checksum-sidecar-required', 'Expected security.github_release_verification to round-trip through config normalization.');
$assert($securityConfig->dependencyMinReleaseAgeHours($securityDependency) === 6, 'Expected dependency source_config.min_release_age_hours to override the repo default.');
$assert($securityConfig->dependencyVerificationMode($securityDependency) === 'checksum-sidecar-required', 'Expected inherit verification mode to resolve to the repo-level GitHub verification default.');
$assert(($securityDependency['source_config']['checksum_asset_pattern'] ?? null) === 'security-plugin-*.zip.sha256', 'Expected checksum sidecar asset patterns to survive config normalization.');
$assert(
    $securityConfig->dependencyVerificationMode($securityConfig->dependencyByKey('plugin:wordpress.org:wordpress-org-plugin')) === 'none',
    'Expected non-GitHub managed dependencies to default to no release checksum verification unless they opt in explicitly.'
);

$verifierReflection = new ReflectionClass(FrameworkReleaseVerifier::class);
$extractChecksum = $verifierReflection->getMethod('extractChecksum');
$extractChecksum->setAccessible(true);
$checksumRejected = false;

try {
    $extractChecksum->invoke(new FrameworkReleaseVerifier($repoRoot), "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa  wrong-file.zip\n", 'wp-core-base-vendor-snapshot.zip');
} catch (RuntimeException $exception) {
    $checksumRejected = str_contains($exception->getMessage(), 'expected');
}

$assert($checksumRejected, 'Expected framework release verification to reject checksum lines bound to the wrong artifact name.');
$checksumFixture = sys_get_temp_dir() . '/wporg-file-checksum-' . bin2hex(random_bytes(4)) . '.txt';
file_put_contents($checksumFixture, "fixture\n");
FileChecksum::assertSha256Matches($checksumFixture, 'sha256:' . hash_file('sha256', $checksumFixture), 'checksum fixture');
$assert(
    FileChecksum::extractSha256ForAsset(str_repeat('a', 64) . "  fixture.zip\n", 'fixture.zip') === str_repeat('a', 64),
    'Expected generic checksum parsing to return the digest bound to the expected asset filename.'
);

$payloadRoot = sys_get_temp_dir() . '/wporg-framework-payload-' . bin2hex(random_bytes(4));
mkdir($payloadRoot, 0777, true);
$repoRuntimeInspector = new RuntimeInspector($config->runtime);
$repoRuntimeInspector->clearPath($repoRoot . '/.wp-core-base/build');
$repoRuntimeInspector->copyPath($repoRoot, $payloadRoot);
(new RuntimeInspector($config->runtime))->clearPath($payloadRoot . '/.git');
$payloadFramework = FrameworkConfig::load($payloadRoot)->withInstalledRelease(
    version: '1.0.1',
    wordPressCoreVersion: '6.9.4',
    managedComponents: $frameworkConfig->baseline['managed_components'],
    managedFiles: [],
    distributionPath: '.'
);
(new FrameworkWriter())->write($payloadFramework);
$payloadTemplatePath = $payloadRoot . '/tools/wporg-updater/templates/downstream-workflow.yml.tpl';
file_put_contents($payloadTemplatePath, str_replace('scheduled update PRs', 'scheduled update PRs from a newer framework release', (string) file_get_contents($payloadTemplatePath)));
$customizedWorkflowPath = $tempScaffoldRoot . '/.github/workflows/wp-core-base-self-update.yml';
file_put_contents($customizedWorkflowPath, (string) file_get_contents($customizedWorkflowPath) . "\n# local customization\n");
$installer = new FrameworkInstaller($tempScaffoldRoot, new RuntimeInspector(Config::load($tempScaffoldRoot)->runtime));
$installResult = $installer->apply($payloadRoot, 'vendor/wp-core-base');
$updatedFramework = FrameworkConfig::load($tempScaffoldRoot);
$assert($updatedFramework->version === '1.0.1', 'Expected framework installer to update the pinned framework version.');
$assert(in_array('.github/workflows/wp-core-base-self-update.yml', $installResult['skipped_files'], true), 'Expected customized framework-managed workflow to be skipped as drift.');
$assert(in_array('.github/workflows/wporg-updates.yml', $installResult['refreshed_files'], true), 'Expected unchanged framework-managed workflow to refresh during framework install.');
$assert(
    $updatedFramework->managedFiles()['.github/workflows/wp-core-base-self-update.yml'] === $scaffoldedFramework->managedFiles()['.github/workflows/wp-core-base-self-update.yml'],
    'Expected skipped managed file checksum to remain pinned to the previous managed version.'
);
$assert(file_exists($tempScaffoldRoot . '/vendor/wp-core-base/.wp-core-base/framework.php'), 'Expected framework installer to replace the vendored framework snapshot.');
$assert(is_executable($tempScaffoldRoot . '/vendor/wp-core-base/bin/wp-core-base'), 'Expected framework installer to preserve the executable wrapper bit.');

$corePayload = json_decode((string) file_get_contents($fixtureDir . '/wp-core-version-check.json'), true, 512, JSON_THROW_ON_ERROR);
$coreOffer = $coreClient->parseLatestStableOffer($corePayload);
$assert($coreOffer['version'] === '6.9.4', 'Expected latest stable core offer to be 6.9.4 in fixture.');

$coreRelease = $coreClient->findReleaseAnnouncementInFeed((string) file_get_contents($fixtureDir . '/wp-release-feed.xml'), '6.9.4');
$assert($coreRelease['release_url'] === 'https://wordpress.org/news/2026/03/wordpress-6-9-4-release/', 'Expected release feed lookup to find the core announcement URL.');
$assert(str_contains($coreRelease['release_text'], 'security'), 'Expected release summary to include security context.');

$coreMetadata = PrBodyRenderer::extractMetadata($renderer->renderCoreUpdate(
    currentVersion: '6.9.3',
    targetVersion: '6.9.4',
    releaseScope: 'patch',
    releaseAt: '2026-03-11T15:34:58+00:00',
    labels: ['component:wordpress-core', 'release:patch', 'type:security-bugfix'],
    releaseUrl: $coreRelease['release_url'],
    downloadUrl: 'https://downloads.wordpress.org/release/wordpress-6.9.4.zip',
    releaseHtml: $coreRelease['release_html'],
    metadata: [
        'kind' => 'core',
        'slug' => 'wordpress-core',
        'target_version' => '6.9.4',
        'release_at' => '2026-03-11T15:34:58+00:00',
        'scope' => 'patch',
        'branch' => 'codex/wordpress-core-6-9-4',
        'blocked_by' => [],
    ],
));
$assert(is_array($coreMetadata) && $coreMetadata['kind'] === 'core', 'Expected core PR body metadata round-trip to work.');

$frameworkMetadata = PrBodyRenderer::extractMetadata($renderer->renderFrameworkUpdate(
    currentVersion: '1.0.0',
    targetVersion: '1.0.1',
    releaseScope: 'patch',
    releaseAt: '2026-03-22T10:00:00+00:00',
    labels: ['automation:framework-update', 'component:framework', 'release:patch'],
    sourceRepository: 'MatthiasReinholz/wp-core-base',
    releaseUrl: 'https://github.com/MatthiasReinholz/wp-core-base/releases/tag/v1.0.1',
    currentBaseline: '6.9.4',
    targetBaseline: '6.9.4',
    notesSections: [
        'Summary' => 'Patch release.',
        'Downstream Impact' => 'Safe update.',
        'Migration Notes' => 'None.',
        'Bundled Baseline' => 'WordPress core 6.9.4',
    ],
    skippedManagedFiles: [],
    metadata: [
        'component_key' => 'framework:wp-core-base',
        'slug' => 'wp-core-base',
        'target_version' => '1.0.1',
        'release_at' => '2026-03-22T10:00:00+00:00',
        'scope' => 'patch',
        'branch' => 'codex/framework-1-0-1',
        'blocked_by' => [],
    ],
));
$assert(is_array($frameworkMetadata) && $frameworkMetadata['slug'] === 'wp-core-base', 'Expected framework PR metadata to include a slug for blocker compatibility.');

$authoringRoot = sys_get_temp_dir() . '/wporg-authoring-' . bin2hex(random_bytes(4));
mkdir($authoringRoot . '/cms/plugins/project-plugin', 0777, true);
mkdir($authoringRoot . '/cms/themes/project-theme', 0777, true);
mkdir($authoringRoot . '/cms/mu-plugins', 0777, true);
mkdir($authoringRoot . '/cms/languages', 0777, true);
$writeManifest($authoringRoot);

file_put_contents(
    $authoringRoot . '/cms/plugins/project-plugin/project-plugin.php',
    "<?php\n/*\nPlugin Name: Project Plugin\nVersion: 1.0.0\n*/\n"
);
file_put_contents(
    $authoringRoot . '/cms/themes/project-theme/style.css',
    "/*\nTheme Name: Project Theme\nVersion: 2.0.0\n*/\n"
);
file_put_contents(
    $authoringRoot . '/cms/mu-plugins/bootstrap.php',
    "<?php\n/*\nPlugin Name: Project Bootstrap\nVersion: 0.1.0\n*/\n"
);

$authoringConfig = Config::load($authoringRoot);
$authoringService = new DependencyAuthoringService(
    config: $authoringConfig,
    metadataResolver: new DependencyMetadataResolver(),
    runtimeInspector: new RuntimeInspector($authoringConfig->runtime),
    manifestWriter: new ManifestWriter(),
    managedSourceRegistry: new ManagedSourceRegistry(
        new WordPressOrgManagedSource($wpClient, $httpClient),
        new GitHubReleaseManagedSource($gitHubReleaseClient),
        new ExamplePremiumManagedSource($httpClient, $premiumCredentialsStore),
    ),
    adminGovernanceExporter: new AdminGovernanceExporter(new RuntimeInspector($authoringConfig->runtime)),
);

$addedPlugin = $authoringService->addDependency([
    'source' => 'local',
    'kind' => 'plugin',
    'path' => 'cms/plugins/project-plugin',
]);
$assert($addedPlugin['component_key'] === 'plugin:local:project-plugin', 'Expected local plugin add to derive the component key.');
$assert($addedPlugin['main_file'] === 'project-plugin.php', 'Expected local plugin add to infer the plugin main file.');
$assert($addedPlugin['version'] === '1.0.0', 'Expected local plugin add to infer the plugin version.');

$addedTheme = $authoringService->addDependency([
    'source' => 'local',
    'kind' => 'theme',
    'path' => 'cms/themes/project-theme',
]);
$assert($addedTheme['main_file'] === 'style.css', 'Expected local theme add to use style.css as the main file.');

$addedMuFile = $authoringService->addDependency([
    'source' => 'local',
    'kind' => 'mu-plugin-file',
    'path' => 'cms/mu-plugins/bootstrap.php',
]);
$assert($addedMuFile['main_file'] === null, 'Expected MU plugin files to omit main_file.');
$assert($addedMuFile['version'] === '0.1.0', 'Expected MU plugin files to infer the version header.');

$addedRuntimeDirectory = $authoringService->addDependency([
    'source' => 'local',
    'kind' => 'runtime-directory',
    'path' => 'cms/languages',
]);
$assert($addedRuntimeDirectory['name'] === 'Languages', 'Expected runtime-directory add to derive a display name from the path.');

$listOutput = $authoringService->renderDependencyList();
$assert(! str_contains($listOutput, 'MANAGED'), 'Expected empty management groups to be omitted from list output.');
$assert(str_contains($listOutput, 'LOCAL'), 'Expected list output to group local dependencies.');
$assert(str_contains($listOutput, 'project-plugin'), 'Expected list output to include added dependencies.');

$addHelp = CommandHelp::render(
    'add-dependency',
    'vendor/wp-core-base/bin/wp-core-base',
    'php vendor/wp-core-base/tools/wporg-updater/bin/wporg-updater.php'
);
$assert(str_contains($addHelp, '--replace'), 'Expected add-dependency help to document --replace.');
$assert(str_contains($addHelp, '--archive-subdir'), 'Expected add-dependency help to document --archive-subdir.');
$assert(str_contains($addHelp, '--plan'), 'Expected add-dependency help to document preview mode.');
$assert(str_contains($addHelp, '--private'), 'Expected add-dependency help to document private GitHub onboarding.');
$assert(str_contains($addHelp, '--provider=KEY'), 'Expected add-dependency help to document the generic premium provider flag.');
$assert(str_contains($addHelp, 'scaffold-premium-provider --repo-root=. --provider=example-vendor'), 'Expected add-dependency help to point users at the premium provider scaffold command.');

$adoptHelp = CommandHelp::render(
    'adopt-dependency',
    'vendor/wp-core-base/bin/wp-core-base',
    'php vendor/wp-core-base/tools/wporg-updater/bin/wporg-updater.php'
);
$assert(str_contains($adoptHelp, '--preserve-version'), 'Expected adopt-dependency help to document version-preserving adoption.');
$assert(str_contains($adoptHelp, 'atomic'), 'Expected adopt-dependency help to explain the atomic single-dependency workflow.');
$assert(str_contains($adoptHelp, '--source=premium --provider=example-vendor'), 'Expected adopt-dependency help to show the registered premium source example.');

$premiumScaffoldHelp = CommandHelp::render(
    'scaffold-premium-provider',
    'vendor/wp-core-base/bin/wp-core-base',
    'php vendor/wp-core-base/tools/wporg-updater/bin/wporg-updater.php'
);
$assert(str_contains($premiumScaffoldHelp, '.wp-core-base/premium-providers.php'), 'Expected premium provider scaffold help to mention the downstream registry.');

$premiumProviderRoot = sys_get_temp_dir() . '/wporg-premium-provider-' . bin2hex(random_bytes(4));
mkdir($premiumProviderRoot, 0777, true);
$premiumProviderScaffold = new PremiumProviderScaffolder($repoRoot, $premiumProviderRoot);
$premiumProviderResult = $premiumProviderScaffold->scaffold('example-vendor');
$assert(is_file($premiumProviderResult['registry_path']), 'Expected premium provider scaffold to create the registry file.');
$assert(is_file($premiumProviderRoot . '/' . $premiumProviderResult['path']), 'Expected premium provider scaffold to create the provider class file.');
$premiumProviderRegistry = PremiumProviderRegistry::load($premiumProviderRoot);
$assert($premiumProviderRegistry->hasProvider('example-vendor'), 'Expected premium provider registry to contain the scaffolded provider.');
$premiumProviderSources = $premiumProviderRegistry->instantiate(new HttpClient(), new PremiumCredentialsStore('{}'));
$assert(isset($premiumProviderSources['example-vendor']), 'Expected premium provider registry to instantiate the scaffolded provider class.');
$assert($premiumProviderSources['example-vendor']->key() === 'example-vendor', 'Expected scaffolded premium provider key to match the registry key.');

$locatorRoot = sys_get_temp_dir() . '/wporg-authoring-locator-' . bin2hex(random_bytes(4));
mkdir($locatorRoot . '/example-companion', 0777, true);
file_put_contents($locatorRoot . '/example-companion/example-companion.php', "<?php\n/*\nPlugin Name: Example Companion\nVersion: 2.4.0\n*/\n");
file_put_contents($locatorRoot . '/README.txt', "top-level readme\n");
$locatedPayload = ExtractedPayloadLocator::locateForAuthoring(
    $locatorRoot,
    '',
    'example-companion',
    'plugin',
    new DependencyMetadataResolver()
);
$assert(
    str_replace('\\', '/', $locatedPayload) === str_replace('\\', '/', $locatorRoot . '/example-companion'),
    'Expected archive payload selection to prefer the slug directory over the broader extract root when both are technically valid.'
);

$managedArchivePath = sys_get_temp_dir() . '/wporg-authoring-managed-' . bin2hex(random_bytes(4)) . '.zip';
$createPluginArchive($managedArchivePath, 'release-package', 'adopt-me', '2.3.4');
$fakeWordPressOrgSource = new class implements WordPressOrgSource
{
    public function fetchComponentInfo(string $kind, string $slug): array
    {
        return [
            'name' => 'Adopt Me',
            'version' => '2.3.4',
        ];
    }

    public function latestVersion(string $kind, array $info): string
    {
        return (string) $info['version'];
    }

    public function downloadUrlForVersion(string $kind, array $info, string $version): string
    {
        return 'https://downloads.wordpress.org/plugin/adopt-me.' . $version . '.zip';
    }
};
$fakeGitHubReleaseSource = new class implements GitHubReleaseSource
{
    public function fetchStableReleases(array $dependency): array
    {
        throw new RuntimeException('Not used in this test.');
    }

    public function latestVersion(array $release, array $dependency): string
    {
        throw new RuntimeException('Not used in this test.');
    }

    public function downloadReleaseToFile(array $release, array $dependency, string $destination): void
    {
        throw new RuntimeException('Not used in this test.');
    }
};
$fakeArchiveDownloader = new class($managedArchivePath) implements ArchiveDownloader
{
    public function __construct(private readonly string $archivePath)
    {
    }

    public function downloadToFile(string $url, string $destination, array $headers = []): void
    {
        if (! copy($this->archivePath, $destination)) {
            throw new RuntimeException('Failed to copy archive fixture.');
        }
    }
};

$managedPlanRoot = sys_get_temp_dir() . '/wporg-authoring-plan-' . bin2hex(random_bytes(4));
mkdir($managedPlanRoot . '/cms/plugins', 0777, true);
$writeManifest($managedPlanRoot);
$managedPlanConfig = Config::load($managedPlanRoot);
$managedPlanService = new DependencyAuthoringService(
    config: $managedPlanConfig,
    metadataResolver: new DependencyMetadataResolver(),
    runtimeInspector: new RuntimeInspector($managedPlanConfig->runtime),
    manifestWriter: new ManifestWriter(),
    managedSourceRegistry: $makeManagedSourceRegistry($fakeWordPressOrgSource, $fakeGitHubReleaseSource, $fakeArchiveDownloader),
    adminGovernanceExporter: new AdminGovernanceExporter(new RuntimeInspector($managedPlanConfig->runtime)),
);
$managedPlan = $managedPlanService->planAddDependency([
    'source' => 'wordpress.org',
    'kind' => 'plugin',
    'slug' => 'adopt-me',
]);
$assert($managedPlan['selected_version'] === '2.3.4', 'Expected add-dependency --plan to resolve the selected upstream version.');
$assert($managedPlan['target_path'] === 'cms/plugins/adopt-me', 'Expected add-dependency --plan to resolve the default target path.');
$assert($managedPlan['would_replace'] === false, 'Expected add-dependency --plan to detect when no replacement is needed.');
$assert(str_contains((string) $managedPlan['source_reference'], 'downloads.wordpress.org/plugin/adopt-me.2.3.4.zip'), 'Expected add-dependency --plan to report the resolved upstream source.');

$premiumConfig = Config::fromArray($managedPlanRoot, [
    'profile' => 'content-only',
    'paths' => [
        'content_root' => 'cms',
        'plugins_root' => 'cms/plugins',
        'themes_root' => 'cms/themes',
        'mu_plugins_root' => 'cms/mu-plugins',
    ],
    'core' => [
        'mode' => 'external',
        'enabled' => false,
    ],
    'runtime' => $runtimeDefaults,
    'github' => [
        'api_base' => 'https://api.github.com',
    ],
    'automation' => [
        'base_branch' => null,
        'dry_run' => false,
        'managed_kinds' => ['plugin', 'theme'],
    ],
    'dependencies' => [[
        'name' => 'Example Premium Plugin',
        'slug' => 'example-premium-plugin',
        'kind' => 'plugin',
        'management' => 'managed',
        'source' => 'premium',
        'path' => 'cms/plugins/example-premium-plugin',
        'main_file' => 'example-premium-plugin.php',
        'version' => '6.3.0',
        'checksum' => str_repeat('a', 64),
        'archive_subdir' => '',
        'extra_labels' => [],
        'source_config' => [
            'github_repository' => null,
            'github_release_asset_pattern' => null,
            'github_token_env' => null,
            'credential_key' => null,
            'provider' => 'example-vendor',
            'provider_product_id' => null,
        ],
        'policy' => [
            'class' => 'managed-premium',
            'allow_runtime_paths' => [],
            'strip_paths' => [],
            'strip_files' => [],
            'sanitize_paths' => [],
            'sanitize_files' => [],
        ],
    ]],
], $managedPlanRoot . '/.wp-core-base/manifest.php');
$premiumDependency = $premiumConfig->dependencyByKey('plugin:premium:example-vendor:example-premium-plugin');
$assert($premiumDependency['source_config']['provider'] === 'example-vendor', 'Expected generic premium dependencies to retain provider metadata.');
$assert(
    $makeManagedSourceRegistry($fakeWordPressOrgSource, $fakeGitHubReleaseSource, $fakeArchiveDownloader)->for($premiumDependency)->key() === 'example-vendor',
    'Expected the managed source registry to route generic premium dependencies to the provider adapter.'
);
$premiumSourceDetails = (new ExamplePremiumManagedSource(new HttpClient(), new PremiumCredentialsStore('{}')))->releaseDataForVersion(
    $premiumDependency,
    [
        'latest_version' => '6.3.0',
        'latest_release_at' => gmdate(DATE_ATOM),
        'payload' => ['download_url' => 'https://example.com/example-vendor.zip'],
    ],
    '6.3.0',
    gmdate(DATE_ATOM)
);
$assert(
    ((array) $premiumSourceDetails['source_details'])[0]['value'] === '`premium` provider `example-vendor`',
    'Expected generic premium release details to describe the registered premium provider contract.'
);
$assert(
    count((new ExamplePremiumManagedSource(new HttpClient(), new PremiumCredentialsStore('{}')))->hostPolicyWarnings()) === 2,
    'Expected premium provider host-policy diagnostics to warn when API and download allowlists are not declared.'
);

$supportListingRejected = false;
try {
    $supportClient->parseSupportListing('<html><body><a class="bbp-topic-permalink" href="https://example.com/offsite-topic">Bad Topic</a></body></html>');
} catch (RuntimeException $exception) {
    $supportListingRejected = str_contains($exception->getMessage(), 'wordpress.org/support');
}
$assert($supportListingRejected, 'Expected support topic parsing to reject offsite topic URLs.');

$premiumDuplicateRoot = sys_get_temp_dir() . '/wporg-premium-duplicate-' . bin2hex(random_bytes(4));
mkdir($premiumDuplicateRoot . '/cms/plugins/example-premium-plugin', 0777, true);
file_put_contents(
    $premiumDuplicateRoot . '/cms/plugins/example-premium-plugin/example-premium-plugin.php',
    "<?php\n/*\nPlugin Name: Example Premium Plugin\nVersion: 6.3.0\n*/\n"
);
$writeManifest($premiumDuplicateRoot, [[
    'name' => 'Example Premium Plugin',
    'slug' => 'example-premium-plugin',
    'kind' => 'plugin',
    'management' => 'managed',
    'source' => 'premium',
    'path' => 'cms/plugins/example-premium-plugin',
    'main_file' => 'example-premium-plugin.php',
    'version' => '6.3.0',
    'checksum' => str_repeat('b', 64),
    'archive_subdir' => '',
    'extra_labels' => [],
    'source_config' => [
        'github_repository' => null,
        'github_release_asset_pattern' => null,
        'github_token_env' => null,
        'credential_key' => null,
        'provider' => 'example-vendor',
        'provider_product_id' => null,
    ],
    'policy' => [
        'class' => 'managed-premium',
        'allow_runtime_paths' => [],
        'sanitize_paths' => [],
        'sanitize_files' => [],
    ],
]]);
$premiumDuplicateConfig = Config::load($premiumDuplicateRoot);
$premiumDuplicateService = new DependencyAuthoringService(
    config: $premiumDuplicateConfig,
    metadataResolver: new DependencyMetadataResolver(),
    runtimeInspector: new RuntimeInspector($premiumDuplicateConfig->runtime),
    manifestWriter: new ManifestWriter(),
    managedSourceRegistry: $makeManagedSourceRegistry($fakeWordPressOrgSource, $fakeGitHubReleaseSource, $fakeArchiveDownloader),
    adminGovernanceExporter: new AdminGovernanceExporter(new RuntimeInspector($premiumDuplicateConfig->runtime)),
);
$duplicateBlocked = false;

try {
    $premiumDuplicateService->planAddDependency([
        'source' => 'premium',
        'provider' => 'example-vendor',
        'kind' => 'plugin',
        'slug' => 'example-premium-plugin',
    ]);
} catch (RuntimeException $exception) {
    $duplicateBlocked = str_contains($exception->getMessage(), 'Dependency already exists: plugin:premium:example-vendor:example-premium-plugin');
}

$assert($duplicateBlocked, 'Expected premium authoring to reject duplicate provider/slug combinations.');

$dependencyAuthoringReflection = new ReflectionClass(\WpOrgPluginUpdater\DependencyAuthoringService::class);
$matchesIdentity = $dependencyAuthoringReflection->getMethod('dependencyMatchesIdentity');
$matchesIdentity->setAccessible(true);
$assert(
    $matchesIdentity->invoke($premiumDuplicateService, $premiumDuplicateConfig->dependencies()[0], 'plugin', 'premium', 'example-premium-plugin', 'other-vendor') === false,
    'Expected premium dependency identity matching to distinguish providers for the same slug.'
);

$removedLegacy = $premiumDuplicateService->removeDependency([
    'component-key' => 'plugin:premium:example-premium-plugin',
]);
$assert(
    ($removedLegacy['removed']['component_key'] ?? null) === 'plugin:premium:example-vendor:example-premium-plugin',
    'Expected remove-dependency to honor legacy premium component keys during migration.'
);

$adoptRoot = sys_get_temp_dir() . '/wporg-authoring-adopt-' . bin2hex(random_bytes(4));
mkdir($adoptRoot . '/cms/plugins/adopt-me', 0777, true);
file_put_contents(
    $adoptRoot . '/cms/plugins/adopt-me/adopt-me.php',
    "<?php\n/*\nPlugin Name: Adopt Me\nVersion: 2.3.4\n*/\n"
);
$writeManifest($adoptRoot, [[
    'name' => 'Adopt Me',
    'slug' => 'adopt-me',
    'kind' => 'plugin',
    'management' => 'local',
    'source' => 'local',
    'path' => 'cms/plugins/adopt-me',
    'main_file' => 'adopt-me.php',
    'version' => null,
    'checksum' => null,
    'archive_subdir' => '',
    'extra_labels' => [],
    'source_config' => ['github_repository' => null, 'github_release_asset_pattern' => null, 'github_token_env' => null, 'credential_key' => null, 'provider' => null, 'provider_product_id' => null],
    'policy' => ['class' => 'local-owned', 'allow_runtime_paths' => [], 'strip_paths' => [], 'strip_files' => [], 'sanitize_paths' => [], 'sanitize_files' => []],
]]);
$adoptConfig = Config::load($adoptRoot);
$adoptService = new DependencyAuthoringService(
    config: $adoptConfig,
    metadataResolver: new DependencyMetadataResolver(),
    runtimeInspector: new RuntimeInspector($adoptConfig->runtime),
    manifestWriter: new ManifestWriter(),
    managedSourceRegistry: $makeManagedSourceRegistry($fakeWordPressOrgSource, $fakeGitHubReleaseSource, $fakeArchiveDownloader),
    adminGovernanceExporter: new AdminGovernanceExporter(new RuntimeInspector($adoptConfig->runtime)),
);
$adoptPlan = $adoptService->planAdoptDependency([
    'kind' => 'plugin',
    'slug' => 'adopt-me',
    'source' => 'wordpress.org',
    'preserve-version' => true,
    'archive-subdir' => 'adopt-me',
]);
$assert($adoptPlan['selected_version'] === '2.3.4', 'Expected adopt-dependency --plan --preserve-version to resolve the installed local version.');
$assert($adoptPlan['adopted_from'] === 'plugin:local:adopt-me', 'Expected adopt-dependency --plan to identify the source dependency.');
$adoptedDependency = $adoptService->adoptDependency([
    'kind' => 'plugin',
    'slug' => 'adopt-me',
    'source' => 'wordpress.org',
    'preserve-version' => true,
    'archive-subdir' => 'adopt-me',
]);
$assert($adoptedDependency['component_key'] === 'plugin:wordpress.org:adopt-me', 'Expected adopt-dependency to replace the local component key with the managed source.');
$assert($adoptedDependency['version'] === '2.3.4', 'Expected adopt-dependency --preserve-version to keep the installed version.');
$assert(! file_exists($adoptRoot . '/cms/plugins/adopt-me/README.txt'), 'Expected managed sanitation to strip README files before the managed snapshot is applied.');
$adoptedConfig = Config::load($adoptRoot);
$assert($adoptedConfig->dependencyByKey('plugin:wordpress.org:adopt-me')['path'] === 'cms/plugins/adopt-me', 'Expected adopt-dependency to preserve the existing runtime path.');
$localAdoptStillExists = false;
foreach ($adoptedConfig->dependencies() as $dependency) {
    if ($dependency['component_key'] === 'plugin:local:adopt-me') {
        $localAdoptStillExists = true;
        break;
    }
}
$assert(! $localAdoptStillExists, 'Expected adopt-dependency to remove the previous local manifest entry.');

$rollbackArchivePath = sys_get_temp_dir() . '/wporg-authoring-rollback-' . bin2hex(random_bytes(4)) . '.zip';
$createPluginArchive($rollbackArchivePath, 'release-package', 'rollback-plugin', '9.9.9');
$rollbackDownloader = new class($rollbackArchivePath) implements ArchiveDownloader
{
    public function __construct(private readonly string $archivePath)
    {
    }

    public function downloadToFile(string $url, string $destination, array $headers = []): void
    {
        if (! copy($this->archivePath, $destination)) {
            throw new RuntimeException('Failed to copy rollback archive fixture.');
        }
    }
};
$rollbackWpSource = new class implements WordPressOrgSource
{
    public function fetchComponentInfo(string $kind, string $slug): array
    {
        return [
            'name' => 'Rollback Plugin',
            'version' => '9.9.9',
        ];
    }

    public function latestVersion(string $kind, array $info): string
    {
        return (string) $info['version'];
    }

    public function downloadUrlForVersion(string $kind, array $info, string $version): string
    {
        return 'https://downloads.wordpress.org/plugin/rollback-plugin.' . $version . '.zip';
    }
};
$rollbackRoot = sys_get_temp_dir() . '/wporg-authoring-rollback-root-' . bin2hex(random_bytes(4));
mkdir($rollbackRoot . '/cms/plugins/rollback-plugin', 0777, true);
file_put_contents(
    $rollbackRoot . '/cms/plugins/rollback-plugin/rollback-plugin.php',
    "<?php\n/*\nPlugin Name: Rollback Plugin\nVersion: 1.0.0\n*/\n"
);
$writeManifest($rollbackRoot, [[
    'name' => 'Rollback Plugin',
    'slug' => 'rollback-plugin',
    'kind' => 'plugin',
    'management' => 'local',
    'source' => 'local',
    'path' => 'cms/plugins/rollback-plugin',
    'main_file' => 'rollback-plugin.php',
    'version' => '1.0.0',
    'checksum' => null,
    'archive_subdir' => '',
    'extra_labels' => [],
    'source_config' => ['github_repository' => null, 'github_release_asset_pattern' => null, 'github_token_env' => null, 'credential_key' => null, 'provider' => null, 'provider_product_id' => null],
    'policy' => ['class' => 'local-owned', 'allow_runtime_paths' => [], 'strip_paths' => [], 'strip_files' => [], 'sanitize_paths' => [], 'sanitize_files' => []],
]]);
$rollbackGovernanceExporter = new AdminGovernanceExporter(new RuntimeInspector(Config::load($rollbackRoot)->runtime));
$rollbackGovernanceExporter->refresh(Config::load($rollbackRoot));
$rollbackManifestBefore = (string) file_get_contents($rollbackRoot . '/.wp-core-base/manifest.php');
$rollbackGovernancePath = $rollbackRoot . '/' . FrameworkRuntimeFiles::governanceDataPath(Config::load($rollbackRoot));
$rollbackGovernanceBefore = (string) file_get_contents($rollbackGovernancePath);
$failingWriter = new class implements ConfigWriter
{
    public function write(Config $config): void
    {
        (new ManifestWriter())->write($config);
        throw new RuntimeException('Synthetic manifest write failure.');
    }
};
$rollbackConfig = Config::load($rollbackRoot);
$rollbackService = new DependencyAuthoringService(
    config: $rollbackConfig,
    metadataResolver: new DependencyMetadataResolver(),
    runtimeInspector: new RuntimeInspector($rollbackConfig->runtime),
    manifestWriter: $failingWriter,
    managedSourceRegistry: $makeManagedSourceRegistry($rollbackWpSource, $fakeGitHubReleaseSource, $rollbackDownloader),
    adminGovernanceExporter: $rollbackGovernanceExporter,
);
$rollbackTriggered = false;

try {
    $rollbackService->adoptDependency([
        'kind' => 'plugin',
        'slug' => 'rollback-plugin',
        'source' => 'wordpress.org',
        'preserve-version' => true,
        'archive-subdir' => 'rollback-plugin',
    ]);
} catch (RuntimeException $exception) {
    $rollbackTriggered = str_contains($exception->getMessage(), 'Synthetic manifest write failure.');
}

$assert($rollbackTriggered, 'Expected adopt-dependency to bubble manifest write failures.');
$restoredPlugin = (string) file_get_contents($rollbackRoot . '/cms/plugins/rollback-plugin/rollback-plugin.php');
$assert(str_contains($restoredPlugin, 'Version: 1.0.0'), 'Expected adopt-dependency to restore the original runtime tree when manifest writing fails.');
$rollbackConfigAfter = Config::load($rollbackRoot);
$assert($rollbackConfigAfter->dependencyByKey('plugin:local:rollback-plugin')['version'] === '1.0.0', 'Expected rollback to leave the original local manifest entry intact.');
$assert(
    (string) file_get_contents($rollbackConfigAfter->manifestPath) === $rollbackManifestBefore,
    'Expected adopt-dependency rollback to restore the previous manifest contents after a post-write failure.'
);
$assert(
    (string) file_get_contents($rollbackGovernancePath) === $rollbackGovernanceBefore,
    'Expected adopt-dependency rollback to preserve the previous admin governance file when manifest persistence fails after writing.'
);
$rollbackManagedMissing = true;
foreach ($rollbackConfigAfter->dependencies() as $dependency) {
    if ($dependency['component_key'] === 'plugin:wordpress.org:rollback-plugin') {
        $rollbackManagedMissing = false;
        break;
    }
}
$assert($rollbackManagedMissing, 'Expected rollback to avoid persisting a managed manifest entry after failure.');

file_put_contents(
    $authoringRoot . '/cms/plugins/project-plugin/project-plugin.php',
    "<?php\n/*\nPlugin Name: Project Plugin\nVersion: 1.1.0\n*/\n"
);
$replacedPlugin = $authoringService->addDependency([
    'source' => 'local',
    'kind' => 'plugin',
    'path' => 'cms/plugins/project-plugin',
    'force' => true,
]);
$assert($replacedPlugin['version'] === '1.1.0', 'Expected --force to replace an existing manifest entry rather than appending a duplicate.');
$authoringReloaded = Config::load($authoringRoot);
$pluginEntries = array_values(array_filter(
    $authoringReloaded->dependencies(),
    static fn (array $dependency): bool => $dependency['component_key'] === 'plugin:local:project-plugin'
));
$assert(count($pluginEntries) === 1, 'Expected --force replacement to keep only one manifest entry for the same component.');

$removedTheme = $authoringService->removeDependency([
    'slug' => 'project-theme',
    'kind' => 'theme',
]);
$assert($removedTheme['deleted_path'] === false, 'Expected manifest-only dependency removal by default.');
$assert(file_exists($authoringRoot . '/cms/themes/project-theme/style.css'), 'Expected manifest-only removal to leave the runtime path intact.');

$removedPlugin = $authoringService->removeDependency([
    'component-key' => 'plugin:local:project-plugin',
    'delete-path' => true,
]);
$assert($removedPlugin['deleted_path'] === true, 'Expected remove-dependency --delete-path to report the path deletion.');
$assert(! file_exists($authoringRoot . '/cms/plugins/project-plugin'), 'Expected remove-dependency --delete-path to delete the runtime path.');

$ambiguousRemoveRoot = sys_get_temp_dir() . '/wporg-authoring-remove-' . bin2hex(random_bytes(4));
mkdir($ambiguousRemoveRoot . '/cms/plugins/shared-plugin', 0777, true);
$writeManifest($ambiguousRemoveRoot, [
    [
        'name' => 'Shared Plugin Local',
        'slug' => 'shared-plugin',
        'kind' => 'plugin',
        'management' => 'local',
        'source' => 'local',
        'path' => 'cms/plugins/shared-plugin',
        'main_file' => 'shared-plugin.php',
        'version' => '1.0.0',
        'checksum' => null,
        'archive_subdir' => '',
        'extra_labels' => [],
        'source_config' => ['github_repository' => null, 'github_release_asset_pattern' => null, 'github_token_env' => null, 'credential_key' => null, 'provider' => null, 'provider_product_id' => null],
        'policy' => ['class' => 'local-owned', 'allow_runtime_paths' => [], 'strip_paths' => [], 'strip_files' => [], 'sanitize_paths' => [], 'sanitize_files' => []],
    ],
    [
        'name' => 'Shared Plugin Managed',
        'slug' => 'shared-plugin',
        'kind' => 'plugin',
        'management' => 'managed',
        'source' => 'wordpress.org',
        'path' => 'cms/plugins/shared-plugin-managed',
        'main_file' => 'shared-plugin.php',
        'version' => '2.0.0',
        'checksum' => 'sha256:test',
        'archive_subdir' => '',
        'extra_labels' => [],
        'source_config' => ['github_repository' => null, 'github_release_asset_pattern' => null, 'github_token_env' => null, 'credential_key' => null, 'provider' => null, 'provider_product_id' => null],
        'policy' => ['class' => 'managed-upstream', 'allow_runtime_paths' => [], 'sanitize_paths' => [], 'sanitize_files' => []],
    ],
]);
$ambiguousAuthoringConfig = Config::load($ambiguousRemoveRoot);
$ambiguousAuthoringService = new DependencyAuthoringService(
    config: $ambiguousAuthoringConfig,
    metadataResolver: new DependencyMetadataResolver(),
    runtimeInspector: new RuntimeInspector($ambiguousAuthoringConfig->runtime),
    manifestWriter: new ManifestWriter(),
    managedSourceRegistry: new ManagedSourceRegistry(
        new WordPressOrgManagedSource($wpClient, $httpClient),
        new GitHubReleaseManagedSource($gitHubReleaseClient),
        new ExamplePremiumManagedSource($httpClient, $premiumCredentialsStore),
    ),
    adminGovernanceExporter: new AdminGovernanceExporter(new RuntimeInspector($ambiguousAuthoringConfig->runtime)),
);
$ambiguousRemoveRejected = false;

try {
    $ambiguousAuthoringService->removeDependency([
        'slug' => 'shared-plugin',
        'kind' => 'plugin',
    ]);
} catch (RuntimeException $exception) {
    $ambiguousRemoveRejected = str_contains($exception->getMessage(), '--source')
        && str_contains($exception->getMessage(), '--component-key');
}

$assert($ambiguousRemoveRejected, 'Expected remove-dependency to reject ambiguous slug/kind matches unless source or component-key is provided.');
$specificRemove = $ambiguousAuthoringService->removeDependency([
    'slug' => 'shared-plugin',
    'kind' => 'plugin',
    'source' => 'local',
]);
$assert($specificRemove['removed']['component_key'] === 'plugin:local:shared-plugin', 'Expected remove-dependency --source to disambiguate matching entries.');

$ambiguousRoot = sys_get_temp_dir() . '/wporg-authoring-ambiguous-' . bin2hex(random_bytes(4));
mkdir($ambiguousRoot . '/plugin', 0777, true);
file_put_contents($ambiguousRoot . '/plugin/first.php', "<?php\n/*\nPlugin Name: First\n*/\n");
file_put_contents($ambiguousRoot . '/plugin/second.php', "<?php\n/*\nPlugin Name: Second\n*/\n");
$ambiguousRejected = false;

try {
    (new DependencyMetadataResolver())->resolveMainFile($ambiguousRoot . '/plugin', 'plugin');
} catch (RuntimeException $exception) {
    $ambiguousRejected = str_contains($exception->getMessage(), '--main-file');
}

$assert($ambiguousRejected, 'Expected ambiguous plugin entrypoints to require --main-file.');
$assert(
    DependencyAuthoringService::defaultGitHubTokenEnv('example-private-plugin') === 'WP_CORE_BASE_GITHUB_TOKEN_EXAMPLE_PRIVATE_PLUGIN',
    'Expected default token env names to normalize plugin slugs.'
);
$assert(
    DependencyAuthoringService::defaultGitHubTokenEnv('', 'owner/private-plugin') === 'WP_CORE_BASE_GITHUB_TOKEN_PRIVATE_PLUGIN',
    'Expected default token env names to fall back to the repository basename.'
);

$interactiveStream = fopen('php://temp', 'r+');
$assert($interactiveStream !== false, 'Expected to create a temp stream for interactive prompter testing.');
$assert(! InteractivePrompter::canPrompt($interactiveStream), 'Expected non-TTY streams to disable interactive prompting.');
fclose($interactiveStream);

$suggestRoot = sys_get_temp_dir() . '/wporg-suggest-authoring-' . bin2hex(random_bytes(4));
mkdir($suggestRoot . '/cms/plugins/custom-plugin', 0777, true);
$writeManifest($suggestRoot);
file_put_contents($suggestRoot . '/cms/plugins/custom-plugin/custom-plugin.php', "<?php\n/*\nPlugin Name: Custom Plugin\n*/\n");
$suggestions = (new ManifestSuggester(Config::load($suggestRoot), 'vendor/wp-core-base/bin/wp-core-base'))->render();
$assert(str_contains($suggestions, 'add-dependency --source=local --kind=plugin --path=cms/plugins/custom-plugin'), 'Expected manifest suggestions to recommend add-dependency commands.');

$wrapperContents = (string) file_get_contents($repoRoot . '/bin/wp-core-base');
$assert(str_contains($wrapperContents, 'brew install php'), 'Expected the shell launcher to include macOS PHP install guidance.');
$assert(str_contains($wrapperContents, 'docs/local-prerequisites.md'), 'Expected the shell launcher to point users at the local prerequisites doc.');

$artifactFixturePath = sys_get_temp_dir() . '/wporg-release-artifact-' . bin2hex(random_bytes(4)) . '.zip';
$artifactBuilder = new FrameworkReleaseArtifactBuilder($repoRoot);
$artifactBuild = $artifactBuilder->build($artifactFixturePath);
$assert(is_file($artifactBuild['artifact']), 'Expected the framework artifact builder to create the vendored snapshot ZIP.');
$assert(is_file($artifactBuild['checksum_file']), 'Expected the framework artifact builder to create the checksum sidecar.');
$artifactExtractRoot = sys_get_temp_dir() . '/wporg-release-artifact-extract-' . bin2hex(random_bytes(4));
mkdir($artifactExtractRoot, 0777, true);
$artifactZip = new ZipArchive();
$assert($artifactZip->open($artifactFixturePath) === true, 'Expected to reopen the built framework artifact.');
ZipExtractor::extractValidated($artifactZip, $artifactExtractRoot);
$artifactZip->close();
$assert(! file_exists($artifactExtractRoot . '/wp-core-base/tools/wporg-updater/.tmp'), 'Expected release artifacts to exclude temp paths.');
$assert(! file_exists($artifactExtractRoot . '/wp-core-base/tools/wporg-updater/tests'), 'Expected release artifacts to exclude framework tests.');
$assert(! file_exists($artifactExtractRoot . '/wp-core-base/.github'), 'Expected release artifacts to exclude upstream workflow definitions.');
$runtimeInspector->clearPath($artifactExtractRoot);
@unlink($artifactFixturePath);
@unlink($artifactBuild['checksum_file']);

$doctorJson = runCommandJson($repoRoot, [
    'php',
    'tools/wporg-updater/bin/wporg-updater.php',
    'doctor',
    '--repo-root=.',
    '--json',
]);
$assert(($doctorJson['status'] ?? null) === 'success', 'Expected doctor --json to report success for the local repository without live GitHub requirements.');
$assert(is_array($doctorJson['messages'] ?? null), 'Expected doctor --json to include structured messages.');

$stageRuntimeJsonOutput = '.wp-core-base/build/runtime-json';
$stageRuntimeJson = runCommandJson($repoRoot, [
    'php',
    'tools/wporg-updater/bin/wporg-updater.php',
    'stage-runtime',
    '--repo-root=.',
    '--output=' . $stageRuntimeJsonOutput,
    '--json',
]);
$assert(($stageRuntimeJson['status'] ?? null) === 'success', 'Expected stage-runtime --json to report success.');
$assert(in_array('wp-content/plugins/woocommerce', (array) ($stageRuntimeJson['staged_paths'] ?? []), true), 'Expected stage-runtime --json to include staged runtime paths.');
$runtimeInspector->clearPath($repoRoot . '/' . $stageRuntimeJsonOutput);

$releaseVerifyJson = runCommandJson($repoRoot, [
    'php',
    'tools/wporg-updater/bin/wporg-updater.php',
    'release-verify',
    '--repo-root=.',
    '--json',
]);
$assert(($releaseVerifyJson['status'] ?? null) === 'success', 'Expected release-verify --json to report success.');
$assert(($releaseVerifyJson['release_tag'] ?? null) === 'v' . $currentFrameworkVersion, 'Expected release-verify --json to report the resolved release tag.');

$managedPlanJson = runCommandJson($managedPlanRoot, [
    'php',
    $repoRoot . '/tools/wporg-updater/bin/wporg-updater.php',
    'add-dependency',
    '--repo-root=.',
    '--source=wordpress.org',
    '--kind=plugin',
    '--slug=contact-form-7',
    '--plan',
    '--json',
]);
$assert(($managedPlanJson['status'] ?? null) === 'success', 'Expected add-dependency --plan --json to report success.');
$assert(($managedPlanJson['operation'] ?? null) === 'add-dependency', 'Expected add-dependency --plan --json to report the operation type.');
$assert(is_string($managedPlanJson['selected_version'] ?? null) && $managedPlanJson['selected_version'] !== '', 'Expected add-dependency --plan --json to report the selected version.');
$assert(str_contains((string) ($managedPlanJson['source_reference'] ?? ''), 'wordpress.org/plugins/contact-form-7'), 'Expected add-dependency --plan --json to report the resolved upstream source.');

$adoptJsonRoot = sys_get_temp_dir() . '/wporg-authoring-adopt-json-' . bin2hex(random_bytes(4));
mkdir($adoptJsonRoot . '/cms/plugins/contact-form-7', 0777, true);
file_put_contents(
    $adoptJsonRoot . '/cms/plugins/contact-form-7/wp-contact-form-7.php',
    "<?php\n/*\nPlugin Name: Contact Form 7\nVersion: 6.1.5\n*/\n"
);
$writeManifest($adoptJsonRoot, [[
    'name' => 'Contact Form 7',
    'slug' => 'contact-form-7',
    'kind' => 'plugin',
    'management' => 'local',
    'source' => 'local',
    'path' => 'cms/plugins/contact-form-7',
    'main_file' => 'wp-contact-form-7.php',
    'version' => null,
    'checksum' => null,
    'archive_subdir' => '',
    'extra_labels' => [],
    'source_config' => ['github_repository' => null, 'github_release_asset_pattern' => null, 'github_token_env' => null, 'credential_key' => null, 'provider' => null, 'provider_product_id' => null],
    'policy' => ['class' => 'local-owned', 'allow_runtime_paths' => [], 'strip_paths' => [], 'strip_files' => [], 'sanitize_paths' => [], 'sanitize_files' => []],
]]);

$adoptPlanJson = runCommandJson($adoptJsonRoot, [
    'php',
    $repoRoot . '/tools/wporg-updater/bin/wporg-updater.php',
    'adopt-dependency',
    '--repo-root=.',
    '--kind=plugin',
    '--slug=contact-form-7',
    '--source=wordpress.org',
    '--preserve-version',
    '--archive-subdir=contact-form-7',
    '--plan',
    '--json',
]);
$assert(($adoptPlanJson['status'] ?? null) === 'success', 'Expected adopt-dependency --plan --json to report success.');
$assert(($adoptPlanJson['operation'] ?? null) === 'adopt-dependency', 'Expected adopt-dependency --plan --json to report the operation type.');
$assert(($adoptPlanJson['adopted_from'] ?? null) === 'plugin:local:contact-form-7', 'Expected adopt-dependency --plan --json to identify the source dependency.');

ZipExtractor::assertSafeEntryName('wordpress/wp-includes/version.php');
$zipTraversalRejected = false;

try {
    ZipExtractor::assertSafeEntryName('../escape.php');
} catch (RuntimeException) {
    $zipTraversalRejected = true;
}

$assert($zipTraversalRejected, 'Expected ZipExtractor to reject path traversal entries.');

$zipBombPath = sys_get_temp_dir() . '/wporg-zip-bomb-' . bin2hex(random_bytes(4)) . '.zip';
$zipBomb = new ZipArchive();
$assert($zipBomb->open($zipBombPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'Expected to create ZIP bomb fixture.');
$zipBomb->addFromString('bomb.txt', str_repeat('A', 1024 * 1024));
$zipBomb->close();
$zipBombRejected = false;
$zipBombReader = new ZipArchive();
$assert($zipBombReader->open($zipBombPath) === true, 'Expected to reopen ZIP bomb fixture.');

try {
    ZipExtractor::extractValidated($zipBombReader, sys_get_temp_dir() . '/wporg-zip-bomb-extract-' . bin2hex(random_bytes(4)));
} catch (RuntimeException $exception) {
    $zipBombRejected = str_contains($exception->getMessage(), 'compression ratio');
}

$zipBombReader->close();
$assert($zipBombRejected, 'Expected ZipExtractor to reject suspicious compression ratios.');

$tempCoreRoot = sys_get_temp_dir() . '/wporg-core-scanner-' . bin2hex(random_bytes(4));
mkdir($tempCoreRoot . '/wp-includes', 0777, true);
file_put_contents($tempCoreRoot . '/wp-includes/version.php', "<?php\n\$wp_version = '6.9.4';\n");
$coreScan = (new CoreScanner())->inspect($tempCoreRoot);
$assert($coreScan['version'] === '6.9.4', 'Expected CoreScanner to parse $wp_version correctly.');

fwrite(STDOUT, "All updater tests passed.\n");

/**
 * @param list<string> $command
 * @return array<string, mixed>
 */
function runCommandJson(string $cwd, array $command): array
{
    $descriptor = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptor, $pipes, $cwd);

    if (! is_resource($process)) {
        throw new RuntimeException(sprintf('Unable to start command: %s', implode(' ', $command)));
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);

    if (! is_string($stdout) || trim($stdout) === '') {
        throw new RuntimeException(sprintf(
            "Command did not produce JSON output in %s: %s\n%s",
            $cwd,
            implode(' ', $command),
            is_string($stderr) ? $stderr : ''
        ));
    }

    $decoded = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);

    if (! is_array($decoded)) {
        throw new RuntimeException(sprintf('Command did not return a JSON object: %s', implode(' ', $command)));
    }

    if ($status !== 0) {
        throw new RuntimeException(sprintf(
            'Command failed unexpectedly: %s (%s)',
            implode(' ', $command),
            $decoded['error'] ?? 'unknown error'
        ));
    }

    return $decoded;
}
