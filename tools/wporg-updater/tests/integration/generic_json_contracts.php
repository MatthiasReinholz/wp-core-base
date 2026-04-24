<?php

declare(strict_types=1);

use WpOrgPluginUpdater\AdminGovernanceExporter;
use WpOrgPluginUpdater\Cli\Handlers\DependencyAuthoringModeHandler;
use WpOrgPluginUpdater\CommandHelp;
use WpOrgPluginUpdater\Config;
use WpOrgPluginUpdater\DependencyAuthoringService;
use WpOrgPluginUpdater\DependencyMetadataResolver;
use WpOrgPluginUpdater\GenericJsonManagedSource;
use WpOrgPluginUpdater\InteractivePrompter;
use WpOrgPluginUpdater\JsonHttpTransport;
use WpOrgPluginUpdater\ManagedSourceRegistry;
use WpOrgPluginUpdater\ManifestWriter;
use WpOrgPluginUpdater\MutationLock;
use WpOrgPluginUpdater\PremiumCredentialsStore;
use WpOrgPluginUpdater\PrBodyRenderer;
use WpOrgPluginUpdater\ReleaseClassifier;
use WpOrgPluginUpdater\RuntimeInspector;
use WpOrgPluginUpdater\SupportForumClient;
use WpOrgPluginUpdater\Updater;
use WpOrgPluginUpdater\GitHubReleaseClient;
use WpOrgPluginUpdater\WordPressOrgClient;

final class FakeGenericJsonTransport implements JsonHttpTransport
{
    /** @var array<string, array<string, mixed>> */
    public array $jsonResponses = [];

    /** @var array<string, string> */
    public array $downloadBodies = [];

    /** @var list<string> */
    public array $jsonRequests = [];

    /** @var list<string> */
    public array $downloadRequests = [];

    public function getJsonWithOptions(string $url, array $headers = [], array $options = []): array
    {
        $this->jsonRequests[] = $url;

        if (! isset($this->jsonResponses[$url])) {
            throw new RuntimeException(sprintf('Unexpected JSON request: %s', $url));
        }

        return $this->jsonResponses[$url];
    }

    public function downloadToFileWithOptions(string $url, string $destination, array $headers = [], array $options = []): void
    {
        $this->downloadRequests[] = $url;

        if (! isset($this->downloadBodies[$url])) {
            throw new RuntimeException(sprintf('Unexpected download request: %s', $url));
        }

        file_put_contents($destination, $this->downloadBodies[$url]);
    }
}

/**
 * @param callable(bool,string):void $assert
 * @param array<string,mixed> $context
 */
