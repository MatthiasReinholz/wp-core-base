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
use WpOrgPluginUpdater\GitLabClient;
use WpOrgPluginUpdater\GitCommandRunner;
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
use WpOrgPluginUpdater\MutationLock;
use WpOrgPluginUpdater\OutputRedactor;
use WpOrgPluginUpdater\PremiumProviderRegistry;
use WpOrgPluginUpdater\PremiumProviderScaffolder;
use WpOrgPluginUpdater\PremiumCredentialsStore;
use WpOrgPluginUpdater\PrBodyRenderer;
use WpOrgPluginUpdater\PullRequestBlocker;
use WpOrgPluginUpdater\ReleaseClassifier;
use WpOrgPluginUpdater\ReleaseSignatureKeyStore;
use WpOrgPluginUpdater\RuntimeHygieneDefaults;
use WpOrgPluginUpdater\RuntimeInspector;
use WpOrgPluginUpdater\RuntimeOwnershipInspector;
use WpOrgPluginUpdater\RuntimeStager;
use WpOrgPluginUpdater\SupportForumClient;
use WpOrgPluginUpdater\SyncReport;
use WpOrgPluginUpdater\TempDirectoryJanitor;
use WpOrgPluginUpdater\ArchiveDownloader;
use WpOrgPluginUpdater\Cli\Handlers\ReleaseAssetInspectionModeHandler;
use WpOrgPluginUpdater\WordPressCoreClient;
use WpOrgPluginUpdater\WordPressOrgManagedSource;
use WpOrgPluginUpdater\WordPressOrgSource;
use WpOrgPluginUpdater\WordPressOrgClient;
use WpOrgPluginUpdater\ZipExtractor;

require dirname(__DIR__) . '/src/Autoload.php';
require __DIR__ . '/integration/workflow_contracts.php';
require __DIR__ . '/integration/upstream_workflow_contracts.php';
require __DIR__ . '/integration/release_contracts.php';
require __DIR__ . '/integration/config_runtime_contracts.php';
require __DIR__ . '/integration/security_framework_contracts.php';
require __DIR__ . '/integration/security_policy_contracts.php';
require __DIR__ . '/integration/dependency_authoring_contracts.php';
require __DIR__ . '/integration/cli_json_contracts.php';
require __DIR__ . '/integration/blocker_states.php';
require __DIR__ . '/integration/followups.php';
require __DIR__ . '/integration/multi_host_contracts.php';
require __DIR__ . '/integration/generic_json_contracts.php';

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

final class ConfigurablePremiumManagedSource extends AbstractPremiumManagedSource
{
    public function key(): string
    {
        return 'configurable-vendor';
    }

    public function fetchCatalog(array $dependency): array
    {
        return [];
    }

    public function releaseDataForVersion(array $dependency, array $catalog, string $targetVersion, string $fallbackReleaseAt): array
    {
        return [];
    }

    public function downloadReleaseToFile(array $dependency, array $releaseData, string $destination): void
    {
    }

    protected function premiumMetadataTimeoutSeconds(): ?int
    {
        return 90;
    }

    protected function premiumMetadataRetryAttempts(): ?int
    {
        return 5;
    }

    protected function premiumMetadataInitialRetryDelayMilliseconds(): ?int
    {
        return 750;
    }

