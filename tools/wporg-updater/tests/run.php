<?php

declare(strict_types=1);

use WpOrgPluginUpdater\Config;
use WpOrgPluginUpdater\CommandHelp;
use WpOrgPluginUpdater\ConfigWriter;
use WpOrgPluginUpdater\CoreScanner;
use WpOrgPluginUpdater\AbstractPremiumManagedSource;
use WpOrgPluginUpdater\AdminGovernanceExporter;
use WpOrgPluginUpdater\DependencyAuthoringService;
use WpOrgPluginUpdater\DependencyMetadataResolver;
use WpOrgPluginUpdater\DependencyScanner;
use WpOrgPluginUpdater\DownstreamScaffolder;
use WpOrgPluginUpdater\ExtractedPayloadLocator;
use WpOrgPluginUpdater\FrameworkConfig;
use WpOrgPluginUpdater\FrameworkInstaller;
use WpOrgPluginUpdater\FrameworkReleaseNotes;
use WpOrgPluginUpdater\FrameworkReleasePreparer;
use WpOrgPluginUpdater\FrameworkReleaseVerifier;
use WpOrgPluginUpdater\FrameworkRuntimeFiles;
use WpOrgPluginUpdater\FrameworkWriter;
use WpOrgPluginUpdater\GitHubReleaseClient;
use WpOrgPluginUpdater\GitHubReleaseManagedSource;
use WpOrgPluginUpdater\GitHubReleaseSource;
use WpOrgPluginUpdater\HttpStatusRuntimeException;
use WpOrgPluginUpdater\HttpClient;
use WpOrgPluginUpdater\InteractivePrompter;
use WpOrgPluginUpdater\ManagedDependencySource;
use WpOrgPluginUpdater\ManagedSourceRegistry;
use WpOrgPluginUpdater\ManifestWriter;
use WpOrgPluginUpdater\ManifestSuggester;
use WpOrgPluginUpdater\LabelHelper;
use WpOrgPluginUpdater\PremiumProviderRegistry;
use WpOrgPluginUpdater\PremiumProviderScaffolder;
use WpOrgPluginUpdater\PremiumCredentialsStore;
use WpOrgPluginUpdater\PrBodyRenderer;
use WpOrgPluginUpdater\ReleaseClassifier;
use WpOrgPluginUpdater\RuntimeInspector;
use WpOrgPluginUpdater\RuntimeOwnershipInspector;
use WpOrgPluginUpdater\RuntimeStager;
use WpOrgPluginUpdater\SupportForumClient;
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
$releaseNotesMarkdown = (string) file_get_contents($repoRoot . '/docs/releases/' . $currentFrameworkVersion . '.md');
$assert($releaseNotesMarkdown !== '', 'Expected framework release notes to exist.');
$assert(FrameworkReleaseNotes::missingRequiredSections($releaseNotesMarkdown) === [], 'Expected framework release notes to include all required sections.');
$assert((new FrameworkReleaseVerifier($repoRoot))->verify() === 'v' . $currentFrameworkVersion, 'Expected framework release verification to succeed.');
$upstreamUpdatesWorkflow = (string) file_get_contents($repoRoot . '/.github/workflows/wporg-updates.yml');
$upstreamReconcileWorkflow = (string) file_get_contents($repoRoot . '/.github/workflows/wporg-updates-reconcile.yml');
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
$assert(str_contains($upstreamFinalizeWorkflow, "git push --delete origin"), 'Expected finalize release workflow to roll back the pushed tag when release publishing fails.');
$assert(str_contains($upstreamRecoveryReleaseWorkflow, 'wp-core-base-vendor-snapshot.zip.sha256'), 'Expected manual release workflow to publish a SHA-256 checksum asset.');
$assert(str_contains($upstreamRecoveryReleaseWorkflow, 'GitHub Release ${{ steps.version.outputs.value }} already exists; nothing to publish.'), 'Expected manual recovery release workflow to exit cleanly when the GitHub Release already exists.');

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
$assert(str_contains($preparedNotes, '- WooCommerce `10.6.1`'), 'Expected scaffolded release notes to include the bundled baseline component list.');
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
$assert($woocommerceState['version'] === '10.6.1', 'Expected bundled WooCommerce version to match the manifest.');

