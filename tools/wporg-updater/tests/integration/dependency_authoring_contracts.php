<?php

declare(strict_types=1);

use WpOrgPluginUpdater\AbstractPremiumManagedSource;
use WpOrgPluginUpdater\AdminGovernanceExporter;
use WpOrgPluginUpdater\ArchiveDownloader;
use WpOrgPluginUpdater\CommandHelp;
use WpOrgPluginUpdater\Config;
use WpOrgPluginUpdater\ConfigWriter;
use WpOrgPluginUpdater\Cli\Handlers\DependencyAuthoringModeHandler;
use WpOrgPluginUpdater\DependencyAuthoringService;
use WpOrgPluginUpdater\DependencyMetadataResolver;
use WpOrgPluginUpdater\ExtractedPayloadLocator;
use WpOrgPluginUpdater\FrameworkRuntimeFiles;
use WpOrgPluginUpdater\GitHubReleaseClient;
use WpOrgPluginUpdater\GitHubReleaseManagedSource;
use WpOrgPluginUpdater\GitHubReleaseSource;
use WpOrgPluginUpdater\HttpClient;
use WpOrgPluginUpdater\InteractivePrompter;
use WpOrgPluginUpdater\ManagedSourceRegistry;
use WpOrgPluginUpdater\ManifestSuggester;
use WpOrgPluginUpdater\ManifestWriter;
use WpOrgPluginUpdater\MutationLock;
use WpOrgPluginUpdater\PremiumCredentialsStore;
use WpOrgPluginUpdater\PremiumProviderRegistry;
use WpOrgPluginUpdater\PremiumProviderScaffolder;
use WpOrgPluginUpdater\RuntimeInspector;
use WpOrgPluginUpdater\SupportForumClient;
use WpOrgPluginUpdater\WordPressOrgClient;
use WpOrgPluginUpdater\WordPressOrgManagedSource;
use WpOrgPluginUpdater\WordPressOrgSource;

/**
 * @param callable(bool,string):void $assert
 * @param array<string,mixed> $context
 */