    /**
     * @return array<string, mixed>
     */
    public function metadataOptionsForTest(): array
    {
        return $this->premiumMetadataRequestOptions();
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

    public function listOpenPullRequests(?string $label = null): array
    {
        if ($this->listFailure !== null) {
            throw $this->listFailure;
        }

        if (! is_string($label) || $label === '') {
            return $this->openPullRequests;
        }

        return array_values(array_filter(
            $this->openPullRequests,
            static function (array $pullRequest) use ($label): bool {
                foreach ((array) ($pullRequest['labels'] ?? []) as $labelEntry) {
                    if ((string) ($labelEntry['name'] ?? '') === $label) {
                        return true;
                    }
                }

                return false;
            }
        ));
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

    public function listOpenPullRequests(?string $label = null): array
    {
        if (! is_string($label) || $label === '') {
            return $this->openPullRequests;
        }

        return array_values(array_filter(
            $this->openPullRequests,
            static function (array $pullRequest) use ($label): bool {
                foreach ((array) ($pullRequest['labels'] ?? []) as $labelEntry) {
                    if ((string) ($labelEntry['name'] ?? '') === $label) {
                        return true;
                    }
                }

                return false;
            }
        ));
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

    public function setIssueLabels(int $number, array $labels): void
    {
        $this->labelUpdates[] = ['type' => 'issue', 'number' => $number, 'labels' => $labels];
    }

    public function setPullRequestLabels(int $number, array $labels): void
    {
        $this->labelUpdates[] = ['type' => 'pull_request', 'number' => $number, 'labels' => $labels];
    }

    public function convertToDraft(int $number): void
    {
    }

    public function markReadyForReview(int $number): void
    {
    }
}

/**
 * @param list<string> $command
 * @return array{exit_code:int,stdout:string,stderr:string}
 */
function run_process(string $cwd, array $command): array
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

    return [
        'exit_code' => (int) $status,
        'stdout' => is_string($stdout) ? $stdout : '',
        'stderr' => is_string($stderr) ? $stderr : '',
    ];
}

/**
 * @param list<string> $command
 */
function run_process_or_fail(callable $assert, string $cwd, array $command, string $message): string
{
    $result = run_process($cwd, $command);
    $assert(
        $result['exit_code'] === 0,
        $message . sprintf(" (command: %s, stderr: %s)", implode(' ', $command), trim($result['stderr']))
    );

    return trim($result['stdout']);
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

$premiumMetadataDefaults = (new ExamplePremiumManagedSource(new HttpClient(), new PremiumCredentialsStore('{}')));
$premiumMetadataDefaultsMethod = new ReflectionMethod(AbstractPremiumManagedSource::class, 'premiumMetadataRequestOptions');
$defaultMetadataOptions = $premiumMetadataDefaultsMethod->invoke($premiumMetadataDefaults);
$assert(
    is_array($defaultMetadataOptions) && ($defaultMetadataOptions['max_body_bytes'] ?? null) === 5 * 1024 * 1024,
    'Expected premium metadata request options to enforce default JSON body limits.'
);
$assert(
    ! isset($defaultMetadataOptions['timeout_seconds']) && ! isset($defaultMetadataOptions['retry_attempts']) && ! isset($defaultMetadataOptions['retry_initial_delay_milliseconds']),
    'Expected premium metadata request options to omit retry/timeout overrides by default.'
);

$configurablePremiumSource = new ConfigurablePremiumManagedSource(new HttpClient(), new PremiumCredentialsStore('{}'));
$configurableMetadataOptions = $configurablePremiumSource->metadataOptionsForTest();
$assert(
    ($configurableMetadataOptions['timeout_seconds'] ?? null) === 90,
    'Expected premium metadata timeout override to flow into request options.'
);
$assert(
    ($configurableMetadataOptions['retry_attempts'] ?? null) === 5,
    'Expected premium metadata retry-attempt override to flow into request options.'
);
$assert(
    ($configurableMetadataOptions['retry_initial_delay_milliseconds'] ?? null) === 750,
    'Expected premium metadata retry-delay override to flow into request options.'
);

$runtimeDefaults = [
    'stage_dir' => '.wp-core-base/build/runtime',
    'manifest_mode' => 'strict',
    'validation_mode' => 'source-clean',
    'ownership_roots' => ['cms/plugins', 'cms/themes', 'cms/mu-plugins'],
    'staged_kinds' => ['plugin', 'theme', 'mu-plugin-package', 'mu-plugin-file', 'runtime-file', 'runtime-directory'],
    'validated_kinds' => ['plugin', 'theme', 'mu-plugin-package', 'mu-plugin-file', 'runtime-file', 'runtime-directory'],
    'forbidden_paths' => RuntimeHygieneDefaults::FORBIDDEN_PATHS,
    'forbidden_files' => RuntimeHygieneDefaults::FORBIDDEN_FILES,
    'allow_runtime_paths' => [],
    'strip_paths' => [],
    'strip_files' => [],
    'managed_sanitize_paths' => RuntimeHygieneDefaults::managedSanitizePaths([
        'content_root' => 'cms',
        'plugins_root' => 'cms/plugins',
        'themes_root' => 'cms/themes',
        'mu_plugins_root' => 'cms/mu-plugins',
    ]),
    'managed_sanitize_files' => RuntimeHygieneDefaults::MANAGED_SANITIZE_FILES,
];
$checkoutActionSha = 'actions/checkout@de0fac2e4500dabe0009e67214ff5f5447ce83dd';
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

$writePremiumProvider = static function (string $root, string $provider = 'test-provider', string $version = '2.3.4'): void {
    $providerDirectory = $root . '/.wp-core-base/premium-providers';

    if (! is_dir($providerDirectory)) {
        mkdir($providerDirectory, 0777, true);
    }

    file_put_contents(
        $root . '/.wp-core-base/premium-providers.php',
        "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export([
            $provider => [
                'class' => 'TestPremiumManagedSource',
                'path' => '.wp-core-base/premium-providers/' . $provider . '.php',
            ],
        ], true) . ";\n"
    );
    file_put_contents(
        $providerDirectory . '/' . $provider . '.php',
        <<<PHP
<?php

declare(strict_types=1);

use WpOrgPluginUpdater\AbstractPremiumManagedSource;

final class TestPremiumManagedSource extends AbstractPremiumManagedSource
{
    public function key(): string
    {
        return '{$provider}';
    }

    protected function requiredCredentialFields(): array
    {
        return [];
    }

    public function fetchCatalog(array \$dependency): array
    {
        return [
            'source' => '{$provider}',
            'latest_version' => '{$version}',
            'latest_release_at' => '2026-04-01T00:00:00+00:00',
            'payload' => [
                'download_url' => 'https://example.com/{$provider}.zip',
            ],
        ];
    }

    public function releaseDataForVersion(array \$dependency, array \$catalog, string \$targetVersion, string \$fallbackReleaseAt): array
    {
        return [
            'source' => '{$provider}',
            'version' => \$targetVersion,
            'release_at' => (string) (\$catalog['latest_release_at'] ?? \$fallbackReleaseAt),
            'archive_subdir' => trim((string) (\$dependency['archive_subdir'] ?? ''), '/'),
            'download_url' => 'https://example.com/{$provider}.zip',
            'notes_markup' => '<p>Release notes unavailable.</p>',
            'notes_text' => 'Release notes unavailable.',
            'source_reference' => 'https://example.com/{$provider}',
            'source_details' => [
                ['label' => 'Update contract', 'value' => '`premium` provider `{$provider}`'],
            ],
        ];
    }

    public function downloadReleaseToFile(array \$dependency, array \$releaseData, string \$destination): void
    {
        \$zip = new \ZipArchive();
        \$opened = \$zip->open(\$destination, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        if (\$opened !== true) {
            throw new \RuntimeException(sprintf('Failed to create premium archive fixture: %s', \$destination));
        }

        \$slug = (string) (\$dependency['slug'] ?? 'premium-plan-plugin');
        \$version = (string) (\$releaseData['version'] ?? '{$version}');
        \$base = trim((string) (\$releaseData['archive_subdir'] ?? ''), '/');
        \$base = \$base === '' ? \$slug : \$base;
        \$zip->addEmptyDir(\$base);
        \$zip->addFromString(
            \$base . '/' . \$slug . '.php',
            "<?php\\n/*\\nPlugin Name: " . ucwords(str_replace('-', ' ', \$slug)) . "\\nVersion: " . \$version . "\\n*/\\n"
        );
        \$zip->addFromString(\$base . '/README.txt', "Readme for {\$slug}\\n");
        \$zip->close();
    }
}
PHP
    );
};

$freshnessConfig = Config::fromArray($repoRoot, [
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
    'dependencies' => [[
        'name' => 'Freshness Plugin',
        'slug' => 'freshness-plugin',
        'kind' => 'plugin',
        'management' => 'managed',
        'source' => 'wordpress.org',
        'path' => 'cms/plugins/freshness-plugin',
        'main_file' => 'freshness-plugin.php',
        'version' => '1.0.0',
        'checksum' => 'sha256:' . str_repeat('a', 64),
        'archive_subdir' => '',
        'extra_labels' => [],
        'source_config' => [],
        'policy' => [],
    ]],
]);
$freshnessSource = new class implements ManagedDependencySource
{
    public function key(): string
    {
        return 'wordpress.org';
    }

    public function fetchCatalog(array $dependency): array
    {
        return [
            'latest_version' => '2.0.0',
            'latest_release_at' => '2026-04-01T00:00:00+00:00',
        ];
    }

    public function releaseDataForVersion(array $dependency, array $catalog, string $targetVersion, string $fallbackReleaseAt): array
    {
        throw new RuntimeException('Not used in freshness tests.');
    }

    public function downloadReleaseToFile(array $dependency, array $releaseData, string $destination): void
    {
        throw new RuntimeException('Not used in freshness tests.');
    }

    public function supportsForumSync(array $dependency): bool
    {
        return false;
    }
};
$freshnessService = new DependencyAuthoringService(
    $freshnessConfig,
    new DependencyMetadataResolver(),
    new RuntimeInspector($freshnessConfig->runtime),
    new ManifestWriter(),
    new ManagedSourceRegistry($freshnessSource)
);
$freshnessList = $freshnessService->dependencyList(includeFreshness: true);
$assert(
    ($freshnessList[0]['freshness']['status'] ?? null) === 'outdated'
        && ($freshnessList[0]['freshness']['latest_version'] ?? null) === '2.0.0',
    'Expected dependency freshness reporting to compare manifest versions with source catalogs.'
);

$releaseAssetInspector = new ReleaseAssetInspectionModeHandler(
    $freshnessConfig,
    new HttpClient(),
    'bin/wp-core-base',
    'php tools/wporg-updater/bin/wporg-updater.php',
    false,
    static function (array $payload): never {
        throw new RuntimeException('JSON emission is not used in release asset inspection unit tests.');
    }
);
$releaseInspectorReflection = new ReflectionClass(ReleaseAssetInspectionModeHandler::class);
$githubAssets = $releaseInspectorReflection->getMethod('githubAssets')->invoke($releaseAssetInspector, [
    'assets' => [
        [
            'name' => 'example-plugin.zip',
            'url' => 'https://api.github.com/repos/example/plugin/releases/assets/1',
            'browser_download_url' => 'https://github.com/example/plugin/releases/download/v1.0.0/example-plugin.zip',
            'content_type' => 'application/zip',
            'size' => 123,
        ],
        [
            'name' => 'example-plugin.zip.sha256',
            'url' => 'https://api.github.com/repos/example/plugin/releases/assets/2',
            'browser_download_url' => 'https://github.com/example/plugin/releases/download/v1.0.0/example-plugin.zip.sha256',
            'content_type' => 'text/plain',
            'size' => 91,
        ],
    ],
], '*.zip', '*.sha256');
$assert(
    ($githubAssets[0]['matches_archive_pattern'] ?? null) === true
        && ($githubAssets[0]['matches_checksum_pattern'] ?? null) === false
        && ($githubAssets[1]['matches_archive_pattern'] ?? null) === false
        && ($githubAssets[1]['matches_checksum_pattern'] ?? null) === true,
    'Expected release asset inspection to classify GitHub archive and checksum assets by pattern.'
);
$inspectionResult = $releaseInspectorReflection->getMethod('inspectionResult')->invoke(
    $releaseAssetInspector,
    'github-release',
    'example/plugin',
    '1.0.0',
    gmdate(DATE_ATOM, time() - 7200),
    'v1.0.0',
    'https://github.com/example/plugin/releases/tag/v1.0.0',
    '*.zip',
    '*.sha256',
    $githubAssets
);
$assert(($inspectionResult['archive_asset']['name'] ?? null) === 'example-plugin.zip', 'Expected release asset inspection to select the matching archive asset.');
$assert(($inspectionResult['checksum_asset']['name'] ?? null) === 'example-plugin.zip.sha256', 'Expected release asset inspection to select the matching checksum asset.');
$assert(($inspectionResult['warnings'] ?? null) === [], 'Expected release asset inspection not to warn when both archive and checksum patterns match.');
$warningGithubAssets = $releaseInspectorReflection->getMethod('githubAssets')->invoke($releaseAssetInspector, [
    'assets' => [
        [
            'name' => 'example-plugin.zip',
            'url' => 'https://api.github.com/repos/example/plugin/releases/assets/1',
            'browser_download_url' => 'https://github.com/example/plugin/releases/download/v1.0.0/example-plugin.zip',
            'content_type' => 'application/zip',
            'size' => 123,
        ],
    ],
], 'missing-*.zip', null);
$warningInspectionResult = $releaseInspectorReflection->getMethod('inspectionResult')->invoke(
    $releaseAssetInspector,
    'github-release',
    'example/plugin',
    '1.0.0',
    gmdate(DATE_ATOM),
    'v1.0.0',
    'https://github.com/example/plugin/releases/tag/v1.0.0',
    'missing-*.zip',
    null,
    $warningGithubAssets
);
$assert(
    is_array($warningInspectionResult['warnings'] ?? null)
        && count($warningInspectionResult['warnings']) === 2,
    'Expected release asset inspection to warn when archive or checksum verification cannot be confirmed.'
);

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
$gitLabAutomationClient = new GitLabClient(new HttpClient(), 'example/group-project', 'token');
$gitLabAutomationReflection = new ReflectionClass(GitLabClient::class);
$normalizeGitLabMergeRequest = $gitLabAutomationReflection->getMethod('normalizeMergeRequest');
$normalizedGitLabManagedMergeRequest = $normalizeGitLabMergeRequest->invoke($gitLabAutomationClient, [
    'iid' => 12,
    'title' => 'Update dependency',
    'description' => 'Body',
    'source_branch' => 'codex/update-plugin',
    'target_branch' => 'main',
    'source_project_id' => 101,
    'target_project_id' => 101,
]);
$normalizedGitLabForkMergeRequest = $normalizeGitLabMergeRequest->invoke($gitLabAutomationClient, [
    'iid' => 13,
    'title' => 'Forked change',
    'description' => 'Body',
    'source_branch' => 'fork/update-plugin',
    'target_branch' => 'main',
    'source_project_id' => 101,
    'target_project_id' => 202,
]);
$updaterRepositoryCheckReflection = new ReflectionClass(\WpOrgPluginUpdater\Updater::class);
$isManagedRepositoryPullRequest = $updaterRepositoryCheckReflection->getMethod('isManagedRepositoryPullRequest');
$updaterForRepositoryCheck = $updaterRepositoryCheckReflection->newInstanceWithoutConstructor();
$assert(
    $isManagedRepositoryPullRequest->invoke($updaterForRepositoryCheck, $normalizedGitLabManagedMergeRequest) === true,
    'Expected normalized GitLab merge requests from the same project to satisfy managed same-repository branch checks.'
);
$assert(
    $isManagedRepositoryPullRequest->invoke($updaterForRepositoryCheck, $normalizedGitLabForkMergeRequest) === false,
    'Expected normalized GitLab merge requests from different projects to fail managed same-repository branch checks.'
);
$httpStatusException = new HttpStatusRuntimeException(404, 'Example 404.');
$assert($httpStatusException->status() === 404, 'Expected HTTP status exceptions to retain the structured status code.');

$frameworkConfig = FrameworkConfig::load($repoRoot);
$currentFrameworkVersion = $frameworkConfig->version;
$assert(preg_match('/^\d+\.\d+\.\d+$/', $currentFrameworkVersion) === 1, 'Expected framework metadata to load a valid current framework version.');
$assert($frameworkConfig->distributionPath() === '.', 'Expected upstream framework metadata to point at the repository root.');
$assert($frameworkConfig->releaseSourceProvider() === 'github-release', 'Expected upstream framework metadata to declare a GitHub release source.');
$assert($frameworkConfig->releaseSourceReference() === 'MatthiasReinholz/wp-core-base', 'Expected upstream framework metadata to declare the authoritative release source reference.');
$assert($frameworkConfig->releaseSourceReferenceUrl() === 'https://github.com/MatthiasReinholz/wp-core-base', 'Expected upstream framework metadata to derive the authoritative source URL.');
$assert($frameworkConfig->checksumSignatureAssetName() === 'wp-core-base-vendor-snapshot.zip.sha256.sig', 'Expected framework metadata to derive the checksum-signature asset name.');
$assert(str_ends_with(ReleaseSignatureKeyStore::defaultPublicKeyPath($frameworkConfig), 'tools/wporg-updater/keys/framework-release-public.pem'), 'Expected the default release public key path to resolve inside the framework distribution.');
$legacyFrameworkRoot = sys_get_temp_dir() . '/wporg-framework-legacy-' . bin2hex(random_bytes(4));
mkdir($legacyFrameworkRoot . '/.wp-core-base', 0777, true);
file_put_contents(
    $legacyFrameworkRoot . '/.wp-core-base/framework.php',
    "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export([
        'repository' => 'legacy/example-framework',
        'version' => '1.2.3',
        'release_channel' => 'stable',
        'distribution' => [
            'mode' => 'vendor-snapshot',
            'path' => '.',
            'asset_name' => 'wp-core-base-vendor-snapshot.zip',
        ],
        'baseline' => [
            'wordpress_core' => '6.9.4',
            'managed_components' => [],
        ],
        'scaffold' => [
            'managed_files' => [],
        ],
    ], true) . ";\n"
);
$legacyFrameworkConfig = FrameworkConfig::load($legacyFrameworkRoot);
$assert($legacyFrameworkConfig->releaseSourceProvider() === 'github-release', 'Expected legacy repository-only framework metadata to default to a GitHub release source.');
$assert($legacyFrameworkConfig->releaseSourceReference() === 'legacy/example-framework', 'Expected legacy repository-only framework metadata to reuse the repository as the source reference.');
$agentsDoc = (string) file_get_contents($repoRoot . '/AGENTS.md');
$assert($agentsDoc !== '', 'Expected upstream AGENTS.md to exist.');
$assert(str_contains($agentsDoc, 'adopt-dependency'), 'Expected AGENTS.md to treat adopt-dependency as a preferred authoring command.');
$assert(str_contains($agentsDoc, 'doctor --automation --json'), 'Expected AGENTS.md to preserve automation-contract validation in machine-readable guidance.');
$assert(str_contains($agentsDoc, 'source_config.checksum_asset_pattern'), 'Expected AGENTS.md to document checksum sidecar settings for GitHub release dependencies.');
$assert(str_contains($agentsDoc, 'Do not invent checksum asset patterns'), 'Expected AGENTS.md to warn agents against guessing checksum asset patterns.');
$llmsIndex = (string) file_get_contents($repoRoot . '/llms.txt');
$assert(str_contains($llmsIndex, 'downstream-registered `premium` providers'), 'Expected llms.txt to describe premium providers as a supported managed source.');
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
run_upstream_workflow_contract_tests($assert, $repoRoot, $checkoutActionSha, $setupPhpActionSha);
run_release_contract_tests($assert, $repoRoot, $frameworkConfig, $currentFrameworkVersion);

