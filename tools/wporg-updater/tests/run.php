<?php

declare(strict_types=1);

use WpOrgPluginUpdater\Config;
use WpOrgPluginUpdater\CoreScanner;
use WpOrgPluginUpdater\DependencyScanner;
use WpOrgPluginUpdater\DownstreamScaffolder;
use WpOrgPluginUpdater\GitHubReleaseClient;
use WpOrgPluginUpdater\HttpClient;
use WpOrgPluginUpdater\ManifestWriter;
use WpOrgPluginUpdater\PrBodyRenderer;
use WpOrgPluginUpdater\ReleaseClassifier;
use WpOrgPluginUpdater\RuntimeInspector;
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
    'forbidden_paths' => ['.git', '.github', '.gitlab', '.circleci', '.wordpress-org', 'node_modules', 'docs', 'doc', 'tests', 'test', '__tests__', 'examples', 'example', 'demo', 'screenshots'],
    'forbidden_files' => ['README*', 'CHANGELOG*', '.gitignore', '.gitattributes', 'phpunit.xml*', 'composer.json', 'composer.lock', 'package.json', 'package-lock.json', 'pnpm-lock.yaml', 'yarn.lock'],
    'allow_runtime_paths' => [],
];

$assert($classifier->classifyScope('5.3.6', '5.3.7') === 'patch', 'Expected patch classification.');
$assert($classifier->classifyScope('5.3.7', '5.4.0') === 'minor', 'Expected minor classification.');
$assert($classifier->classifyScope('5.4.0', '6.0.0') === 'major', 'Expected major classification.');

$labels = $classifier->deriveLabels('source:wordpress.org', 'patch', 'Security fix for comment validation.', []);
$assert(in_array('type:security-bugfix', $labels, true), 'Patch releases must be labeled as security-bugfix.');

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

$config = Config::load($repoRoot);
$assert($config->profile === 'full-core', 'Expected repository manifest to load as full-core.');
$assert($config->coreManaged(), 'Expected repository manifest to manage WordPress core.');
$assert(count($config->managedDependencies()) === 4, 'Expected four managed baseline dependencies.');

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
mkdir($contentRoot . '/cms/themes/example-theme', 0777, true);
mkdir($contentRoot . '/cms/mu-plugins/bootstrap', 0777, true);
file_put_contents($contentRoot . '/cms/plugins/example-plugin/example-plugin.php', <<<'PHP'
<?php
/*
Plugin Name: Example Plugin
Version: 1.2.3
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
    'runtime' => $runtimeDefaults,
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
$contentStager = new RuntimeStager($loadedContentConfig, new RuntimeInspector($loadedContentConfig->runtime));
$contentPaths = $contentStager->stage('.wp-core-base/build/runtime');
$assert(in_array('cms/plugins/example-plugin', $contentPaths, true), 'Expected content-only runtime staging to include plugin path.');

$tempScaffoldRoot = sys_get_temp_dir() . '/wporg-scaffold-' . bin2hex(random_bytes(4));
mkdir($tempScaffoldRoot, 0777, true);
(new DownstreamScaffolder(dirname(__DIR__, 3), $tempScaffoldRoot))->scaffold('vendor/wp-core-base', 'content-only', 'cms', true);
$scaffoldedManifest = (string) file_get_contents($tempScaffoldRoot . '/.wp-core-base/manifest.php');
$scaffoldedWorkflow = (string) file_get_contents($tempScaffoldRoot . '/.github/workflows/wporg-updates.yml');
$scaffoldedValidate = (string) file_get_contents($tempScaffoldRoot . '/.github/workflows/wporg-validate-runtime.yml');
$assert(str_contains($scaffoldedManifest, "'profile' => 'content-only'"), 'Expected scaffolded manifest to set the requested profile.');
$assert(str_contains($scaffoldedManifest, "'content_root' => 'cms'"), 'Expected scaffolded manifest to set the requested content root.');
$assert(str_contains($scaffoldedWorkflow, 'php vendor/wp-core-base/tools/wporg-updater/bin/wporg-updater.php sync'), 'Expected scaffolded workflow to target the configured tool path.');
$assert(str_contains($scaffoldedValidate, 'stage-runtime'), 'Expected scaffolded validation workflow to stage runtime output.');

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
