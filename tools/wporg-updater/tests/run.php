<?php

declare(strict_types=1);

use WpOrgPluginUpdater\PrBodyRenderer;
use WpOrgPluginUpdater\ReleaseClassifier;
use WpOrgPluginUpdater\SupportForumClient;
use WpOrgPluginUpdater\WordPressCoreClient;
use WpOrgPluginUpdater\WordPressOrgClient;
use WpOrgPluginUpdater\Config;

require dirname(__DIR__) . '/src/Autoload.php';

$fixtureDir = __DIR__ . '/fixtures';
$classifier = new ReleaseClassifier();
$wpClient = new WordPressOrgClient(new WpOrgPluginUpdater\HttpClient());
$coreClient = new WordPressCoreClient(new WpOrgPluginUpdater\HttpClient());
$supportClient = new SupportForumClient(new WpOrgPluginUpdater\HttpClient(), 30);
$renderer = new PrBodyRenderer();

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$assert($classifier->classifyScope('5.3.6', '5.3.7') === 'patch', 'Expected patch classification.');
$assert($classifier->classifyScope('5.3.7', '5.4.0') === 'minor', 'Expected minor classification.');
$assert($classifier->classifyScope('5.4.0', '6.0.0') === 'major', 'Expected major classification.');

$labels = $classifier->deriveLabels('patch', 'Security fix for comment validation.', []);
$assert(in_array('type:security-bugfix', $labels, true), 'Patch releases must be labeled as security-bugfix.');

$pluginInfo = json_decode((string) file_get_contents($fixtureDir . '/akismet-plugin-info.json'), true, 512, JSON_THROW_ON_ERROR);
$changelog = $wpClient->extractChangelogSection((string) $pluginInfo['sections']['changelog'], '5.6');
$assert(str_contains($changelog, 'Release Date'), 'Expected changelog section to include the release date.');
$assert(str_contains($changelog, 'Improve caching of compatible plugins.'), 'Expected changelog section to include current release items.');

$feedItems = $supportClient->parseFeed((string) file_get_contents($fixtureDir . '/akismet-support-feed.xml'));
$assert(count($feedItems) > 1, 'Expected support feed fixture to contain topics.');
$assert($feedItems[0]['title'] === 'Akismet Flagging Gravity Forms Submissions as Spam', 'Expected feed parser to strip markup from titles.');

$listingTopics = $supportClient->parseSupportListing((string) file_get_contents($fixtureDir . '/akismet-support.html'));
$assert(count($listingTopics) > 10, 'Expected support listing parser to find topics.');

$openedAt = $supportClient->extractTopicPublishedAt((string) file_get_contents($fixtureDir . '/akismet-topic.html'));
$assert($openedAt->format('Y-m-d\TH:i:sP') === '2026-03-12T11:00:14+00:00', 'Expected topic page parser to extract article:published_time.');

$body = $renderer->render(
    pluginName: 'Akismet Anti-spam',
    pluginSlug: 'akismet',
    pluginPath: 'wp-content/plugins/akismet',
    currentVersion: '5.5',
    targetVersion: '5.6',
    releaseScope: 'minor',
    releaseAt: '2025-11-12T16:31:00+00:00',
    labels: ['automation:plugin-update', 'release:minor', 'type:feature'],
    pluginUrl: 'https://wordpress.org/plugins/akismet/',
    supportUrl: 'https://wordpress.org/support/plugin/akismet/',
    changelogHtml: $changelog,
    supportTopics: [$feedItems[0]],
    metadata: [
        'slug' => 'akismet',
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
$assert($supportTopicsFromBody[0]['url'] === $feedItems[0]['url'], 'Expected support topic parser to extract the topic URL.');

$tempConfigPath = sys_get_temp_dir() . '/wporg-updater-config-' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($tempConfigPath, <<<'PHP'
<?php
return [
    'base_branch' => null,
    'support_max_pages' => 30,
    'github_api_base' => 'https://api.github.com',
    'dry_run' => false,
    'core' => ['enabled' => true],
    'plugins' => [
        [
            'slug' => 'woocommerce',
            'path' => 'wp-content/plugins/woocommerce',
            'main_file' => 'woocommerce.php',
            'enabled' => true,
            'support_max_pages' => 60,
            'extra_labels' => ['plugin:woocommerce'],
        ],
    ],
];
PHP);
$config = Config::load(__DIR__, $tempConfigPath);
$enabledPlugins = $config->enabledPlugins();
$assert($enabledPlugins[0]['support_max_pages'] === 60, 'Expected plugin support_max_pages override to load from config.');
unlink($tempConfigPath);

$corePayload = json_decode((string) file_get_contents($fixtureDir . '/wp-core-version-check.json'), true, 512, JSON_THROW_ON_ERROR);
$coreOffer = $coreClient->parseLatestStableOffer($corePayload);
$assert($coreOffer['version'] === '6.9.4', 'Expected latest stable core offer to be 6.9.4 in fixture.');

$coreRelease = $coreClient->findReleaseAnnouncementInFeed((string) file_get_contents($fixtureDir . '/wp-release-feed.xml'), '6.9.4');
$assert($coreRelease['release_url'] === 'https://wordpress.org/news/2026/03/wordpress-6-9-4-release/', 'Expected release feed lookup to find the core announcement URL.');
$assert(str_contains($coreRelease['release_text'], 'security'), 'Expected release summary to include security context.');

$coreBody = $renderer->renderCoreUpdate(
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
);

$coreMetadata = PrBodyRenderer::extractMetadata($coreBody);
$assert(is_array($coreMetadata) && $coreMetadata['kind'] === 'core', 'Expected core PR body metadata round-trip to work.');

fwrite(STDOUT, "All updater tests passed.\n");