$configRuntimeContracts = run_config_runtime_contract_tests(
    $assert,
    $repoRoot,
    $runtimeDefaults,
    $longLabel,
    $normalizedLongLabel
);
$config = $configRuntimeContracts['config'];
$runtimeInspector = $configRuntimeContracts['runtimeInspector'];
$gitLabVerificationConfig = Config::fromArray($repoRoot, [
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
    'security' => [
        'managed_release_min_age_hours' => 24,
        'github_release_verification' => 'checksum-sidecar-required',
    ],
    'dependencies' => [
        [
            'name' => 'GitLab Managed Plugin',
            'slug' => 'gitlab-managed-plugin',
            'kind' => 'plugin',
            'management' => 'managed',
            'source' => 'gitlab-release',
            'path' => 'cms/plugins/gitlab-managed-plugin',
            'main_file' => 'gitlab-managed-plugin.php',
            'version' => '1.2.3',
            'checksum' => 'sha256:abc',
            'archive_subdir' => '',
            'extra_labels' => [],
            'source_config' => [
                'gitlab_project' => 'group/gitlab-managed-plugin',
                'gitlab_release_asset_pattern' => '*.zip',
                'gitlab_token_env' => 'GITLAB_PLUGIN_TOKEN',
                'verification_mode' => 'inherit',
                'checksum_asset_pattern' => '*.zip.sha256',
            ],
            'policy' => [
                'class' => 'managed-private',
                'allow_runtime_paths' => [],
                'strip_paths' => [],
                'strip_files' => [],
                'sanitize_paths' => [],
                'sanitize_files' => [],
            ],
        ],
    ],
]);
$assert(
    $gitLabVerificationConfig->dependencyVerificationMode($gitLabVerificationConfig->managedDependencies()[0]) === 'checksum-sidecar-required',
    'Expected gitlab-release dependencies with verification_mode=inherit to reuse the repo-level hosted release verification default.'
);

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
run_workflow_contract_tests(
    $assert,
    $repoRoot,
    $tempScaffoldRoot,
    $checkoutActionSha,
    $setupPhpActionSha,
    $normalizeWorkflowExample
);
run_multi_host_contract_tests(
    $assert,
    $repoRoot,
    $tempScaffoldRoot,
    $normalizeWorkflowExample
);
$scaffoldedFramework = FrameworkConfig::load($tempScaffoldRoot);

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
[$canonicalPrs, $duplicatePrs] = $partitionPullRequestsByTargetVersion->invoke($updaterWithoutConstructor, [
    ['number' => 38, 'planned_target_version' => '0.1.0', 'planned_release_at' => '2026-04-01T00:00:00+00:00', 'updated_at' => '2026-04-02T00:00:00+00:00', 'metadata' => ['branch' => 'codex/update-a'], 'head' => ['ref' => 'codex/update-a', 'repo' => ['full_name' => 'example/repo']], 'base' => ['repo' => ['full_name' => 'example/repo']]],
    ['number' => 37, 'planned_target_version' => '0.1.0', 'planned_release_at' => '2026-04-01T00:00:00+00:00', 'updated_at' => '2026-04-01T00:00:00+00:00', 'metadata' => ['branch' => 'codex/update-b'], 'head' => ['ref' => 'codex/update-b', 'repo' => ['full_name' => 'fork/repo']], 'base' => ['repo' => ['full_name' => 'example/repo']]],
    ['number' => 39, 'planned_target_version' => '0.2.0', 'planned_release_at' => '2026-04-03T00:00:00+00:00', 'updated_at' => '2026-04-03T00:00:00+00:00', 'metadata' => ['branch' => 'codex/update-c'], 'head' => ['ref' => 'codex/update-c', 'repo' => ['full_name' => 'example/repo']], 'base' => ['repo' => ['full_name' => 'example/repo']]],
]);
$assert(count($canonicalPrs) === 2, 'Expected updater duplicate partitioning to keep one canonical PR per target version.');
$assert((int) $canonicalPrs[0]['number'] === 38, 'Expected updater duplicate partitioning to prefer the healthiest duplicate candidate, not simply the oldest PR.');
$assert(count($duplicatePrs) === 1 && (int) $duplicatePrs[0]['number'] === 37, 'Expected updater duplicate partitioning to quarantine the weaker duplicate candidate.');
$pullRequestAlreadySatisfied = $updaterReflection->getMethod('pullRequestAlreadySatisfied');
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
$installerIsolationRoot = sys_get_temp_dir() . '/wporg-installer-isolation-' . bin2hex(random_bytes(4));
mkdir($installerIsolationRoot . '/cms/plugins/plugin-a', 0777, true);
mkdir($installerIsolationRoot . '/cms/plugins/plugin-b', 0777, true);
mkdir($installerIsolationRoot . '/cms/mu-plugins', 0777, true);
mkdir($installerIsolationRoot . '/.wp-core-base', 0777, true);
file_put_contents($installerIsolationRoot . '/cms/plugins/plugin-a/plugin-a.php', "<?php\n/*\nPlugin Name: Plugin A\nVersion: 1.0.0\n*/\n");
file_put_contents($installerIsolationRoot . '/cms/plugins/plugin-b/plugin-b.php', "<?php\n/*\nPlugin Name: Plugin B\nVersion: 1.0.0\n*/\n");
$installerInspector = new RuntimeInspector($runtimeDefaults);
$pluginABaseChecksum = $installerInspector->computeChecksum($installerIsolationRoot . '/cms/plugins/plugin-a', [], [], []);
$pluginBBaseChecksum = $installerInspector->computeChecksum($installerIsolationRoot . '/cms/plugins/plugin-b', [], [], []);
$installerIsolationManifest = [
    'profile' => 'content-only',
    'paths' => [
        'content_root' => 'cms',
        'plugins_root' => 'cms/plugins',
        'themes_root' => 'cms/themes',
        'mu_plugins_root' => 'cms/mu-plugins',
    ],
    'core' => ['mode' => 'external', 'enabled' => false],
    'runtime' => $runtimeDefaults,
    'github' => ['api_base' => 'https://api.github.com'],
    'automation' => ['base_branch' => null, 'dry_run' => false, 'managed_kinds' => ['plugin']],
    'dependencies' => [
        [
            'name' => 'Plugin A',
            'slug' => 'plugin-a',
            'kind' => 'plugin',
            'management' => 'managed',
            'source' => 'wordpress.org',
            'path' => 'cms/plugins/plugin-a',
            'main_file' => 'plugin-a.php',
            'version' => '1.0.0',
            'checksum' => $pluginABaseChecksum,
            'archive_subdir' => '',
            'extra_labels' => [],
            'source_config' => [
                'github_repository' => null,
                'github_release_asset_pattern' => null,
                'github_token_env' => null,
                'credential_key' => null,
                'provider' => null,
                'provider_product_id' => null,
            ],
            'policy' => ['class' => 'managed-upstream', 'allow_runtime_paths' => [], 'strip_paths' => [], 'strip_files' => [], 'sanitize_paths' => [], 'sanitize_files' => []],
        ],
        [
            'name' => 'Plugin B',
            'slug' => 'plugin-b',
            'kind' => 'plugin',
            'management' => 'managed',
            'source' => 'wordpress.org',
            'path' => 'cms/plugins/plugin-b',
            'main_file' => 'plugin-b.php',
            'version' => '1.0.0',
            'checksum' => $pluginBBaseChecksum,
            'archive_subdir' => '',
            'extra_labels' => [],
            'source_config' => [
                'github_repository' => null,
                'github_release_asset_pattern' => null,
                'github_token_env' => null,
                'credential_key' => null,
                'provider' => null,
                'provider_product_id' => null,
            ],
            'policy' => ['class' => 'managed-upstream', 'allow_runtime_paths' => [], 'strip_paths' => [], 'strip_files' => [], 'sanitize_paths' => [], 'sanitize_files' => []],
        ],
    ],
];
file_put_contents(
    $installerIsolationRoot . '/.wp-core-base/manifest.php',
    "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($installerIsolationManifest, true) . ";\n"
);
$staleManifest = $installerIsolationManifest;
$staleManifest['dependencies'][0]['version'] = '2.0.0';
$staleManifest['dependencies'][0]['checksum'] = 'sha256:' . str_repeat('a', 64);
$staleManifest['dependencies'][1]['path'] = 'cms/plugins/plugin-b-stale';
$staleManifest['dependencies'][1]['main_file'] = 'plugin-b-stale.php';
$staleConfig = Config::fromArray($installerIsolationRoot, $staleManifest, $installerIsolationRoot . '/.wp-core-base/manifest.php');
$updaterForIsolationTest = new \WpOrgPluginUpdater\Updater(
    config: $staleConfig,
    dependencyScanner: new DependencyScanner(),
    wordPressOrgClient: new WordPressOrgClient(new HttpClient()),
    gitHubReleaseClient: new GitHubReleaseClient(new HttpClient()),
    managedSourceRegistry: new ManagedSourceRegistry(
        new class implements ManagedDependencySource
        {
            public function key(): string
            {
                return 'wordpress.org';
            }

            public function fetchCatalog(array $dependency): array
            {
                throw new RuntimeException('Not used in this test.');
            }

            public function releaseDataForVersion(array $dependency, array $catalog, string $targetVersion, string $fallbackReleaseAt): array
            {
                throw new RuntimeException('Not used in this test.');
            }

            public function downloadReleaseToFile(array $dependency, array $releaseData, string $destination): void
            {
                $zip = new ZipArchive();
                $opened = $zip->open($destination, ZipArchive::CREATE | ZipArchive::OVERWRITE);

                if ($opened !== true) {
                    throw new RuntimeException(sprintf('Failed to create test archive: %s', $destination));
                }

                $slug = (string) ($dependency['slug'] ?? 'plugin-b');
                $base = trim((string) ($releaseData['archive_subdir'] ?? ''), '/');
                $base = $base === '' ? $slug : $base;
                $zip->addEmptyDir($base);
                $zip->addFromString($base . '/plugin-b.php', "<?php\n/*\nPlugin Name: Plugin B\nVersion: 1.1.0\n*/\n");
                $zip->close();
            }

            public function supportsForumSync(array $dependency): bool
            {
                return false;
            }
        }
    ),
    supportForumClient: new SupportForumClient(new HttpClient(), 30),
    releaseClassifier: new ReleaseClassifier(),
    prBodyRenderer: new PrBodyRenderer(),
    automationClient: new FakeGitHubAutomationClient(),
    gitRunner: new FakeGitRunner(),
    runtimeInspector: new RuntimeInspector($runtimeDefaults),
    manifestWriter: new ManifestWriter(),
    httpClient: new HttpClient(),
);
$checkoutAndApply = (new ReflectionClass(\WpOrgPluginUpdater\Updater::class))->getMethod('checkoutAndApplyDependencyVersion');
$checkoutAndApply->invoke(
    $updaterForIsolationTest,
    'main',
    'codex/test-plugin-b',
    $staleConfig->dependencyByKey('plugin:wordpress.org:plugin-b'),
    [
        'version' => '1.1.0',
        'archive_subdir' => '',
    ],
    false
);
$writtenInstallerConfig = Config::load($installerIsolationRoot);
$pluginAAfterInstallerUpdate = $writtenInstallerConfig->dependencyByKey('plugin:wordpress.org:plugin-a');
$pluginBAfterInstallerUpdate = $writtenInstallerConfig->dependencyByKey('plugin:wordpress.org:plugin-b');
$assert(
    $pluginAAfterInstallerUpdate['version'] === '1.0.0' && $pluginAAfterInstallerUpdate['checksum'] === $pluginABaseChecksum,
    'Expected dependency apply writes to preserve non-target manifest entries from the checked-out branch.'
);
$assert(
    $pluginBAfterInstallerUpdate['version'] === '1.1.0',
    'Expected dependency apply writes to update the target dependency version.'
);
$assert(
    $pluginBAfterInstallerUpdate['checksum'] === $installerInspector->computeChecksum($installerIsolationRoot . '/cms/plugins/plugin-b', [], [], []),
    'Expected dependency apply writes to store the checksum computed from the updated target payload.'
);
$assert(
    ! is_dir($installerIsolationRoot . '/cms/plugins/plugin-b-stale'),
    'Expected dependency apply writes to ignore stale in-memory dependency paths and use the checked-out manifest path.'
);
$metadataMatchesDependency = $updaterReflection->getMethod('metadataMatchesDependency');
$pluginADependency = $writtenInstallerConfig->dependencyByKey('plugin:wordpress.org:plugin-a');
$assert(
    $metadataMatchesDependency->invoke(
        $updaterForIsolationTest,
        [
            'component_key' => 'plugin:wordpress.org:plugin-a',
            'kind' => 'plugin',
            'source' => 'wordpress.org',
            'slug' => 'plugin-b',
            'dependency_path' => 'cms/plugins/plugin-b',
        ],
        $pluginADependency
    ) === false,
    'Expected metadata matching to reject component_key metadata that conflicts with explicit dependency identity fields.'
);
$indexManagedPullRequests = $updaterReflection->getMethod('indexManagedPullRequests');
$conflictingIndex = $indexManagedPullRequests->invoke($updaterForIsolationTest, [[
    'number' => 501,
    'body' => '<!-- wporg-update-metadata: {"component_key":"plugin:wordpress.org:plugin-a","kind":"plugin","source":"wordpress.org","slug":"plugin-b","dependency_path":"cms/plugins/plugin-b","target_version":"9.9.9"} -->',
    'head' => ['ref' => 'codex/conflicting-metadata', 'repo' => ['full_name' => 'example/repo']],
    'base' => ['repo' => ['full_name' => 'example/repo']],
]]);
$assert($conflictingIndex === [], 'Expected PR indexing to ignore metadata entries whose component_key conflicts with explicit identity fields.');
$unknownComponentKeyIndex = $indexManagedPullRequests->invoke($updaterForIsolationTest, [[
    'number' => 502,
    'body' => '<!-- wporg-update-metadata: {"component_key":"plugin:wordpress.org:missing-plugin","target_version":"1.2.3"} -->',
    'head' => ['ref' => 'codex/missing-component-key', 'repo' => ['full_name' => 'example/repo']],
    'base' => ['repo' => ['full_name' => 'example/repo']],
]]);
$assert($unknownComponentKeyIndex === [], 'Expected PR indexing to ignore metadata entries with unknown component keys.');
$installerInspector->clearPath($installerIsolationRoot);
$coreUpdaterReflection = new ReflectionClass(\WpOrgPluginUpdater\CoreUpdater::class);
$coreUpdaterWithoutConstructor = $coreUpdaterReflection->newInstanceWithoutConstructor();
$corePartition = $coreUpdaterReflection->getMethod('partitionPullRequestsByTargetVersion');
[$coreCanonical, $coreDuplicates] = $corePartition->invoke($coreUpdaterWithoutConstructor, [
    ['number' => 11, 'planned_target_version' => '6.9.4', 'planned_release_at' => '2026-04-01T00:00:00+00:00', 'updated_at' => '2026-04-01T00:00:00+00:00', 'metadata' => ['branch' => 'codex/core-a'], 'head' => ['ref' => 'codex/core-a', 'repo' => ['full_name' => 'fork/repo']], 'base' => ['repo' => ['full_name' => 'example/repo']]],
    ['number' => 12, 'planned_target_version' => '6.9.4', 'planned_release_at' => '2026-04-01T00:00:00+00:00', 'updated_at' => '2026-04-02T00:00:00+00:00', 'metadata' => ['branch' => 'codex/core-b'], 'head' => ['ref' => 'codex/core-b', 'repo' => ['full_name' => 'example/repo']], 'base' => ['repo' => ['full_name' => 'example/repo']]],
    ['number' => 13, 'planned_target_version' => '7.0.0', 'planned_release_at' => '2026-04-03T00:00:00+00:00', 'updated_at' => '2026-04-03T00:00:00+00:00', 'metadata' => ['branch' => 'codex/core-c'], 'head' => ['ref' => 'codex/core-c', 'repo' => ['full_name' => 'example/repo']], 'base' => ['repo' => ['full_name' => 'example/repo']]],
]);
$assert(count($coreCanonical) === 2, 'Expected core updater duplicate partitioning to keep one canonical PR per target version.');
$assert((int) $coreCanonical[0]['number'] === 12, 'Expected core updater duplicate partitioning to prefer the healthiest duplicate candidate.');
$assert(count($coreDuplicates) === 1 && (int) $coreDuplicates[0]['number'] === 11, 'Expected core updater duplicate partitioning to mark the weaker duplicate candidate.');
$coreSatisfied = $coreUpdaterReflection->getMethod('pullRequestAlreadySatisfied');
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
[$frameworkCanonical, $frameworkDuplicates] = $frameworkPartition->invoke($frameworkSyncerWithoutConstructor, [
    ['number' => 21, 'planned_target_version' => '1.3.1', 'planned_release_at' => '2026-04-01T00:00:00+00:00', 'updated_at' => '2026-04-01T00:00:00+00:00', 'metadata' => ['branch' => 'codex/framework-a'], 'head' => ['ref' => 'codex/framework-a', 'repo' => ['full_name' => 'fork/repo']], 'base' => ['repo' => ['full_name' => 'example/repo']]],
    ['number' => 22, 'planned_target_version' => '1.3.1', 'planned_release_at' => '2026-04-01T00:00:00+00:00', 'updated_at' => '2026-04-02T00:00:00+00:00', 'metadata' => ['branch' => 'codex/framework-b'], 'head' => ['ref' => 'codex/framework-b', 'repo' => ['full_name' => 'example/repo']], 'base' => ['repo' => ['full_name' => 'example/repo']]],
    ['number' => 23, 'planned_target_version' => '1.4.0', 'planned_release_at' => '2026-04-03T00:00:00+00:00', 'updated_at' => '2026-04-03T00:00:00+00:00', 'metadata' => ['branch' => 'codex/framework-c'], 'head' => ['ref' => 'codex/framework-c', 'repo' => ['full_name' => 'example/repo']], 'base' => ['repo' => ['full_name' => 'example/repo']]],
]);
$assert(count($frameworkCanonical) === 2, 'Expected framework sync duplicate partitioning to keep one canonical PR per target version.');
$assert((int) $frameworkCanonical[0]['number'] === 22, 'Expected framework sync duplicate partitioning to prefer the healthiest duplicate candidate.');
$assert(count($frameworkDuplicates) === 1 && (int) $frameworkDuplicates[0]['number'] === 21, 'Expected framework sync duplicate partitioning to mark the weaker duplicate candidate.');
$frameworkSatisfied = $frameworkSyncerReflection->getMethod('pullRequestAlreadySatisfied');
$assert(
    $frameworkSatisfied->invoke($frameworkSyncerWithoutConstructor, '1.3.1', '1.3.1') === true,
    'Expected framework sync to treat matching base and target versions as already satisfied.'
);
$assert(
    $frameworkSatisfied->invoke($frameworkSyncerWithoutConstructor, '1.3.1', '1.4.0') === false,
    'Expected framework sync to keep PRs open when the target version is still ahead of base.'
);
$frameworkBaseBranchConfig = Config::fromArray($repoRoot, [
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
        'base_branch' => 'release-base',
        'dry_run' => false,
        'managed_kinds' => ['plugin', 'theme'],
    ],
    'dependencies' => [],
], $repoRoot . '/.wp-core-base/manifest.php');
$frameworkBaseBranchGitRunner = new FakeGitRunner();
$frameworkBaseBranchGitRunner->remoteBranches = ['release-base' => 'release-base-sha'];
$frameworkBaseBranchGitRunner->localBranches = ['release-base' => 'release-base-sha'];
$frameworkBaseBranchGitRunner->currentBranch = 'release-base';
$frameworkBaseBranchGitRunner->currentRevision = 'release-base-sha';
$frameworkBaseBranchGitHubClient = new FakeGitHubAutomationClient();
$frameworkBaseBranchGitHubClient->defaultBranch = 'main';
$frameworkBaseBranchReleaseSource = new class($frameworkConfig) implements \WpOrgPluginUpdater\FrameworkReleaseSource
{
    public function __construct(private readonly FrameworkConfig $framework)
    {
    }

    public function fetchStableReleases(FrameworkConfig $framework): array
    {
        return [[
            'version' => $this->framework->version,
            'release_at' => '2026-04-01T00:00:00+00:00',
        ]];
    }

    public function releaseData(FrameworkConfig $framework, array $release): array
    {
        return [
            'version' => (string) $release['version'],
            'release_at' => (string) $release['release_at'],
            'release_url' => 'https://example.com/wp-core-base/releases/' . $framework->version,
            'target_wordpress_core' => $framework->baseline['wordpress_core'],
            'notes_sections' => [
                'Summary' => 'No change.',
            ],
        ];
    }

    public function downloadVerifiedReleaseAsset(FrameworkConfig $framework, array $release, string $destination): void
    {
        throw new RuntimeException('Not used in tests.');
    }
};
(new \WpOrgPluginUpdater\FrameworkSyncer(
    framework: $frameworkConfig,
    repoRoot: $repoRoot,
    config: $frameworkBaseBranchConfig,
    frameworkReleaseClient: $frameworkBaseBranchReleaseSource,
    releaseClassifier: new ReleaseClassifier(),
    prBodyRenderer: new PrBodyRenderer(),
    automationClient: $frameworkBaseBranchGitHubClient,
    gitRunner: $frameworkBaseBranchGitRunner,
    runtimeInspector: new RuntimeInspector($runtimeDefaults),
))->sync(false);
$assert(($frameworkBaseBranchGitRunner->remoteBranches['release-base'] ?? null) === 'release-base-sha', 'Expected framework-sync to honor automation.base_branch when selecting the base branch.');
$scaffoldedUpdatesWorkflow = (string) file_get_contents($imageFirstScaffoldRoot . '/.github/workflows/wporg-updates.yml');
$scaffoldedReconcileWorkflow = (string) file_get_contents($imageFirstScaffoldRoot . '/.github/workflows/wporg-updates-reconcile.yml');
$assert(str_contains($scaffoldedUpdatesWorkflow, 'group: wp-core-base-dependency-sync'), 'Expected scaffolded updates workflow to use the shared dependency-sync concurrency group.');
$assert(str_contains($scaffoldedReconcileWorkflow, 'group: wp-core-base-dependency-sync'), 'Expected scaffolded reconcile workflow to share the dependency-sync concurrency group.');
$assert(str_contains($scaffoldedUpdatesWorkflow, '--report-json=.wp-core-base/build/sync-report.json --fail-on-source-errors'), 'Expected scaffolded updates workflow to fail after the run when dependency-source warnings were recorded.');
$assert(str_contains($scaffoldedUpdatesWorkflow, 'render-sync-report'), 'Expected scaffolded updates workflow to publish a sync summary.');
$assert(str_contains($scaffoldedUpdatesWorkflow, 'sync-report-issue'), 'Expected scaffolded updates workflow to synchronize the dependency source-failure issue.');
$assert(str_contains($scaffoldedReconcileWorkflow, '--report-json=.wp-core-base/build/sync-report.json --fail-on-source-errors'), 'Expected scaffolded reconcile workflow to fail after the run when dependency-source warnings were recorded.');
$assert(str_contains($scaffoldedReconcileWorkflow, 'sync-report-issue'), 'Expected scaffolded reconcile workflow to synchronize the dependency source-failure issue.');
$assert(str_contains($scaffoldedReconcileWorkflow, 'workflow_dispatch:'), 'Expected scaffolded reconcile workflow to expose a manual recovery trigger.');
$assert(str_contains($scaffoldedReconcileWorkflow, 'schedule:'), 'Expected scaffolded reconcile workflow to expose a scheduled recovery trigger.');
$scaffoldedBlockerWorkflow = (string) file_get_contents($imageFirstScaffoldRoot . '/.github/workflows/wporg-update-pr-blocker.yml');
$assert(str_contains($scaffoldedBlockerWorkflow, 'pr-blocker-reconcile'), 'Expected scaffolded blocker workflow to include blocker reconciliation scan mode.');
$assert(str_contains($scaffoldedBlockerWorkflow, 'workflow_dispatch:'), 'Expected scaffolded blocker workflow to expose manual retry dispatch.');
$assert(str_contains($scaffoldedBlockerWorkflow, 'schedule:'), 'Expected scaffolded blocker workflow to expose scheduled retry coverage.');
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

