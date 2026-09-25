<?php

declare(strict_types=1);

use WpOrgPluginUpdater\Cli\CommandOptions;
use WpOrgPluginUpdater\Config;
use WpOrgPluginUpdater\GenericJsonManagedSource;
use WpOrgPluginUpdater\HistoricalVersionSource;
use WpOrgPluginUpdater\HttpClient;
use WpOrgPluginUpdater\ManagedDependencySource;
use WpOrgPluginUpdater\ManagedSourceRegistry;
use WpOrgPluginUpdater\ManifestWriter;
use WpOrgPluginUpdater\PremiumProviderRegistry;
use WpOrgPluginUpdater\PremiumProviderScaffolder;
use WpOrgPluginUpdater\PremiumSourceResolver;
use WpOrgPluginUpdater\RuntimeInspector;
use WpOrgPluginUpdater\SourceCatalog;
use WpOrgPluginUpdater\SourceRelease;
use WpOrgPluginUpdater\UpdatePlan;

class ConfigurationContractSource implements ManagedDependencySource
{
    /** @param array<string,mixed> $catalog @param array<string,mixed> $release */
    public function __construct(private readonly string $sourceKey, private readonly array $catalog = [], private readonly array $release = []) {}
    public function key(): string { return $this->sourceKey; }
    public function fetchCatalog(array $dependency): array { return $this->catalog; }
    public function releaseDataForVersion(array $dependency, array $catalog, string $targetVersion, string $fallbackReleaseAt): array { return $this->release; }
    public function downloadReleaseToFile(array $dependency, array $releaseData, string $destination): void { throw new RuntimeException('This contract must not download.'); }
    public function supportsForumSync(array $dependency): bool { return false; }
}