$runtimeInspector = new RuntimeInspector($config->runtime);
$runtimeInspector->assertTreeIsClean($repoRoot . '/wp-content/plugins/woocommerce');
$assert(
    $runtimeInspector->computeTreeChecksum($repoRoot . '/wp-content/plugins/woocommerce') === $woocommerce['checksum'],
    'Expected managed dependency checksum to match the sanitized tree.'
);

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
$scaffoldedFramework = FrameworkConfig::load($tempScaffoldRoot);
$assert($scaffoldedFramework->distributionPath() === 'vendor/wp-core-base', 'Expected scaffolded framework metadata to point at the vendored framework path.');
$scaffoldedFrameworkWorkflow = (string) file_get_contents($tempScaffoldRoot . '/.github/workflows/wp-core-base-self-update.yml');
$assert(str_contains($scaffoldedFrameworkWorkflow, 'framework-sync --repo-root=.'), 'Expected scaffolded self-update workflow to run framework-sync.');
$assert(str_contains($scaffoldedFrameworkWorkflow, $checkoutActionSha), 'Expected scaffolded framework self-update workflow to pin actions/checkout by full commit SHA.');
$assert(str_contains($scaffoldedFrameworkWorkflow, $setupPhpActionSha), 'Expected scaffolded framework self-update workflow to pin setup-php by full commit SHA.');
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
    ['number' => 38, 'planned_target_version' => '0.1.0'],
    ['number' => 37, 'planned_target_version' => '0.1.0'],
    ['number' => 39, 'planned_target_version' => '0.2.0'],
]);
$assert(count($canonicalPrs) === 2, 'Expected updater duplicate partitioning to keep one canonical PR per target version.');
$assert((int) $canonicalPrs[0]['number'] === 37, 'Expected updater duplicate partitioning to keep the oldest PR for a duplicated target version.');
$assert(count($duplicatePrs) === 1 && (int) $duplicatePrs[0]['number'] === 38, 'Expected updater duplicate partitioning to mark later PRs for the same target version as duplicates.');
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
$scaffoldedUpdatesWorkflow = (string) file_get_contents($imageFirstScaffoldRoot . '/.github/workflows/wporg-updates.yml');
$scaffoldedReconcileWorkflow = (string) file_get_contents($imageFirstScaffoldRoot . '/.github/workflows/wporg-updates-reconcile.yml');
$assert(str_contains($scaffoldedUpdatesWorkflow, 'group: wp-core-base-dependency-sync'), 'Expected scaffolded updates workflow to use the shared dependency-sync concurrency group.');
$assert(str_contains($scaffoldedReconcileWorkflow, 'group: wp-core-base-dependency-sync'), 'Expected scaffolded reconcile workflow to share the dependency-sync concurrency group.');

$payloadRoot = sys_get_temp_dir() . '/wporg-framework-payload-' . bin2hex(random_bytes(4));
mkdir($payloadRoot, 0777, true);
(new RuntimeInspector($config->runtime))->copyPath($repoRoot, $payloadRoot);
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
$premiumDependency = $premiumConfig->dependencyByKey('plugin:premium:example-premium-plugin');
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
    $duplicateBlocked = str_contains($exception->getMessage(), 'Dependency already exists: plugin:premium:example-premium-plugin');
}

$assert($duplicateBlocked, 'Expected premium authoring to reject duplicate provider/slug combinations.');

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
$failingWriter = new class implements ConfigWriter
{
    public function write(Config $config): void
    {
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
    adminGovernanceExporter: new AdminGovernanceExporter(new RuntimeInspector($rollbackConfig->runtime)),
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

ZipExtractor::assertSafeEntryName('wordpress/wp-includes/version.php');
$zipTraversalRejected = false;

try {
    ZipExtractor::assertSafeEntryName('../escape.php');
} catch (RuntimeException) {
    $zipTraversalRejected = true;
}

$assert($zipTraversalRejected, 'Expected ZipExtractor to reject path traversal entries.');

$tempCoreRoot = sys_get_temp_dir() . '/wporg-core-scanner-' . bin2hex(random_bytes(4));
mkdir($tempCoreRoot . '/wp-includes', 0777, true);
file_put_contents($tempCoreRoot . '/wp-includes/version.php', "<?php\n\$wp_version = '6.9.4';\n");
$coreScan = (new CoreScanner())->inspect($tempCoreRoot);
$assert($coreScan['version'] === '6.9.4', 'Expected CoreScanner to parse $wp_version correctly.');

fwrite(STDOUT, "All updater tests passed.\n");