$gitRunnerRoot = sys_get_temp_dir() . '/wporg-git-runner-' . bin2hex(random_bytes(4));
mkdir($gitRunnerRoot, 0777, true);
run_process_or_fail($assert, $gitRunnerRoot, ['git', 'init'], 'Expected to initialize a temporary Git repository for GitCommandRunner tests.');
run_process_or_fail($assert, $gitRunnerRoot, ['git', 'config', 'user.email', 'test@example.com'], 'Expected to configure a test Git user email.');
run_process_or_fail($assert, $gitRunnerRoot, ['git', 'config', 'user.name', 'WP Core Base Tests'], 'Expected to configure a test Git user name.');
run_process_or_fail($assert, $gitRunnerRoot, ['git', 'checkout', '-b', 'main'], 'Expected to create a main branch in the temporary Git repository.');
file_put_contents($gitRunnerRoot . '/tracked.txt', "baseline\n");
run_process_or_fail($assert, $gitRunnerRoot, ['git', 'add', 'tracked.txt'], 'Expected to stage baseline file in temporary Git repository.');
run_process_or_fail($assert, $gitRunnerRoot, ['git', 'commit', '-m', 'Initial commit'], 'Expected to create baseline commit in temporary Git repository.');
$remoteRoot = sys_get_temp_dir() . '/wporg-git-remote-' . bin2hex(random_bytes(4)) . '.git';
run_process_or_fail($assert, $gitRunnerRoot, ['git', 'init', '--bare', $remoteRoot], 'Expected to create a temporary bare Git remote.');
run_process_or_fail($assert, $gitRunnerRoot, ['git', 'remote', 'add', 'origin', $remoteRoot], 'Expected to configure temporary origin remote.');
run_process_or_fail($assert, $gitRunnerRoot, ['git', 'push', '-u', 'origin', 'main'], 'Expected to push baseline branch to temporary origin.');
$baselineRevision = run_process_or_fail($assert, $gitRunnerRoot, ['git', 'rev-parse', 'HEAD'], 'Expected to resolve baseline revision.');
run_process_or_fail($assert, $gitRunnerRoot, ['git', 'remote', 'set-url', 'origin', '/path/that/does/not/exist'], 'Expected to reconfigure origin to an invalid path for push-failure rollback testing.');
file_put_contents($gitRunnerRoot . '/tracked.txt', "changed\n");
$gitCommandRunner = new GitCommandRunner($gitRunnerRoot);
$pushRollbackRaised = false;

