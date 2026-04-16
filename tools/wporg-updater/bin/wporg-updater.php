<?php

declare(strict_types=1);

if (PHP_VERSION_ID < 80100) {
    fwrite(STDERR, "wp-core-base requires PHP 8.1 or later.\n");
    exit(1);
}

use WpOrgPluginUpdater\Config;
use WpOrgPluginUpdater\CommandHelp;
use WpOrgPluginUpdater\Cli\Handlers\DependencyAuthoringModeHandler;
use WpOrgPluginUpdater\Cli\Handlers\DoctorModeHandler;
use WpOrgPluginUpdater\Cli\Handlers\FrameworkSyncModeHandler;
use WpOrgPluginUpdater\Cli\Handlers\PullRequestBlockerModeHandler;
use WpOrgPluginUpdater\Cli\Handlers\ReleaseModeHandler;
use WpOrgPluginUpdater\Cli\Handlers\RuntimeMaintenanceModeHandler;
use WpOrgPluginUpdater\Cli\Handlers\ScaffoldModeHandler;
use WpOrgPluginUpdater\Cli\Handlers\SyncModeHandler;
use WpOrgPluginUpdater\Cli\Handlers\SyncReportModeHandler;
use WpOrgPluginUpdater\Cli\ModeDispatcher;
use WpOrgPluginUpdater\AdminGovernanceExporter;
use WpOrgPluginUpdater\GitHubReleaseClient;
use WpOrgPluginUpdater\HttpClient;
use WpOrgPluginUpdater\ManagedSourceRegistry;
use WpOrgPluginUpdater\MutationLock;
use WpOrgPluginUpdater\OutputRedactor;
use WpOrgPluginUpdater\PremiumProviderRegistry;
use WpOrgPluginUpdater\PremiumCredentialsStore;
use WpOrgPluginUpdater\RuntimeInspector;
use WpOrgPluginUpdater\WordPressOrgManagedSource;
use WpOrgPluginUpdater\WordPressOrgClient;
use WpOrgPluginUpdater\GitHubReleaseManagedSource;

require dirname(__DIR__) . '/src/Autoload.php';

$mode = $argv[1] ?? 'sync';
$arguments = array_slice($argv, 2);
$frameworkRoot = dirname(__DIR__, 3);
[$options, $positionals] = parseCliTokens($arguments);

$repoRootEnv = getenv('WPORG_REPO_ROOT');
$repoRoot = $options['repo-root'] ?? $repoRootEnv;
$repoRoot = is_string($repoRoot) && $repoRoot !== '' ? $repoRoot : $frameworkRoot;
$toolPath = $options['tool-path'] ?? (
    realpath($repoRoot) === realpath($frameworkRoot)
        ? '.'
        : 'vendor/wp-core-base'
);
$force = isset($options['force']);
$jsonOutput = isset($options['json']);
$commandPrefix = $toolPath === '.'
    ? 'bin/wp-core-base'
    : sprintf('%s/bin/wp-core-base', trim((string) $toolPath, '/'));
$phpCommandPrefix = $toolPath === '.'
    ? 'php tools/wporg-updater/bin/wporg-updater.php'
    : sprintf('php %s/tools/wporg-updater/bin/wporg-updater.php', trim((string) $toolPath, '/'));
