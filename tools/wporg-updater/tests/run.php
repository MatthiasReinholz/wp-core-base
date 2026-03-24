<?php

declare(strict_types=1);

use WpOrgPluginUpdater\Config;
use WpOrgPluginUpdater\CoreScanner;
use WpOrgPluginUpdater\DependencyAuthoringService;
use WpOrgPluginUpdater\DependencyMetadataResolver;
use WpOrgPluginUpdater\DependencyScanner;
use WpOrgPluginUpdater\DownstreamScaffolder;
use WpOrgPluginUpdater\FrameworkConfig;
use WpOrgPluginUpdater\FrameworkInstaller;
use WpOrgPluginUpdater\FrameworkReleaseNotes;
use WpOrgPluginUpdater\FrameworkReleasePreparer;
use WpOrgPluginUpdater\FrameworkReleaseVerifier;
use WpOrgPluginUpdater\FrameworkWriter;
use WpOrgPluginUpdater\GitHubReleaseClient;
use WpOrgPluginUpdater\HttpClient;
use WpOrgPluginUpdater\InteractivePrompter;
use WpOrgPluginUpdater\ManifestWriter;
use WpOrgPluginUpdater\ManifestSuggester;
use WpOrgPluginUpdater\LabelHelper;
use WpOrgPluginUpdater\PrBodyRenderer;
use WpOrgPluginUpdater\ReleaseClassifier;
use WpOrgPluginUpdater\RuntimeInspector;
use WpOrgPluginUpdater\RuntimeOwnershipInspector;
use WpOrgPluginUpdater\RuntimeStager;
use WpOrgPluginUpdater\SupportForumClient;
use WpOrgPluginUpdater\WordPressCoreClient;
use WpOrgPluginUpdater\WordPressOrgClient;
use WpOrgPluginUpdater\ZipExtractor;

require dirname(__DIR__) . '/src/Autoload.php';

$fixtureDir = __DIR__ . '/fixtures';
$repoRoot = dirname(__DIR__, 3);
$classifier = new ReleaseClassifier();
$httpClient = new HttpClient();
$wpClient = new WordPressOrgClient($httpClient);
$gitHubReleaseClient = new GitHubReleaseClient($httpClient);
$coreClient = new WordPressCoreClient($httpClient);
$supportClient = new SupportForumClient($httpClient, 30);
$renderer = new PrBodyRenderer();

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

$frameworkConfig = FrameworkConfig::load($repoRoot);
$currentFrameworkVersion = $frameworkConfig->version;
$assert(preg_match('/^\d+\.\d+\.\d+$/', $currentFrameworkVersion) === 1, 'Expected framework metadata to load a valid current framework version.');
$assert($frameworkConfig->distributionPath() === '.', 'Expected upstream framework metadata to point at the repository root.');
$releaseNotesMarkdown = (string) file_get_contents($repoRoot . '/docs/releases/' . $currentFrameworkVersion . '.md');
$assert($releaseNotesMarkdown !== '', 'Expected framework release notes to exist.');
$assert(FrameworkReleaseNotes::missingRequiredSections($releaseNotesMarkdown) === [], 'Expected framework release notes to include all required sections.');
$assert((new FrameworkReleaseVerifier($repoRoot))->verify() === 'v' . $currentFrameworkVersion, 'Expected framework release verification to succeed.');

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
$scaffoldedWorkflow = (string) file_get_contents($tempScaffoldRoot . '/.github/workflows/wporg-updates.yml');
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
$assert(str_contains($scaffoldedWorkflow, 'php vendor/wp-core-base/tools/wporg-updater/bin/wporg-updater.php sync'), 'Expected scaffolded workflow to target the configured tool path.');
$assert(str_contains($scaffoldedWorkflow, 'WPORG_REPO_ROOT: ${{ github.workspace }}'), 'Expected scaffolded workflow to set WPORG_REPO_ROOT so sync runs against the downstream repo.');
$assert(str_contains($scaffoldedBlocker, 'contents: read'), 'Expected scaffolded blocker workflow to grant contents: read for actions/checkout.');
$assert(str_contains($scaffoldedValidate, 'stage-runtime'), 'Expected scaffolded validation workflow to stage runtime output.');
$scaffoldedFramework = FrameworkConfig::load($tempScaffoldRoot);
$assert($scaffoldedFramework->distributionPath() === 'vendor/wp-core-base', 'Expected scaffolded framework metadata to point at the vendored framework path.');
$scaffoldedFrameworkWorkflow = (string) file_get_contents($tempScaffoldRoot . '/.github/workflows/wp-core-base-self-update.yml');
$assert(str_contains($scaffoldedFrameworkWorkflow, 'framework-sync --repo-root=.'), 'Expected scaffolded self-update workflow to run framework-sync.');
$assert(str_contains($scaffoldedWorkflow, "github.event.pull_request.merged == true"), 'Expected scaffolded updates workflow to narrow closed-PR reconciliation to merged PRs.');
$assert(str_contains($scaffoldedWorkflow, "automation:dependency-update"), 'Expected scaffolded updates workflow to gate closed-PR reconciliation to framework automation PRs.');

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
$compactWorkflow = (string) file_get_contents($compactScaffoldRoot . '/.github/workflows/wporg-updates.yml');
$assert(str_contains($compactWorkflow, "automation:framework-update"), 'Expected compact scaffold to keep merged automation PR reconciliation in the updates workflow.');

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
    wordPressOrgClient: $wpClient,
    gitHubReleaseClient: $gitHubReleaseClient,
    httpClient: $httpClient,
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