try {
    $gitCommandRunner->commitAndPush('main', 'Simulate push rollback', ['tracked.txt']);
} catch (RuntimeException $exception) {
    $pushRollbackRaised = str_contains($exception->getMessage(), 'branch was reset to ' . $baselineRevision);
}

$assert($pushRollbackRaised, 'Expected GitCommandRunner to report push failure rollback details including the baseline revision.');
$assert(
    run_process_or_fail($assert, $gitRunnerRoot, ['git', 'rev-parse', 'HEAD'], 'Expected to resolve post-rollback revision.') === $baselineRevision,
    'Expected GitCommandRunner to reset the local branch back to the baseline revision when push fails.'
);
$assert(
    trim((string) file_get_contents($gitRunnerRoot . '/tracked.txt')) === 'baseline',
    'Expected GitCommandRunner push-failure rollback to restore tracked file contents.'
);

$lockRepoRoot = sys_get_temp_dir() . '/wporg-lock-timeout-' . bin2hex(random_bytes(4));
mkdir($lockRepoRoot . '/.wp-core-base/build/locks', 0777, true);
$lockPath = $lockRepoRoot . '/.wp-core-base/build/locks/mutation-test.lock';
$lockHandle = fopen($lockPath, 'c+');
$assert(is_resource($lockHandle), 'Expected lock timeout fixture to open lock file.');
$assert(flock($lockHandle, LOCK_EX | LOCK_NB), 'Expected lock timeout fixture to acquire exclusive lock.');
ftruncate($lockHandle, 0);
rewind($lockHandle);
fwrite($lockHandle, sprintf("pid=%d\nacquired_at=%s\n", getmypid() ?: 0, gmdate(DATE_ATOM)));
fflush($lockHandle);
$previousLockTimeout = getenv('WP_CORE_BASE_LOCK_TIMEOUT_SECONDS');
putenv('WP_CORE_BASE_LOCK_TIMEOUT_SECONDS=1');
$mutationLock = new MutationLock();
$lockTimeoutRaised = false;

