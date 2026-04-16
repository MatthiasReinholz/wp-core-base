<?php

declare(strict_types=1);

use WpOrgPluginUpdater\AdminGovernanceExporter;
use WpOrgPluginUpdater\Config;
use WpOrgPluginUpdater\ConfigNormalizer;
use WpOrgPluginUpdater\ConfigMutationStateManager;
use WpOrgPluginUpdater\DependencyScanner;
use WpOrgPluginUpdater\FrameworkRuntimeFiles;
use WpOrgPluginUpdater\GitHubLabelSynchronizer;
use WpOrgPluginUpdater\HttpClient;
use WpOrgPluginUpdater\HttpStatusRuntimeException;
use WpOrgPluginUpdater\ManifestSuggester;
use WpOrgPluginUpdater\ManifestWriter;
use WpOrgPluginUpdater\PhpArrayFileWriter;
use WpOrgPluginUpdater\RuntimeInspector;
use WpOrgPluginUpdater\Cli\CliModeHandler;
use WpOrgPluginUpdater\Cli\ModeDispatcher;

/**
 * @param callable(bool,string):void $assert
 */
function run_seam_contract_tests(callable $assert, string $repoRoot): void
{
    $dispatchTracker = new class {
        /** @var list<string> */
        public array $calls = [];
    };
    $dispatcher = new ModeDispatcher([
        new class ($dispatchTracker) implements CliModeHandler
        {
            public function __construct(private readonly object $tracker)
            {
            }

            public function supports(string $mode): bool
            {
                return $mode === 'target';
            }

            public function handle(string $mode, array $options): int
            {
                $this->tracker->calls[] = 'first';
                return 7;
            }
        },
        new class ($dispatchTracker) implements CliModeHandler
        {
            public function __construct(private readonly object $tracker)
            {
            }

            public function supports(string $mode): bool
            {
                return $mode === 'target';
            }

            public function handle(string $mode, array $options): int
            {
                $this->tracker->calls[] = 'second';
                return 9;
            }
        },
    ]);
    $assert($dispatcher->dispatch('target', ['demo' => true]) === 7, 'Expected ModeDispatcher to return the first matching handler result.');
    $assert($dispatchTracker->calls === ['first'], 'Expected ModeDispatcher to stop at the first matching handler.');
    $assert($dispatcher->dispatch('missing', []) === null, 'Expected ModeDispatcher to return null when no handler supports a mode.');

    $arrayWriter = new PhpArrayFileWriter();
    $writerPath = sys_get_temp_dir() . '/wporg-array-writer-' . bin2hex(random_bytes(4)) . '.php';
    $arrayWriter->write($writerPath, ['alpha' => 'one', 'beta' => ['nested' => true]]);
    $writerFirst = (string) file_get_contents($writerPath);
    $arrayWriter->write($writerPath, ['alpha' => 'one', 'beta' => ['nested' => true]]);
    $writerSecond = (string) file_get_contents($writerPath);
    @unlink($writerPath);
    $assert($writerFirst === $writerSecond, 'Expected PhpArrayFileWriter to produce deterministic bytes for identical input.');

    $governanceRoot = sys_get_temp_dir() . '/wporg-governance-stable-' . bin2hex(random_bytes(4));
    mkdir($governanceRoot . '/.wp-core-base', 0777, true);
    mkdir($governanceRoot . '/cms/plugins/deterministic-plugin', 0777, true);
    mkdir($governanceRoot . '/cms/mu-plugins', 0777, true);
    file_put_contents(
        $governanceRoot . '/cms/plugins/deterministic-plugin/deterministic-plugin.php',
        "<?php\n/*\nPlugin Name: Deterministic Plugin\nVersion: 1.0.0\n*/\n"
    );
    $arrayWriter->write($governanceRoot . '/.wp-core-base/manifest.php', [
        'profile' => 'content-only',
        'paths' => [
            'content_root' => 'cms',
            'plugins_root' => 'cms/plugins',
            'themes_root' => 'cms/themes',
            'mu_plugins_root' => 'cms/mu-plugins',
        ],
        'core' => ['mode' => 'external', 'enabled' => false],
        'dependencies' => [[
            'name' => 'Deterministic Plugin',
            'slug' => 'deterministic-plugin',
            'kind' => 'plugin',
            'management' => 'local',
            'source' => 'local',
            'path' => 'cms/plugins/deterministic-plugin',
            'main_file' => 'deterministic-plugin.php',
            'version' => null,
            'checksum' => null,
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
            'policy' => [
                'class' => 'local-owned',
                'allow_runtime_paths' => [],
                'strip_paths' => [],
                'strip_files' => [],
                'sanitize_paths' => [],
                'sanitize_files' => [],
            ],
        ]],
    ]);
    $governanceConfig = Config::load($governanceRoot);
    $governanceExporter = new AdminGovernanceExporter(new RuntimeInspector($governanceConfig->runtime));
    $governanceExporter->refresh($governanceConfig);
    $governanceDataPath = $governanceRoot . '/' . FrameworkRuntimeFiles::governanceDataPath($governanceConfig);
    $governanceFirst = (string) file_get_contents($governanceDataPath);
    sleep(1);
    $governanceExporter->refresh($governanceConfig);
    $governanceSecond = (string) file_get_contents($governanceDataPath);
    $assert($governanceFirst === $governanceSecond, 'Expected admin governance refreshes to be byte-stable when the manifest is unchanged.');

    $governanceMigrationData = $governanceConfig->toArray();
    $governanceMigrationData['paths']['mu_plugins_root'] = 'cms/mu-plugins-migrated';
    mkdir($governanceRoot . '/cms/mu-plugins-migrated', 0777, true);
    $nextGovernanceConfig = Config::fromArray($governanceRoot, $governanceMigrationData, $governanceConfig->manifestPath);
    $stateManager = new ConfigMutationStateManager(
        new ManifestWriter(),
        new RuntimeInspector($governanceConfig->runtime),
        new AdminGovernanceExporter(new RuntimeInspector($governanceConfig->runtime))
    );
    $trackedState = $stateManager->snapshot($governanceConfig, $nextGovernanceConfig);
    $stateManager->persist($nextGovernanceConfig, $governanceConfig);
    $oldGovernancePath = $governanceRoot . '/' . FrameworkRuntimeFiles::governanceDataPath($governanceConfig);
    $newGovernancePath = $governanceRoot . '/' . FrameworkRuntimeFiles::governanceDataPath($nextGovernanceConfig);
    $assert(! is_file($oldGovernancePath), 'Expected config mutation persistence to remove stale governance data after mu_plugins_root migration.');
    $assert(is_file($newGovernancePath), 'Expected config mutation persistence to write governance data at the migrated mu_plugins_root path.');
    $stateManager->restore($trackedState);
    (new RuntimeInspector($governanceConfig->runtime))->clearPath($governanceRoot);

    $suggestRoot = sys_get_temp_dir() . '/wporg-manifest-suggestion-' . bin2hex(random_bytes(4));
    mkdir($suggestRoot . '/.wp-core-base', 0777, true);
    mkdir($suggestRoot . '/cms/plugins/suggested-plugin', 0777, true);
    file_put_contents(
        $suggestRoot . '/cms/plugins/suggested-plugin/suggested-plugin.php',
        "<?php\n/*\nPlugin Name: Suggested Plugin\nVersion: 1.0.0\n*/\n"
    );
    $arrayWriter->write($suggestRoot . '/.wp-core-base/manifest.php', [
        'profile' => 'content-only',
        'paths' => [
            'content_root' => 'cms',
            'plugins_root' => 'cms/plugins',
            'themes_root' => 'cms/themes',
            'mu_plugins_root' => 'cms/mu-plugins',
        ],
        'core' => ['mode' => 'external', 'enabled' => false],
        'runtime' => [
            'manifest_mode' => 'relaxed',
        ],
        'dependencies' => [],
    ]);
    $suggestConfig = Config::load($suggestRoot);
    $suggestions = (new ManifestSuggester($suggestConfig))->render();
    (new RuntimeInspector($suggestConfig->runtime))->clearPath($suggestRoot);
    $assert(str_contains($suggestions, '--main-file=suggested-plugin.php'), 'Expected manifest suggestions to include inferred --main-file flags when available.');

    $copyInspectorConfig = Config::fromArray($repoRoot, [
        'profile' => 'content-only',
        'paths' => [
            'content_root' => 'cms',
            'plugins_root' => 'cms/plugins',
            'themes_root' => 'cms/themes',
            'mu_plugins_root' => 'cms/mu-plugins',
        ],
        'core' => ['mode' => 'external', 'enabled' => false],
        'dependencies' => [],
    ]);
    $copyInspector = new RuntimeInspector($copyInspectorConfig->runtime);
    $copyRoot = sys_get_temp_dir() . '/wporg-copy-exclusion-' . bin2hex(random_bytes(4));
    mkdir($copyRoot . '/src', 0777, true);
    file_put_contents($copyRoot . '/src/ignored.php', "<?php\n");
    $copyInspector->copyPath($copyRoot . '/src/ignored.php', $copyRoot . '/dest/ignored.php', ['ignored.php']);
    $assert(! file_exists($copyRoot . '/dest/ignored.php'), 'Expected RuntimeInspector::copyPath to honor exclusions for single-file copies.');
    $copyInspector->clearPath($copyRoot);

    $scannerRoot = sys_get_temp_dir() . '/wporg-runtime-directory-' . bin2hex(random_bytes(4));
    mkdir($scannerRoot . '/cms/runtime-assets', 0777, true);
    $runtimeDirectoryState = (new DependencyScanner())->inspect($scannerRoot, [
        'name' => 'Runtime Assets',
        'slug' => 'runtime-assets',
        'kind' => 'runtime-directory',
        'path' => 'cms/runtime-assets',
        'version' => null,
    ]);
    (new RuntimeInspector($copyInspectorConfig->runtime))->clearPath($scannerRoot);
    $assert($runtimeDirectoryState['main_file'] === null, 'Expected runtime-directory scans to use null for main_file semantics.');

    $requests = [];
    $synchronizer = new GitHubLabelSynchronizer('example/repo');
    $synchronizer->ensureLabels([
        'existing' => ['color' => '1d76db', 'description' => 'Existing label'],
        'missing' => ['color' => '0e8a16', 'description' => 'Missing label'],
    ], static function (string $method, string $path, ?array $payload = null) use (&$requests): array {
        $requests[] = ['method' => $method, 'path' => $path, 'payload' => $payload];

        if ($method === 'GET' && $path === '/repos/example/repo/labels/existing') {
            return ['name' => 'existing'];
        }

        if ($method === 'PATCH' && $path === '/repos/example/repo/labels/existing') {
            return ['name' => 'existing'];
        }

        if ($method === 'GET' && $path === '/repos/example/repo/labels/missing') {
            throw new HttpStatusRuntimeException(404, 'Not Found');
        }

        if ($method === 'POST' && $path === '/repos/example/repo/labels') {
            return ['created' => true];
        }

        throw new RuntimeException(sprintf('Unexpected label request: %s %s', $method, $path));
    });
    $assert(github_label_request_count($requests, 'GET', '/repos/example/repo/labels/existing') === 1, 'Expected ensureLabels to check existing labels before updating.');
    $assert(github_label_request_count($requests, 'PATCH', '/repos/example/repo/labels/existing') === 1, 'Expected ensureLabels to update labels that already exist.');
    $assert(github_label_request_count($requests, 'GET', '/repos/example/repo/labels/missing') === 1, 'Expected ensureLabels to check missing labels first.');
    $assert(github_label_request_count($requests, 'POST', '/repos/example/repo/labels') === 1, 'Expected ensureLabels to create labels only after a confirmed not-found response.');

    $requests = [];
    $forbiddenRejected = false;

    try {
        $synchronizer->ensureLabels([
            'forbidden' => ['color' => 'd93f0b', 'description' => 'Forbidden label'],
        ], static function (string $method, string $path, ?array $payload = null) use (&$requests): array {
            $requests[] = ['method' => $method, 'path' => $path, 'payload' => $payload];

            if ($method === 'GET' && $path === '/repos/example/repo/labels/forbidden') {
                throw new HttpStatusRuntimeException(403, 'Forbidden');
            }

            if ($method === 'POST' && $path === '/repos/example/repo/labels') {
                return ['created' => true];
            }

            throw new RuntimeException(sprintf('Unexpected label request: %s %s', $method, $path));
        });
    } catch (HttpStatusRuntimeException $exception) {
        $forbiddenRejected = $exception->status() === 403;
    }

    $assert($forbiddenRejected, 'Expected ensureLabels to surface non-404 GitHub API failures instead of masking them as missing labels.');
    $assert(github_label_request_count($requests, 'POST', '/repos/example/repo/labels') === 0, 'Expected ensureLabels to avoid label creation after a non-404 API failure.');

    $httpClient = new HttpClient();
    $requestBodyLimitRejected = false;

    try {
        $httpClient->requestWithOptions(
            'POST',
            'https://example.com/upload',
            ['Accept' => 'application/json'],
            null,
            str_repeat('a', 16),
            false,
            ['max_request_bytes' => 8]
        );
    } catch (RuntimeException $exception) {
        $requestBodyLimitRejected = str_contains($exception->getMessage(), 'request body exceeded the configured byte limit');
    }

    $assert($requestBodyLimitRejected, 'Expected HttpClient request options to reject oversized outbound request bodies.');

    $jsonDepthRejected = false;
    $tooDeepPayload = '"leaf"';

    for ($index = 0; $index < 40; $index++) {
        $tooDeepPayload = '{"n":' . $tooDeepPayload . '}';
    }

    try {
        HttpClient::decodeJsonObject($tooDeepPayload, 'Depth test.', 16);
    } catch (RuntimeException $exception) {
        $jsonDepthRejected = str_contains($exception->getMessage(), 'Depth test.');
    }

    $assert($jsonDepthRejected, 'Expected HttpClient JSON decoding helper to fail when JSON nesting exceeds the configured decode depth.');

    $manifestReference = (string) file_get_contents($repoRoot . '/docs/manifest-reference.md');

    foreach (ConfigNormalizer::DEFAULT_FORBIDDEN_PATHS as $pathEntry) {
        $assert(
            str_contains($manifestReference, "`{$pathEntry}`"),
            sprintf('Expected manifest reference defaults section to list forbidden path `%s`.', $pathEntry)
        );
    }

    foreach (ConfigNormalizer::DEFAULT_FORBIDDEN_FILES as $filePattern) {
        $assert(
            str_contains($manifestReference, "`{$filePattern}`"),
            sprintf('Expected manifest reference defaults section to list forbidden file pattern `%s`.', $filePattern)
        );
    }

    foreach (ConfigNormalizer::DEFAULT_MANAGED_SANITIZE_PATH_SUFFIXES as $sanitizeSuffix) {
        $assert(
            str_contains($manifestReference, "`{$sanitizeSuffix}`"),
            sprintf('Expected manifest reference defaults section to list managed sanitize path suffix `%s`.', $sanitizeSuffix)
        );
    }
}

/**
 * @param list<array{method:string,path:string,payload:?array<string,mixed>}> $requests
 */
function github_label_request_count(array $requests, string $method, string $path): int
{
    return count(array_filter(
        $requests,
        static fn (array $request): bool => $request['method'] === $method && $request['path'] === $path
    ));
}