$mutationLock = new MutationLock();
$emitJson = static function (array $payload, int $exitCode = 0): never {
    fwrite(STDOUT, json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n");
    exit($exitCode);
};

try {
    if (in_array($mode, ['help', '--help', '-h'], true)) {
        $topic = $positionals[0] ?? null;
        $topic = is_string($topic) ? $topic : null;
        fwrite(STDOUT, CommandHelp::render($topic, $commandPrefix, $phpCommandPrefix));
        exit(0);
    }

    $unknownFlags = unknownFlagsForMode($mode, $options);

    if ($unknownFlags !== []) {
        $errorMessage = sprintf(
            'Unknown option(s) for mode `%s`: %s. Run `%s help` to see grouped command help.',
            $mode,
            implode(', ', array_map(static fn (string $name): string => '--' . $name, $unknownFlags)),
            $commandPrefix
        );

        if ($jsonOutput) {
            $emitJson([
                'status' => 'failure',
                'error' => $errorMessage,
            ], 2);
        }

        fwrite(STDERR, $errorMessage . "\n");
        exit(2);
    }

    $earlyDispatcher = new ModeDispatcher([
        new DoctorModeHandler($repoRoot, $jsonOutput, $emitJson),
        new SyncReportModeHandler($repoRoot, $jsonOutput, $emitJson),
    ]);
    $earlyExitCode = $earlyDispatcher->dispatch($mode, $options);

    if ($earlyExitCode !== null) {
        exit($earlyExitCode);
    }

    $releaseDispatcher = new ModeDispatcher([
        new ReleaseModeHandler($repoRoot, $jsonOutput, $emitJson),
    ]);
    $releaseExitCode = $releaseDispatcher->dispatch($mode, $options);

    if ($releaseExitCode !== null) {
        exit($releaseExitCode);
    }

    $scaffoldDispatcher = new ModeDispatcher([
        new ScaffoldModeHandler($frameworkRoot, $repoRoot, (string) $toolPath, $force, $mutationLock),
    ]);
    $scaffoldExitCode = $scaffoldDispatcher->dispatch($mode, $options);

    if ($scaffoldExitCode !== null) {
        exit($scaffoldExitCode);
    }

    $config = Config::load($repoRoot);
    $httpClient = new HttpClient(
        userAgent: 'wp-core-base/' . ($mode === 'sync' ? 'sync' : $mode)
    );
    $premiumProviderRegistry = PremiumProviderRegistry::load($repoRoot);
    $premiumProviders = $premiumProviderRegistry->providerKeys();
    $managedSourceRegistry = new ManagedSourceRegistry(
        new WordPressOrgManagedSource(new WordPressOrgClient($httpClient), $httpClient),
        new GitHubReleaseManagedSource(new GitHubReleaseClient($httpClient, $config->githubApiBase())),
        ...array_values($premiumProviderRegistry->instantiate($httpClient, new PremiumCredentialsStore(), $config->managedDependencies()))
    );
    $adminGovernanceExporter = new AdminGovernanceExporter(new RuntimeInspector($config->runtime));
    $runtimeMaintenanceDispatcher = new ModeDispatcher([
        new RuntimeMaintenanceModeHandler(
            $config,
            $mutationLock,
            $repoRoot,
            $commandPrefix,
            $jsonOutput,
            $emitJson
        ),
    ]);
    $runtimeMaintenanceExitCode = $runtimeMaintenanceDispatcher->dispatch($mode, $options);

    if ($runtimeMaintenanceExitCode !== null) {
        exit($runtimeMaintenanceExitCode);
    }

    if (isset($options['help']) && in_array($mode, ['scaffold-premium-provider', 'sync'], true)) {
        fwrite(STDOUT, CommandHelp::render($mode, $commandPrefix, $phpCommandPrefix));
        exit(0);
    }

    $authoringDispatcher = new ModeDispatcher([
        new DependencyAuthoringModeHandler(
            config: $config,
            managedSourceRegistry: $managedSourceRegistry,
            adminGovernanceExporter: $adminGovernanceExporter,
            mutationLock: $mutationLock,
            repoRoot: $repoRoot,
            commandPrefix: $commandPrefix,
            phpCommandPrefix: $phpCommandPrefix,
            jsonOutput: $jsonOutput,
            emitJson: $emitJson,
            premiumProviders: $premiumProviders,
        ),
    ]);
    $authoringExitCode = $authoringDispatcher->dispatch($mode, $options);

    if ($authoringExitCode !== null) {
        exit($authoringExitCode);
    }

    $syncDispatcher = new ModeDispatcher([
        new SyncModeHandler(
            $config,
            $httpClient,
            $managedSourceRegistry,
            $adminGovernanceExporter,
            $mutationLock,
            $repoRoot
        ),
        new FrameworkSyncModeHandler($config, $httpClient, $mutationLock, $repoRoot),
    ]);
    $syncExitCode = $syncDispatcher->dispatch($mode, $options);

    if ($syncExitCode !== null) {
        exit($syncExitCode);
    }

    $blockerDispatcher = new ModeDispatcher([
        new PullRequestBlockerModeHandler($config, $httpClient, $jsonOutput, $emitJson),
    ]);
    $blockerExitCode = $blockerDispatcher->dispatch($mode, $options);

    if ($blockerExitCode !== null) {
        exit($blockerExitCode);
    }

    $unknownModeMessage = sprintf('Unknown mode: %s. Run with `help` to see the available modes.', $mode);

    if ($jsonOutput) {
        $emitJson([
            'status' => 'failure',
            'error' => $unknownModeMessage,
        ], 2);
    }

    fwrite(STDERR, sprintf("Unknown mode: %s\n", $mode));
    fwrite(STDERR, "Run with `help` to see the available modes.\n");
    exit(2);
} catch (Throwable $throwable) {
    if ($jsonOutput) {
        $emitJson([
            'status' => 'failure',
            'error' => OutputRedactor::redact($throwable->getMessage()),
        ], 1);
    }

    fwrite(STDERR, OutputRedactor::redact($throwable->getMessage()) . "\n");
    exit(1);
}

/**
 * @param list<string> $tokens
 * @return array{0:array<string, mixed>,1:list<string>}
 */
function parseCliTokens(array $tokens): array
{
    $options = [];
    $positionals = [];

    for ($index = 0, $count = count($tokens); $index < $count; $index++) {
        $token = (string) $tokens[$index];

        if ($token === '--') {
            for ($cursor = $index + 1; $cursor < $count; $cursor++) {
                $positionals[] = (string) $tokens[$cursor];
            }
            break;
        }

        if (! str_starts_with($token, '--')) {
            $positionals[] = $token;
            continue;
        }

        $option = substr($token, 2);

        if ($option === '') {
            $positionals[] = $token;
            continue;
        }

        if (str_contains($option, '=')) {
            [$name, $value] = explode('=', $option, 2);
            $options[$name] = $value;
            continue;
        }

        $nextToken = $tokens[$index + 1] ?? null;

        if (optionExpectsValue($option) && is_string($nextToken) && $nextToken !== '' && ! str_starts_with($nextToken, '--')) {
            $options[$option] = $nextToken;
            $index++;
            continue;
        }

        $options[$option] = true;
    }

    return [$options, $positionals];
}

function optionExpectsValue(string $option): bool
{
    static $valueOptions = [
        'artifact' => true,
        'archive-subdir' => true,
        'checksum-file' => true,
        'class' => true,
        'component-key' => true,
        'content-root' => true,
        'credential-key' => true,
        'distribution-path' => true,
        'from-source' => true,
        'github-release-asset-pattern' => true,
        'github-repository' => true,
        'github-token-env' => true,
        'kind' => true,
        'main-file' => true,
        'management' => true,
        'name' => true,
        'output' => true,
        'passphrase-env' => true,
        'path' => true,
        'payload-root' => true,
        'pr-number' => true,
        'private-key-env' => true,
        'profile' => true,
        'provider' => true,
        'provider-product-id' => true,
        'public-key-file' => true,
        'release-type' => true,
        'repo-root' => true,
        'report-json' => true,
        'result-path' => true,
        'signature-file' => true,
        'slug' => true,
        'source' => true,
        'summary-path' => true,
        'tag' => true,
        'tool-path' => true,
        'version' => true,
    ];

    return isset($valueOptions[$option]);
}

/**
 * @param array<string, mixed> $options
 * @return list<string>
 */
function unknownFlagsForMode(string $mode, array $options): array
{
    $global = ['repo-root', 'tool-path', 'json', 'help'];
    $byMode = [
        'doctor' => ['github'],
        'render-sync-report' => ['report-json', 'summary-path'],
        'sync-report-issue' => ['report-json'],
        'release-verify' => ['tag', 'artifact', 'checksum-file', 'signature-file', 'public-key-file'],
        'build-release-artifact' => ['output', 'checksum-file'],
        'release-sign' => ['artifact', 'checksum-file', 'signature-file', 'private-key-env', 'passphrase-env'],
        'prepare-framework-release' => ['release-type', 'version', 'allow-current-version'],
        'scaffold-downstream' => ['profile', 'content-root', 'force', 'adopt-existing-managed-files'],
        'scaffold-premium-provider' => ['provider', 'class', 'path', 'force'],
        'framework-apply' => ['payload-root', 'distribution-path', 'result-path'],
        'stage-runtime' => ['output'],
        'refresh-admin-governance' => [],
        'suggest-manifest' => [],
        'format-manifest' => [],
        'add-dependency' => [
            'source',
            'kind',
            'slug',
            'path',
            'main-file',
            'version',
            'archive-subdir',
            'github-repository',
            'github-release-asset-pattern',
            'github-token-env',
            'credential-key',
            'provider',
            'provider-product-id',
            'private',
            'replace',
            'force',
            'interactive',
            'plan',
            'preview',
            'dry-run',
            'name',
            'management',
        ],
        'adopt-dependency' => [
            'component-key',
            'slug',
            'kind',
            'from-source',
            'source',
            'version',
            'preserve-version',
            'github-repository',
            'github-release-asset-pattern',
            'github-token-env',
            'credential-key',
            'provider',
            'provider-product-id',
            'private',
            'archive-subdir',
            'plan',
            'preview',
            'dry-run',
        ],
        'remove-dependency' => ['component-key', 'slug', 'kind', 'source', 'delete-path'],
        'list-dependencies' => [],
        'sync' => ['report-json', 'fail-on-source-errors'],
        'framework-sync' => ['check-only'],
        'pr-blocker' => ['pr-number'],
        'pr-blocker-reconcile' => [],
    ];

    $allowed = array_fill_keys(array_merge($global, $byMode[$mode] ?? []), true);
    $unknown = [];

    foreach (array_keys($options) as $name) {
        if (isset($allowed[$name])) {
            continue;
        }

        $unknown[] = $name;
    }

    sort($unknown);
    return $unknown;
}