try {
    $mutationLock->synchronized($lockRepoRoot, static fn (): string => 'never-runs', 'mutation-test');
} catch (RuntimeException $exception) {
    $message = $exception->getMessage();
    $lockTimeoutRaised = str_contains($message, 'Timed out after 1 seconds waiting for mutation lock')
        && str_contains($message, 'holder pid=')
        && str_contains($message, 'holder acquired_at=');
}

$assert($lockTimeoutRaised, 'Expected MutationLock timeout errors to include lock holder PID and acquisition timestamp diagnostics.');
flock($lockHandle, LOCK_UN);
fclose($lockHandle);

if ($previousLockTimeout === false) {
    putenv('WP_CORE_BASE_LOCK_TIMEOUT_SECONDS');
} else {
    putenv('WP_CORE_BASE_LOCK_TIMEOUT_SECONDS=' . $previousLockTimeout);
}

$httpClientForRetryContracts = new HttpClient();
$parseRetryAfter = new ReflectionMethod(HttpClient::class, 'parseRetryAfterSeconds');
$retryDelayFromHeaders = new ReflectionMethod(HttpClient::class, 'retryDelayFromHeaders');
$nextRetryDelayMicroseconds = new ReflectionMethod(HttpClient::class, 'nextRetryDelayMicroseconds');