function run_dependency_authoring_contract_tests(callable $assert, array $context): void
{
    $repoRoot = (string) $context['repoRoot'];
    /** @var callable(string,array<int,array<string,mixed>>):void $writeManifest */
    $writeManifest = $context['writeManifest'];
    /** @var callable(string,string,string,bool):void $createPluginArchive */
    $createPluginArchive = $context['createPluginArchive'];
    /** @var callable(WpOrgPluginUpdater\WordPressOrgSource,WpOrgPluginUpdater\GitHubReleaseSource,WpOrgPluginUpdater\ArchiveDownloader,?WpOrgPluginUpdater\HttpClient):WpOrgPluginUpdater\ManagedSourceRegistry $makeManagedSourceRegistry */
    $makeManagedSourceRegistry = $context['makeManagedSourceRegistry'];
    /** @var array<string,mixed> $runtimeDefaults */
    $runtimeDefaults = $context['runtimeDefaults'];
    /** @var WordPressOrgClient $wpClient */
    $wpClient = $context['wpClient'];
    /** @var HttpClient $httpClient */
    $httpClient = $context['httpClient'];
    /** @var GitHubReleaseClient $gitHubReleaseClient */
    $gitHubReleaseClient = $context['gitHubReleaseClient'];
    /** @var PremiumCredentialsStore $premiumCredentialsStore */
    $premiumCredentialsStore = $context['premiumCredentialsStore'];
    /** @var SupportForumClient $supportClient */
    $supportClient = $context['supportClient'];

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
    $assert(str_contains($addHelp, '--version=10.6.2'), 'Expected add-dependency help examples to reflect the current WooCommerce baseline.');

    $adoptHelp = CommandHelp::render(
        'adopt-dependency',
        'vendor/wp-core-base/bin/wp-core-base',
        'php vendor/wp-core-base/tools/wporg-updater/bin/wporg-updater.php'
    );
    $assert(str_contains($adoptHelp, '--preserve-version'), 'Expected adopt-dependency help to document version-preserving adoption.');
    $assert(str_contains($adoptHelp, 'atomic'), 'Expected adopt-dependency help to explain the atomic single-dependency workflow.');
    $assert(str_contains($adoptHelp, '--source=premium --provider=example-vendor'), 'Expected adopt-dependency help to show the registered premium source example.');
    $assert(str_contains($adoptHelp, '--version=10.6.2'), 'Expected adopt-dependency help examples to reflect the current WooCommerce baseline.');

    $generalHelp = CommandHelp::render(
        null,
        'vendor/wp-core-base/bin/wp-core-base',
        'php vendor/wp-core-base/tools/wporg-updater/bin/wporg-updater.php'
    );
    $assert(str_contains($generalHelp, '--signature-file=/path/to/wp-core-base-vendor-snapshot.zip.sha256.sig'), 'Expected general help to document signature-backed artifact verification.');

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

    $dependencyAuthoringReflection = new ReflectionClass(DependencyAuthoringService::class);
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
    $assert(
        DependencyAuthoringService::defaultGitLabTokenEnv('example-private-plugin') === 'WP_CORE_BASE_GITLAB_TOKEN_EXAMPLE_PRIVATE_PLUGIN',
        'Expected default GitLab token env names to normalize plugin slugs.'
    );
    $assert(
        DependencyAuthoringService::defaultGitLabTokenEnv('', 'group/private-plugin') === 'WP_CORE_BASE_GITLAB_TOKEN_PRIVATE_PLUGIN',
        'Expected default GitLab token env names to fall back to the project basename.'
    );

    $promptHandler = new DependencyAuthoringModeHandler(
        config: $authoringConfig,
        managedSourceRegistry: new ManagedSourceRegistry(
            new WordPressOrgManagedSource($wpClient, $httpClient),
            new GitHubReleaseManagedSource($gitHubReleaseClient),
            new ExamplePremiumManagedSource($httpClient, $premiumCredentialsStore),
        ),
        adminGovernanceExporter: new AdminGovernanceExporter(new RuntimeInspector($authoringConfig->runtime)),
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
    $maybePromptForMissing->setAccessible(true);

    $gitHubPromptInput = fopen('php://temp', 'r+');
    $gitHubPromptOutput = fopen('php://temp', 'r+');
    $assert($gitHubPromptInput !== false && $gitHubPromptOutput !== false, 'Expected temp streams for GitHub hosted-release prompting.');
    fwrite($gitHubPromptInput, "\n" . "n\n");
    rewind($gitHubPromptInput);
    $gitHubPromptOptions = [
        'source' => 'github-release',
        'kind' => 'plugin',
        'slug' => 'example-plugin',
        'github-repository' => 'owner/example-plugin',
    ];
    $gitHubPromptArgs = [&$gitHubPromptOptions, new InteractivePrompter($gitHubPromptInput, $gitHubPromptOutput)];
    $maybePromptForMissing->invokeArgs($promptHandler, $gitHubPromptArgs);
    $assert(
        $gitHubPromptOptions['github-release-asset-pattern'] === '*.zip',
        'Expected interactive GitHub hosted-release authoring to default the release asset pattern.'
    );
    fclose($gitHubPromptInput);
    fclose($gitHubPromptOutput);

    $gitLabPromptInput = fopen('php://temp', 'r+');
    $gitLabPromptOutput = fopen('php://temp', 'r+');
    $assert($gitLabPromptInput !== false && $gitLabPromptOutput !== false, 'Expected temp streams for GitLab hosted-release prompting.');
    fwrite($gitLabPromptInput, "\n" . "n\n");
    rewind($gitLabPromptInput);
    $gitLabPromptOptions = [
        'source' => 'gitlab-release',
        'kind' => 'plugin',
        'slug' => 'example-plugin',
        'gitlab-project' => 'group/example-plugin',
    ];
    $gitLabPromptArgs = [&$gitLabPromptOptions, new InteractivePrompter($gitLabPromptInput, $gitLabPromptOutput)];
    $maybePromptForMissing->invokeArgs($promptHandler, $gitLabPromptArgs);
    $assert(
        $gitLabPromptOptions['gitlab-release-asset-pattern'] === '*.zip',
        'Expected interactive GitLab hosted-release authoring to default the release asset pattern.'
    );
    fclose($gitLabPromptInput);
    fclose($gitLabPromptOutput);

    $missingGitHubAssetPatternRejected = false;

    try {
        $authoringService->planAddDependency([
            'source' => 'github-release',
            'kind' => 'plugin',
            'slug' => 'missing-github-pattern',
            'github-repository' => 'owner/missing-github-pattern',
        ]);
    } catch (RuntimeException $exception) {
        $missingGitHubAssetPatternRejected = str_contains($exception->getMessage(), '--github-release-asset-pattern');
    }

    $assert($missingGitHubAssetPatternRejected, 'Expected non-interactive GitHub hosted-release authoring to fail early when --github-release-asset-pattern is missing.');

    $missingGitLabAssetPatternRejected = false;

    try {
        $authoringService->planAddDependency([
            'source' => 'gitlab-release',
            'kind' => 'plugin',
            'slug' => 'missing-gitlab-pattern',
            'gitlab-project' => 'group/missing-gitlab-pattern',
        ]);
    } catch (RuntimeException $exception) {
        $missingGitLabAssetPatternRejected = str_contains($exception->getMessage(), '--gitlab-release-asset-pattern');
    }

    $assert($missingGitLabAssetPatternRejected, 'Expected non-interactive GitLab hosted-release authoring to fail early when --gitlab-release-asset-pattern is missing.');

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
}