function run_generic_json_contract_tests(callable $assert, array $context): void
{
    /** @var callable(string,array<int,array<string,mixed>>):void $writeManifest */
    $writeManifest = $context['writeManifest'];
    /** @var callable(string,string,string,string):void $createPluginArchive */
    $createPluginArchive = $context['createPluginArchive'];
    $repoRoot = (string) $context['repoRoot'];
    /** @var WpOrgPluginUpdater\HttpClient $httpClient */
    $httpClient = $context['httpClient'];

    $metadataUrl = 'https://updates.example.com/example-json-plugin/info.json';
    $downloadUrl = 'https://updates.example.com/downloads/example-json-plugin-3.2.1.zip';
    $transport = new FakeGenericJsonTransport();
    $transport->jsonResponses[$metadataUrl] = [
        'name' => 'Example JSON Plugin',
        'version' => '3.2.1',
        'download_url' => $downloadUrl,
        'requires' => '6.6',
        'tested' => '6.7',
        'requires_php' => '8.1',
        'last_updated' => '2026-04-18T12:30:00Z',
        'homepage' => 'https://plugins.example.com/example-json-plugin',
        'sections' => [
            'changelog' => '<h2>3.2.1</h2><p>GitLab-ready release.</p>',
        ],
    ];

    $genericJsonSource = new GenericJsonManagedSource($transport);
    $genericDependency = [
        'slug' => 'example-json-plugin',
        'kind' => 'plugin',
        'archive_subdir' => '',
        'source_config' => [
            'generic_json_url' => $metadataUrl,
        ],
    ];

    $catalog = $genericJsonSource->fetchCatalog($genericDependency);
    $assert($catalog['latest_version'] === '3.2.1', 'Expected generic-json catalog resolution to read the latest version from metadata.');
    $assert($catalog['latest_release_at'] === '2026-04-18T12:30:00+00:00', 'Expected generic-json catalog resolution to normalize the metadata timestamp.');
    $assert($transport->jsonRequests === [$metadataUrl], 'Expected generic-json catalog resolution to request the configured metadata URL.');

    $releaseData = $genericJsonSource->releaseDataForVersion($genericDependency, $catalog, '3.2.1', (string) $catalog['latest_release_at']);
    $assert($releaseData['download_url'] === $downloadUrl, 'Expected generic-json release data to use download_url from metadata.');
    $assert(str_contains((string) $releaseData['notes_text'], 'GitLab-ready release.'), 'Expected generic-json release data to convert changelog markup into reviewer-readable text.');
    $assert($releaseData['source_reference'] === $metadataUrl, 'Expected generic-json release data to use the metadata endpoint as the source reference.');

    $downloadPath = sys_get_temp_dir() . '/wporg-generic-json-download-' . bin2hex(random_bytes(4)) . '.zip';
    $transport->downloadBodies[$downloadUrl] = 'zip-payload';
    $genericJsonSource->downloadReleaseToFile($genericDependency, $releaseData, $downloadPath);
    $assert((string) file_get_contents($downloadPath) === 'zip-payload', 'Expected generic-json downloads to use the resolved archive URL.');
    $assert($transport->downloadRequests === [$downloadUrl], 'Expected generic-json downloads to request the resolved archive URL exactly once.');

    $versionMismatchRejected = false;

    try {
        $genericJsonSource->releaseDataForVersion($genericDependency, $catalog, '3.1.0', (string) $catalog['latest_release_at']);
    } catch (RuntimeException $exception) {
        $versionMismatchRejected = str_contains($exception->getMessage(), 'currently advertises only version 3.2.1');
    }

    $assert($versionMismatchRejected, 'Expected generic-json source resolution to reject target versions older than the currently advertised metadata version.');

    $missingTimestampTransport = new FakeGenericJsonTransport();
    $missingTimestampTransport->jsonResponses[$metadataUrl] = [
        'name' => 'Example JSON Plugin',
        'version' => '3.2.1',
        'download_url' => $downloadUrl,
    ];
    $missingTimestampRejected = false;

    try {
        (new GenericJsonManagedSource($missingTimestampTransport))->fetchCatalog($genericDependency);
    } catch (RuntimeException $exception) {
        $missingTimestampRejected = str_contains($exception->getMessage(), 'must define a valid release_at');
    }

    $assert($missingTimestampRejected, 'Expected generic-json metadata to require a real release timestamp instead of inventing one.');

    $configRoot = sys_get_temp_dir() . '/wporg-generic-json-config-' . bin2hex(random_bytes(4));
    mkdir($configRoot . '/cms/plugins', 0777, true);
    $writeManifest($configRoot, [[
        'name' => 'Example JSON Plugin',
        'slug' => 'example-json-plugin',
        'kind' => 'plugin',
        'management' => 'managed',
        'source' => 'generic-json',
        'path' => 'cms/plugins/example-json-plugin',
        'main_file' => 'example-json-plugin.php',
        'version' => '3.2.1',
        'checksum' => 'sha256:...',
        'archive_subdir' => '',
        'extra_labels' => ['plugin:example-json-plugin'],
        'source_config' => [
            'generic_json_url' => $metadataUrl,
            'verification_mode' => 'none',
        ],
        'policy' => [
            'class' => 'managed-private',
            'allow_runtime_paths' => [],
            'sanitize_paths' => [],
            'sanitize_files' => [],
        ],
    ]]);
    $loadedConfig = Config::load($configRoot);
    $loadedDependency = $loadedConfig->dependencyByKey('plugin:generic-json:example-json-plugin');
    $assert(($loadedDependency['source_config']['generic_json_url'] ?? null) === $metadataUrl, 'Expected Config::load to retain source_config.generic_json_url for generic-json dependencies.');
    $assert(($loadedDependency['policy']['class'] ?? null) === 'managed-private', 'Expected generic-json dependencies to normalize to the managed-private policy class.');
    $assert(isset(Updater::labelDefinitions()['source:generic-json']), 'Expected updater label definitions to declare the generic-json source label.');

    $invalidConfigRoot = sys_get_temp_dir() . '/wporg-generic-json-invalid-' . bin2hex(random_bytes(4));
    mkdir($invalidConfigRoot . '/cms/plugins', 0777, true);
    $writeManifest($invalidConfigRoot, [[
        'name' => 'Invalid JSON Plugin',
        'slug' => 'invalid-json-plugin',
        'kind' => 'plugin',
        'management' => 'managed',
        'source' => 'generic-json',
        'path' => 'cms/plugins/invalid-json-plugin',
        'main_file' => 'invalid-json-plugin.php',
        'version' => '1.0.0',
        'checksum' => 'sha256:...',
        'archive_subdir' => '',
        'extra_labels' => ['plugin:invalid-json-plugin'],
        'source_config' => [
            'generic_json_url' => 'http://updates.example.com/invalid-json-plugin/info.json',
        ],
        'policy' => [
            'class' => 'managed-private',
            'allow_runtime_paths' => [],
            'sanitize_paths' => [],
            'sanitize_files' => [],
        ],
    ]]);
    $invalidGenericJsonRejected = false;

    try {
        Config::load($invalidConfigRoot);
    } catch (RuntimeException $exception) {
        $invalidGenericJsonRejected = str_contains($exception->getMessage(), 'must use an HTTPS source_config.generic_json_url');
    }

    $assert($invalidGenericJsonRejected, 'Expected Config::load to reject non-HTTPS generic-json metadata URLs.');

    $archivePath = sys_get_temp_dir() . '/wporg-generic-json-authoring-' . bin2hex(random_bytes(4)) . '.zip';
    $createPluginArchive($archivePath, 'example-json-plugin', 'example-json-plugin', '3.2.1');
    $transport->downloadBodies[$downloadUrl] = (string) file_get_contents($archivePath);

    $authoringRoot = sys_get_temp_dir() . '/wporg-generic-json-authoring-root-' . bin2hex(random_bytes(4));
    mkdir($authoringRoot . '/cms/plugins', 0777, true);
    $writeManifest($authoringRoot);
    $authoringConfig = Config::load($authoringRoot);
    $authoringService = new DependencyAuthoringService(
        config: $authoringConfig,
        metadataResolver: new DependencyMetadataResolver(),
        runtimeInspector: new RuntimeInspector($authoringConfig->runtime),
        manifestWriter: new ManifestWriter(),
        managedSourceRegistry: new ManagedSourceRegistry(
            new GenericJsonManagedSource($transport),
            new ExamplePremiumManagedSource($httpClient, new PremiumCredentialsStore('{}')),
        ),
        adminGovernanceExporter: new AdminGovernanceExporter(),
    );

    $addedGenericJsonDependency = $authoringService->addDependency([
        'source' => 'generic-json',
        'kind' => 'plugin',
        'slug' => 'example-json-plugin',
        'generic-json-url' => $metadataUrl,
    ]);
    $assert($addedGenericJsonDependency['component_key'] === 'plugin:generic-json:example-json-plugin', 'Expected add-dependency to use generic-json in the component key for generic JSON managed plugins.');
    $assert(($addedGenericJsonDependency['source_config']['generic_json_url'] ?? null) === $metadataUrl, 'Expected add-dependency to persist source_config.generic_json_url for generic-json managed plugins.');
    $assert($addedGenericJsonDependency['version'] === '3.2.1', 'Expected add-dependency to resolve the plugin version from the downloaded generic-json archive.');

    $missingGenericJsonUrlRejected = false;

    try {
        $authoringService->planAddDependency([
            'source' => 'generic-json',
            'kind' => 'plugin',
            'slug' => 'missing-json-url',
        ]);
    } catch (RuntimeException $exception) {
        $missingGenericJsonUrlRejected = str_contains($exception->getMessage(), '--generic-json-url');
    }

    $assert($missingGenericJsonUrlRejected, 'Expected non-interactive generic-json authoring to fail early when --generic-json-url is missing.');

    $addHelp = CommandHelp::render(
        'add-dependency',
        'vendor/wp-core-base/bin/wp-core-base',
        'php vendor/wp-core-base/tools/wporg-updater/bin/wporg-updater.php'
    );
    $assert(str_contains($addHelp, '--generic-json-url=URL'), 'Expected add-dependency help to document the generic-json metadata URL flag.');
    $assert(str_contains($addHelp, '--source=generic-json'), 'Expected add-dependency help examples to include the generic-json source.');

    $adoptHelp = CommandHelp::render(
        'adopt-dependency',
        'vendor/wp-core-base/bin/wp-core-base',
        'php vendor/wp-core-base/tools/wporg-updater/bin/wporg-updater.php'
    );
    $assert(str_contains($adoptHelp, 'local -> generic-json'), 'Expected adopt-dependency help to treat generic-json as a supported adoption target.');

    $promptHandler = new DependencyAuthoringModeHandler(
        config: $authoringConfig,
        managedSourceRegistry: new ManagedSourceRegistry(new GenericJsonManagedSource($transport)),
        adminGovernanceExporter: new AdminGovernanceExporter(),
        mutationLock: new MutationLock(),
        repoRoot: $authoringRoot,
        commandPrefix: 'vendor/wp-core-base/bin/wp-core-base',
        phpCommandPrefix: 'php vendor/wp-core-base/tools/wporg-updater/bin/wporg-updater.php',
        jsonOutput: false,
        emitJson: static function (array $payload): void {
        },
        premiumProviders: ['example-vendor'],
    );
    $handlerReflection = new ReflectionClass(DependencyAuthoringModeHandler::class);
    $maybePromptForMissing = $handlerReflection->getMethod('maybePromptForMissing');

    $promptInput = fopen('php://temp', 'r+');
    $promptOutput = fopen('php://temp', 'r+');
    $assert($promptInput !== false && $promptOutput !== false, 'Expected temp streams for generic-json prompting.');
    fwrite($promptInput, $metadataUrl . "\n");
    rewind($promptInput);
    $promptOptions = [
        'source' => 'generic-json',
        'kind' => 'plugin',
        'slug' => 'example-json-plugin',
    ];
    $promptArgs = [&$promptOptions, new InteractivePrompter($promptInput, $promptOutput)];
    $maybePromptForMissing->invokeArgs($promptHandler, $promptArgs);
    $assert(
        ($promptOptions['generic-json-url'] ?? null) === $metadataUrl,
        'Expected interactive generic-json authoring to prompt for the metadata URL.'
    );
    fclose($promptInput);
    fclose($promptOutput);

    $renderedGenericJsonPr = (new PrBodyRenderer())->renderDependencyUpdate(
        dependencyName: 'Example JSON Plugin',
        dependencySlug: 'example-json-plugin',
        dependencyKind: 'plugin',
        dependencyPath: 'cms/plugins/example-json-plugin',
        currentVersion: '3.1.0',
        targetVersion: '3.2.1',
        releaseScope: 'minor',
        releaseAt: '2026-04-18T12:30:00+00:00',
        labels: ['automation:dependency-update', 'source:generic-json'],
        sourceDetails: [['label' => 'Metadata endpoint', 'value' => sprintf('[Open](%s)', $metadataUrl)]],
        releaseNotesHeading: 'Release Notes',
        releaseNotesBody: '<p>GitLab-ready release.</p>',
        supportTopics: [],
        metadata: [
            'source' => 'generic-json',
            'target_version' => '3.2.1',
            'release_at' => '2026-04-18T12:30:00+00:00',
            'scope' => 'minor',
            'branch' => 'codex/update-example-json-plugin-3-2-1',
            'blocked_by' => [],
        ],
    );
    $assert(str_contains($renderedGenericJsonPr, 'generic JSON metadata updater automation'), 'Expected PR rendering to describe generic-json automation accurately.');

    $automationClient = new FakeGitHubAutomationClient();
    $updater = new Updater(
        config: $authoringConfig,
        dependencyScanner: new \WpOrgPluginUpdater\DependencyScanner(),
        wordPressOrgClient: new WordPressOrgClient($httpClient),
        gitHubReleaseClient: new GitHubReleaseClient($httpClient),
        managedSourceRegistry: new ManagedSourceRegistry(new GenericJsonManagedSource($transport)),
        supportForumClient: new SupportForumClient($httpClient, 1),
        releaseClassifier: new ReleaseClassifier(),
        prBodyRenderer: new PrBodyRenderer(),
        automationClient: $automationClient,
        gitRunner: new FakeGitRunner(),
        runtimeInspector: new RuntimeInspector($authoringConfig->runtime),
        manifestWriter: new ManifestWriter(),
        httpClient: $httpClient,
        adminGovernanceExporter: new AdminGovernanceExporter(),
    );
    $updaterReflection = new ReflectionClass(Updater::class);
    $pruneLatestOnlyPullRequests = $updaterReflection->getMethod('pruneHistoricalPullRequestsForLatestOnlySource');
    $remainingPlannedPrs = $pruneLatestOnlyPullRequests->invoke(
        $updater,
        [
            'source' => 'generic-json',
            'component_key' => 'plugin:generic-json:example-json-plugin',
        ],
        [
            ['number' => 41, 'planned_target_version' => '3.1.0'],
            ['number' => 42, 'planned_target_version' => '3.2.1'],
        ],
        '3.2.1'
    );
    $assert(array_column($remainingPlannedPrs, 'number') === [42], 'Expected latest-only generic-json pruning to drop historical PR targets once a newer advertised version exists.');
    $assert(count($automationClient->closedPullRequests) === 1 && $automationClient->closedPullRequests[0]['number'] === 41, 'Expected latest-only generic-json pruning to close the superseded older PR.');

    $readmeContents = (string) file_get_contents($repoRoot . '/README.md');
    $assert(str_contains($readmeContents, 'generic JSON metadata endpoints'), 'Expected the README support summary to mention generic JSON metadata endpoints.');
}