$assert($parseRetryAfter->invoke($httpClientForRetryContracts, '7') === 7, 'Expected Retry-After numeric parsing to return exact seconds.');
$retryAfterDateSeconds = $parseRetryAfter->invoke(
    $httpClientForRetryContracts,
    gmdate('D, d M Y H:i:s \G\M\T', time() + 5)
);
$assert(is_int($retryAfterDateSeconds) && $retryAfterDateSeconds >= 1 && $retryAfterDateSeconds <= 6, 'Expected Retry-After HTTP-date parsing to resolve to a bounded positive delay.');
$assert($parseRetryAfter->invoke($httpClientForRetryContracts, 'not-a-date') === null, 'Expected Retry-After parsing to reject invalid values.');
$rateLimitDelay = $retryDelayFromHeaders->invoke($httpClientForRetryContracts, [
    'x-ratelimit-remaining' => '0',
    'x-ratelimit-reset' => (string) (time() + 3),
]);
$assert(is_int($rateLimitDelay) && $rateLimitDelay >= 1 && $rateLimitDelay <= 3, 'Expected x-ratelimit-reset handling to compute a bounded retry delay.');
$cappedDelay = $nextRetryDelayMicroseconds->invoke(
    $httpClientForRetryContracts,
    250_000,
    ['status' => 429, 'body' => '', 'headers' => ['retry-after' => '5000']]
);
$assert($cappedDelay === 900_000_000, 'Expected Retry-After delays to be capped at MAX_RETRY_DELAY_SECONDS.');
$fallbackDelay = $nextRetryDelayMicroseconds->invoke(
    $httpClientForRetryContracts,
    123_000,
    ['status' => 503, 'body' => '', 'headers' => []]
);
$assert($fallbackDelay === 123_000, 'Expected retry delay calculation to fall back when no rate-limit headers are available.');