/** @param callable(bool,string):void $assert */
function run_configuration_planning_contract_tests(callable $assert, string $frameworkRoot): void
{
    $root = sys_get_temp_dir() . '/wp-core-base-configuration-contracts-' . bin2hex(random_bytes(8));
    mkdir($root . '/.wp-core-base', 0700, true);
    mkdir($root . '/cms/plugins', 0700, true);
    $base = [
        'profile' => 'content-only',
        'paths' => ['content_root' => 'cms', 'plugins_root' => 'cms/plugins', 'themes_root' => 'cms/themes', 'mu_plugins_root' => 'cms/mu-plugins'],
        'core' => ['mode' => 'external', 'enabled' => false],
        'dependencies' => [['slug' => 'demo', 'kind' => 'plugin', 'management' => 'local', 'source' => 'local', 'path' => 'cms/plugins/demo', 'main_file' => 'demo.php']],
    ];
    $inspector = new RuntimeInspector(Config::fromArray($root, $base)->runtime);
    $throws = static function (callable $callback): bool {
        try { $callback(); return false; } catch (RuntimeException) { return true; }
    };
    try {
        foreach (['strict', 'relaxed'] as $mode) {
            foreach (['local', 'ignored'] as $management) {
                $duplicate = $base;
                $duplicate['runtime']['manifest_mode'] = $mode;
                $copy = $base['dependencies'][0];
                $copy['path'] = 'cms/plugins/different-path';
                $copy['management'] = $management;
                $duplicate['dependencies'][] = $copy;
                $assert($throws(static fn () => Config::fromArray($root, $duplicate)), 'Dependency identity must be unique regardless of manifest mode or local/ignored ownership.');
            }
        }
        $rootContent = $base;
        $rootContent['paths'] = ['content_root' => '.', 'plugins_root' => 'custom-plugins', 'themes_root' => 'custom-themes', 'mu_plugins_root' => 'custom-mu'];
        $rootContent['dependencies'][0]['path'] = 'custom-plugins/demo';
        $assert(Config::fromArray($root, $rootContent)->paths['content_root'] === '.', 'A repository-root content architecture must keep legitimate custom runtime roots.');
        $installedFramework = require $frameworkRoot . '/.wp-core-base/framework.php';
        $installedFramework['distribution']['path'] = 'lib/wp-core-base';
        file_put_contents($root . '/.wp-core-base/framework.php', '<?php return ' . var_export($installedFramework, true) . ';');
        foreach (['strict', 'relaxed'] as $mode) {
            foreach (['local', 'managed'] as $management) {
                foreach (['.', '.git', '.git/objects', '.github/workflows', '.gitlab', '.gitea', '.forgejo', '.circleci', '.wp-core-base', '.wp-core-base/build', 'tools', 'tools/wporg-updater/src', 'bin/wp-core-base', 'lib', 'lib/wp-core-base', 'lib/wp-core-base/tools'] as $path) {
                    $unsafe = $rootContent;
                    $unsafe['runtime']['manifest_mode'] = $mode;
                    $unsafe['paths']['plugins_root'] = '.';
                    $unsafe['dependencies'][0]['path'] = $path;
                    $unsafe['dependencies'][0]['management'] = $management;
                    if ($management === 'managed') {
                        $unsafe['dependencies'][0]['source'] = 'github-release';
                        $unsafe['dependencies'][0]['version'] = '1.0.0';
                        $unsafe['dependencies'][0]['checksum'] = 'sha256:' . str_repeat('a', 64);
                        $unsafe['dependencies'][0]['source_config'] = ['github_repository' => 'owner/demo', 'verification_mode' => 'none'];
                    }
                    $assert($throws(static fn () => Config::fromArray($root, $unsafe)), 'Managed/local dependencies must never own control trees in either manifest mode: ' . $path);
                }
            }
        }
        $ignoredControl = $rootContent;
        $ignoredControl['dependencies'] = [['slug' => 'git-metadata', 'kind' => 'runtime-directory', 'management' => 'ignored', 'source' => 'local', 'path' => '.git']];
        $assert(Config::fromArray($root, $ignoredControl)->dependencies()[0]['management'] === 'ignored', 'Safe ignored entries may document control paths without granting mutation ownership.');
        $rootLocal = $rootContent;
        $rootLocal['dependencies'] = [['slug' => 'bootstrap', 'kind' => 'runtime-file', 'management' => 'local', 'source' => 'local', 'path' => 'bootstrap.php']];
        $rootLocalConfig = Config::fromArray($root, $rootLocal);
        $assert($rootLocalConfig->dependencies()[0]['path'] === 'bootstrap.php', 'Project-owned runtime files at the content root remain valid.');
        $assert($throws(static fn () => $rootLocalConfig->stageDir('bootstrap.php')), 'Explicit runtime dependency paths must also be protected from stage output overlap.');
        $overlap = $base;
        $copy = $base['dependencies'][0];
        $copy['slug'] = 'distinct';
        $overlap['dependencies'][] = $copy;
        $assert($throws(static fn () => Config::fromArray($root, $overlap)), 'Strict mode still rejects distinct identities sharing runtime paths.');
        $overlap['runtime']['manifest_mode'] = 'relaxed';
        $assert(count(Config::fromArray($root, $overlap)->dependencies()) === 2, 'Relaxed migration mode intentionally retains distinct identity path overlap.');

        foreach ([['managed_release_min_age_hour' => 24], ['managed_release_min_age_hours' => false], ['managed_release_min_age_hours' => 1.5], ['managed_release_min_age_hours' => null], ['github_release_verification' => false], ['extensions' => null], 'invalid'] as $security) {
            $invalid = $base;
            $invalid['security'] = $security;
            $assert($throws(static fn () => Config::fromArray($root, $invalid)), 'Unknown security fields and wrong types must fail explicitly.');
        }
        foreach ([['min_release_age_hour' => 24], ['min_release_age_hours' => false], ['verification_mode' => true], ['provider_product_id' => '12junk'], ['provider_product_id' => 2.7], ['extensions' => 'invalid'], 'invalid'] as $sourceConfig) {
            $invalid = $base;
            $invalid['dependencies'][0]['source_config'] = $sourceConfig;
            $assert($throws(static fn () => Config::fromArray($root, $invalid)), 'Unknown source configuration fields and wrong types must fail explicitly.');
        }
        foreach (['../outside', '/absolute', 'nested/..', "nested/\0bad"] as $archivePath) {
            $invalid = $base;
            $invalid['dependencies'][0]['archive_subdir'] = $archivePath;
            $assert($throws(static fn () => Config::fromArray($root, $invalid)), 'Manifest archive subdirectories must not escape extracted payloads.');
        }
        $extended = $base;
        $extended['extensions'] = ['project' => ['owner' => 'engineering', 'enabled' => true]];
        $extended['security']['extensions'] = ['audit' => ['policy' => 2]];
        $extended['dependencies'][0]['source_config']['extensions'] = ['vendor' => ['channel' => 'stable', 'product' => 17]];
        $config = Config::fromArray($root, $extended);
        (new ManifestWriter())->write($config);
        $roundTrip = Config::load($root)->toArray();
        $assert($roundTrip['extensions'] === $extended['extensions'], 'Project extension namespaces must survive manifest write/read.');
        $assert($roundTrip['security']['extensions'] === $extended['security']['extensions'], 'Security extension namespaces must survive manifest write/read.');
        $assert($roundTrip['dependencies'][0]['source_config']['extensions'] === $extended['dependencies'][0]['source_config']['extensions'], 'Adapter source extensions must survive manifest write/read.');
        $assert($config->withDependencies($config->dependencies())->extensions === $config->extensions, 'Dependency mutations must preserve project extensions.');
        $assert($throws(static fn () => $config->withDependencies([$config->dependencies()[0], $config->dependencies()[0]])), 'In-memory dependency replacement must also enforce unique identities.');

        $scaffolder = new PremiumProviderScaffolder($frameworkRoot, $root);
        foreach (PremiumSourceResolver::allowedSources() as $reserved) {
            $assert($throws(static fn () => $scaffolder->scaffold($reserved)), 'Premium scaffolding must reject reserved source key ' . $reserved);
        }
        foreach (['/tmp/provider.php', '../provider.php', 'providers/..', 'C:provider.php', 'C:/provider.php', "providers/\0evil.php", '.wp-core-base/manifest.php', '.wp-core-base/premium-providers.php'] as $path) {
            $assert($throws(static fn () => $scaffolder->scaffold('sample', null, $path)), 'Premium class paths must reject traversal, absolute paths and control-file collisions.');
        }
        mkdir($root . '/outside', 0700);
        file_put_contents($root . '/outside/KEEP', 'preserved');
        symlink($root . '/outside', $root . '/linked-provider');
        $assert($throws(static fn () => $scaffolder->scaffold('sample', null, 'linked-provider/provider.php')), 'Premium scaffolding must reject symlink ancestors before creating files.');
        $assert(file_get_contents($root . '/outside/KEEP') === 'preserved' && ! file_exists($root . '/outside/provider.php'), 'Rejected premium paths must leave unrelated files unchanged.');
        $assert($throws(static fn () => $scaffolder->scaffold('sample', 'Invalid; injected')), 'Premium class names must be validated before rendering executable PHP.');
        $scaffolded = $scaffolder->scaffold('sample');
        $assert(is_file($root . '/' . $scaffolded['path']) && PremiumProviderRegistry::load($root)->hasProvider('sample'), 'Valid custom providers must continue to scaffold and register.');
        $registryPath = $root . '/.wp-core-base/premium-providers.php';
        $validRegistry = file_get_contents($registryPath);
        foreach (['/tmp/invalid.php', '../invalid.php', 'linked-provider/provider.php'] as $path) {
            file_put_contents($registryPath, '<?php return ' . var_export(['sample' => ['class' => 'Sample', 'path' => $path]], true) . ';');
            $assert($throws(static fn () => PremiumProviderRegistry::load($root)), 'Premium registry paths must share canonical path and symlink validation.');
        }
        file_put_contents($registryPath, '<?php return ' . var_export(['generic-json' => ['class' => 'Sample']], true) . ';');
        $assert($throws(static fn () => PremiumProviderRegistry::load($root)), 'A premium registry must not shadow generic-json.');
        file_put_contents($registryPath, $validRegistry);

        $catalog = ['latest_version' => '2.0.1', 'latest_release_at' => '2026-09-25T08:00:00+00:00', 'custom_payload' => ['opaque' => true]];
        $release = ['version' => '2.0.1', 'release_at' => '2026-09-25T08:00:00Z', 'provider_ticket' => ['id' => 42]];
        $legacy = new ConfigurationContractSource('legacy-vendor', $catalog, $release);
        $registry = new ManagedSourceRegistry($legacy);
        $dependency = ['source' => 'premium', 'source_config' => ['provider' => 'legacy-vendor']];
        $assert($registry->for($dependency) === $legacy, 'Registry must preserve adapter identity and the existing ManagedDependencySource API.');
        $assert($registry->supportsHistoricalVersions($dependency), 'Legacy custom providers must retain their historical-version behavior.');
        $assert($registry->fetchCatalog($dependency)['custom_payload'] === ['opaque' => true], 'Catalog validation must retain opaque custom adapter metadata.');
        $assert($registry->releaseDataForVersion($dependency, $catalog, '2.0.1', $catalog['latest_release_at'])['provider_ticket'] === ['id' => 42], 'Release validation must retain opaque custom adapter metadata.');
        $assert($throws(static fn () => new ManagedSourceRegistry($legacy, $legacy)), 'Duplicate adapter keys must fail instead of replacing an earlier source.');
        $assert($throws(static fn () => new ManagedSourceRegistry(new ConfigurationContractSource(''))), 'Empty adapter keys must fail.');
        $latestOnly = new class('latest-vendor', $catalog, $release) extends ConfigurationContractSource implements HistoricalVersionSource {
            public function supportsHistoricalVersions(array $dependency): bool { return false; }
        };
        $latestRegistry = new ManagedSourceRegistry($latestOnly);
        $latestDependency = ['source' => 'premium', 'source_config' => ['provider' => 'latest-vendor']];
        $assert(! $latestRegistry->supportsHistoricalVersions($latestDependency), 'History capability must follow the registered adapter, including custom premium providers.');
        $assert($throws(static fn () => $latestRegistry->releaseDataForVersion($latestDependency, $catalog, '2.0.0', $catalog['latest_release_at'])), 'Latest-only capability must prevent unsupported historical resolution.');
        $assert(! (new ManagedSourceRegistry(new GenericJsonManagedSource(new HttpClient())))->supportsHistoricalVersions(['source' => 'generic-json']), 'Generic JSON must explicitly advertise latest-only capability.');
        foreach ([[], ['latest_version' => 3, 'latest_release_at' => '2026-09-25T08:00:00Z'], ['latest_version' => '1', 'latest_release_at' => 'tomorrow'], ['latest_version' => '1', 'latest_release_at' => '2026-02-30T08:00:00Z']] as $invalidCatalog) {
            $assert($throws(static fn () => SourceCatalog::fromArray($invalidCatalog, 'sample')), 'Malformed catalog minimum records must fail before planning.');
        }
        $assert($throws(static fn () => SourceRelease::fromArray($release, 'sample', '2.0.0')), 'Resolved release identity must match the requested version.');
        $assert($throws(static fn () => SourceRelease::fromArray(['version' => '2.0.1'], 'sample', '2.0.1')), 'Release records must declare a valid publication timestamp.');

        $metadata = ['base_version' => '1.4.0', 'base_revision' => 'revision-a', 'target_version' => '2.0.0', 'release_at' => '2026-09-24T08:00:00Z', 'scope' => 'patch'];
        $plan = UpdatePlan::refresh($metadata, '1.4.0', '2.0.1', '2026-09-25T08:00:00Z', 'revision-a');
        $assert($plan->scope === 'major' && $plan->targetVersion === '2.0.1' && $plan->requiresBranchRefresh, 'A queued major upgrade followed by an upstream patch must remain a major PR.');
        $assert(UpdatePlan::scope('1.4.0', '2.0.1') === 'major', 'New queued PR scope must be measured against installed base rather than a queued target.');
        $plan = UpdatePlan::refresh($metadata, '2.0.0', '2.0.1', '2026-09-25T08:00:00Z', 'revision-b');
        $assert($plan->baseVersion === '2.0.0' && $plan->scope === 'patch' && $plan->requiresBranchRefresh, 'When the base advances, stale metadata and scope must be recalculated.');
        $metadata['target_version'] = '1.5.0';
        $plan = UpdatePlan::refresh($metadata, '1.4.0', '2.0.1', '2026-09-25T08:00:00Z', 'revision-a');
        $assert($plan->targetVersion === '1.5.0' && $plan->scope === 'minor' && ! $plan->requiresBranchRefresh, 'A newer major release must not retarget an existing different-line PR.');
        $metadata['scope'] = 'major';
        $metadata['target_version'] = '1.4.1';
        $plan = UpdatePlan::refresh($metadata, '1.4.0', '1.4.1', '2026-09-25T08:00:00Z', 'revision-a');
        $assert($plan->scope === 'patch' && ! $plan->requiresBranchRefresh, 'Correcting stale labels alone must not force unnecessary code updates.');
        $plan = UpdatePlan::refresh($metadata, '1.5.0', '1.5.0', '2026-09-25T08:00:00Z', '');
        $assert($plan->scope === 'none' && $plan->requiresBranchRefresh, 'A changed effective base must invalidate stale metadata even without a recorded Git revision.');

        foreach ([\WpOrgPluginUpdater\Updater::class, \WpOrgPluginUpdater\CoreUpdater::class, \WpOrgPluginUpdater\FrameworkSyncer::class] as $updaterClass) {
            $reflection = new ReflectionClass($updaterClass);
            $updater = $reflection->newInstanceWithoutConstructor();
            $reflection->getProperty('releaseClassifier')->setValue($updater, new \WpOrgPluginUpdater\ReleaseClassifier());
            $prMetadata = ['base_version' => '1.3.0', 'base_revision' => 'old-revision', 'target_version' => '2.0.0', 'release_at' => '2026-09-24T08:00:00Z', 'scope' => 'patch'];
            $pullRequest = ['number' => 1, 'metadata' => $prMetadata, 'body' => '<!-- wporg-update-metadata: ' . json_encode($prMetadata, JSON_THROW_ON_ERROR) . ' -->'];
            $planned = $reflection->getMethod('planExistingPullRequest')->invoke($updater, $pullRequest, '1.4.0', '2.0.1', '2026-09-25T08:00:00Z', 'new-revision');
            $assert($planned['planned_scope'] === 'major' && $planned['metadata']['base_version'] === '1.4.0', 'Each updater must actually apply shared effective-base planning to refreshed PRs.');
        }

        foreach ([['stage-runtime', ['--source=local']], ['doctor', ['--force']], ['add-dependency', ['--force=false']], ['doctor', ['--json=true']], ['stage-runtime', ['--output']], ['stage-runtime', ['--output=']], ['stage-runtime', ['--output', 'build/runtime']], ['doctor', ['extra']], ['doctor', ['--json', '--json']], ['sync', ['--max_body_bytes=99']], ['help', ['sync', 'doctor']], ['add-dependency', ['--provider-product-id=12junk']], ['pr-blocker', ['--pr-number=0']]] as [$mode, $arguments]) {
            $assert($throws(static fn () => CommandOptions::parse($mode, $arguments)), 'CLI must reject ignored, malformed, or ambiguous arguments before reading project files.');
        }
        $parsed = CommandOptions::parse('build-release-artifact', ['--output=/tmp/release.zip', '--source-revision=abc123', '--fixture', '--json']);
        $assert($parsed['source-revision'] === 'abc123' && $parsed['fixture'] === true, 'Release CLI must expose explicit immutable revision and fixture-build options.');
        $assert(CommandOptions::parse('help', ['framework-sync']) === [], 'The documented help COMMAND form must remain valid.');
        $assert(CommandOptions::parse('scaffold-premium-provider', ['--provider=sample', '--force'])['force'] === true, 'Bare supported flags must retain true semantics.');
    } finally {
        $inspector->clearPath($root);
    }
}