$fakeFilterClient = new FakeGitHubAutomationClient();
$fakeFilterClient->openPullRequests = [
    ['number' => 1, 'labels' => [['name' => 'automation:dependency-update']]],
    ['number' => 2, 'labels' => [['name' => 'automation:framework-update']]],
    ['number' => 3, 'labels' => [['name' => 'unrelated']]],
];
$assert(count($fakeFilterClient->listOpenPullRequests()) === 3, 'Expected unfiltered open pull request listing to return all pull requests.');
$assert(
    array_column($fakeFilterClient->listOpenPullRequests('automation:dependency-update'), 'number') === [1],
    'Expected label-filtered open pull request listing to return only matching pull requests.'
);

$janitorRoot = sys_get_temp_dir() . '/wporg-janitor-contract-' . bin2hex(random_bytes(4));
mkdir($janitorRoot, 0777, true);
$staleManagedDirectory = $janitorRoot . '/wporg-update-old';
$freshManagedDirectory = $janitorRoot . '/wporg-update-fresh';
$unmanagedDirectory = $janitorRoot . '/custom-temp-old';
mkdir($staleManagedDirectory . '/nested', 0777, true);
mkdir($freshManagedDirectory, 0777, true);
mkdir($unmanagedDirectory, 0777, true);
file_put_contents($staleManagedDirectory . '/nested/stale.txt', "stale\n");
file_put_contents($freshManagedDirectory . '/fresh.txt', "fresh\n");
file_put_contents($unmanagedDirectory . '/unmanaged.txt', "unmanaged\n");
touch($staleManagedDirectory, time() - 600);
touch($freshManagedDirectory, time());
touch($unmanagedDirectory, time() - 600);
$janitorResult = (new TempDirectoryJanitor(['wporg-update-'], 60, $janitorRoot))->cleanup();
$assert(
    in_array($staleManagedDirectory, $janitorResult['removed'], true),
    'Expected TempDirectoryJanitor to remove stale managed temporary directories.'
);
$assert(! is_dir($staleManagedDirectory), 'Expected TempDirectoryJanitor to delete stale managed temporary directory trees.');
$assert(is_dir($freshManagedDirectory), 'Expected TempDirectoryJanitor to preserve managed temporary directories newer than max age.');
$assert(is_dir($unmanagedDirectory), 'Expected TempDirectoryJanitor to ignore stale directories without recognized prefixes.');
$assert($janitorResult['failed'] === [], 'Expected TempDirectoryJanitor cleanup contract test to complete without failures.');

$structuredLoggerScript = $janitorRoot . '/structured-logger-contract.php';
file_put_contents($structuredLoggerScript, sprintf(<<<'PHP'
<?php
declare(strict_types=1);
require %s;
putenv('WP_CORE_BASE_JSON_LOGS=1');
$startedAt = \WpOrgPluginUpdater\StructuredLogger::startTimer();
usleep(2000);
\WpOrgPluginUpdater\StructuredLogger::log(
    'INFO',
    'sync',
    'Structured logger contract test message.',
    'component:test',
    $startedAt,
    ['contract' => 'structured-logger']
);
PHP, var_export($repoRoot . '/tools/wporg-updater/src/Autoload.php', true)));
$structuredLoggerResult = run_process($repoRoot, ['php', $structuredLoggerScript]);
$assert($structuredLoggerResult['exit_code'] === 0, 'Expected StructuredLogger contract subprocess to exit successfully.');
$structuredLoggerLines = array_values(array_filter(array_map('trim', preg_split('/\R+/', $structuredLoggerResult['stderr']) ?: []), static fn (string $line): bool => $line !== ''));
$assert(count($structuredLoggerLines) === 1, 'Expected StructuredLogger JSON mode contract test to emit exactly one log line.');
$structuredLoggerPayload = json_decode($structuredLoggerLines[0], true);
$assert(is_array($structuredLoggerPayload), 'Expected StructuredLogger contract output to be valid JSON.');
$assert(($structuredLoggerPayload['operation'] ?? null) === 'sync', 'Expected StructuredLogger contract output to include operation.');
$assert(($structuredLoggerPayload['component_key'] ?? null) === 'component:test', 'Expected StructuredLogger contract output to include component key.');
$assert(($structuredLoggerPayload['level'] ?? null) === 'info', 'Expected StructuredLogger contract output to normalize level casing.');
$assert(($structuredLoggerPayload['message'] ?? null) === 'Structured logger contract test message.', 'Expected StructuredLogger contract output to include message.');
$assert(isset($structuredLoggerPayload['operation_id']) && preg_match('/^[a-f0-9]{16}$/', (string) $structuredLoggerPayload['operation_id']) === 1, 'Expected StructuredLogger contract output to include a stable 16-hex operation_id.');
$assert(is_int($structuredLoggerPayload['duration_ms'] ?? null), 'Expected StructuredLogger contract output to include integer duration_ms when startedAt is provided.');
$assert(($structuredLoggerPayload['context']['contract'] ?? null) === 'structured-logger', 'Expected StructuredLogger contract output to include context payload.');

run_blocker_state_tests($assert);
run_followup_integration_tests($assert);

run_security_policy_contract_tests($assert, $repoRoot);

run_security_framework_contract_tests(
    $assert,
    $repoRoot,
    $config,
    $frameworkConfig,
    $tempScaffoldRoot,
    $scaffoldedFramework,
    $fixtureDir,
    $coreClient,
    $renderer
);

run_dependency_authoring_contract_tests($assert, [
    'repoRoot' => $repoRoot,
    'writeManifest' => $writeManifest,
    'createPluginArchive' => $createPluginArchive,
    'makeManagedSourceRegistry' => $makeManagedSourceRegistry,
    'runtimeDefaults' => $runtimeDefaults,
    'wpClient' => $wpClient,
    'httpClient' => $httpClient,
    'gitHubReleaseClient' => $gitHubReleaseClient,
    'premiumCredentialsStore' => $premiumCredentialsStore,
    'supportClient' => $supportClient,
]);

run_generic_json_contract_tests($assert, [
    'repoRoot' => $repoRoot,
    'writeManifest' => $writeManifest,
    'createPluginArchive' => $createPluginArchive,
    'httpClient' => $httpClient,
]);

run_cli_json_contract_tests(
    $assert,
    $repoRoot,
    $runtimeInspector,
    $currentFrameworkVersion,
    $writeManifest,
    $writePremiumProvider
);

fwrite(STDOUT, "All updater tests passed.\n");
