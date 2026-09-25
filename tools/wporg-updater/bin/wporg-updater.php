<?php

declare(strict_types=1);

use WpOrgPluginUpdater\Config;
use WpOrgPluginUpdater\CommandHelp;
use WpOrgPluginUpdater\Cli\CommandOptions;
use WpOrgPluginUpdater\Cli\Handlers\DependencyAuthoringModeHandler;
use WpOrgPluginUpdater\Cli\Handlers\DoctorModeHandler;
use WpOrgPluginUpdater\Cli\Handlers\FrameworkSyncModeHandler;
use WpOrgPluginUpdater\Cli\Handlers\ManagedPullRequestCleanupModeHandler;
use WpOrgPluginUpdater\Cli\Handlers\PullRequestBlockerModeHandler;
use WpOrgPluginUpdater\Cli\Handlers\ReleaseAssetInspectionModeHandler;
use WpOrgPluginUpdater\Cli\Handlers\ReleaseModeHandler;
use WpOrgPluginUpdater\Cli\Handlers\RuntimeMaintenanceModeHandler;
use WpOrgPluginUpdater\Cli\Handlers\ScaffoldModeHandler;
use WpOrgPluginUpdater\Cli\Handlers\SyncModeHandler;
use WpOrgPluginUpdater\Cli\Handlers\SyncReportModeHandler;
use WpOrgPluginUpdater\Cli\ModeDispatcher;
use WpOrgPluginUpdater\AdminGovernanceExporter;
use WpOrgPluginUpdater\GenericJsonManagedSource;
use WpOrgPluginUpdater\GitLabReleaseClient;
use WpOrgPluginUpdater\GitLabReleaseManagedSource;
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

$cliArguments = array_values(array_map('strval', $GLOBALS['argv'] ?? []));
$mode = $cliArguments[1] ?? 'sync';
$arguments = array_slice($cliArguments, 2);
$frameworkRoot = dirname(__DIR__, 3);
try {
    $options = CommandOptions::parse($mode, $arguments);
} catch (RuntimeException $exception) {
    if (in_array('--json', $cliArguments, true)) {
        fwrite(STDOUT, json_encode(['status' => 'failure', 'error' => $exception->getMessage()], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n");
    } else {
        fwrite(STDERR, $exception->getMessage() . "\nRun with `help` to see the available modes.\n");
    }
    exit(2);
}

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
        $topic = null;

        foreach ($arguments as $argument) {
            if (! str_starts_with($argument, '--')) {
                $topic = $argument;
                break;
            }
        }

        fwrite(STDOUT, CommandHelp::render($topic, $commandPrefix, $phpCommandPrefix));
        exit(0);
    }

    if (isset($options['help'])) {
        fwrite(STDOUT, CommandHelp::render($mode, $commandPrefix, $phpCommandPrefix));
        exit(0);
    }

    // Keep one lease for the complete command, before any mutable state is read.
    $repositoryLease = $mutationLock->acquire($repoRoot, $mode);

    $earlyDispatcher = new ModeDispatcher([
        new DoctorModeHandler($repoRoot, $jsonOutput, $emitJson),
        new SyncReportModeHandler($repoRoot),
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
        new GitLabReleaseManagedSource(new GitLabReleaseClient($httpClient, $config->gitlabApiBase())),
        new GenericJsonManagedSource($httpClient),
        ...array_values($premiumProviderRegistry->instantiate($httpClient, new PremiumCredentialsStore(), $config->managedDependencies()))
    );
    $adminGovernanceExporter = new AdminGovernanceExporter();
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

    $releaseAssetInspectionDispatcher = new ModeDispatcher([
        new ReleaseAssetInspectionModeHandler(
            $config,
            $httpClient,
            $commandPrefix,
            $phpCommandPrefix,
            $jsonOutput,
            $emitJson
        ),
    ]);
    $releaseAssetInspectionExitCode = $releaseAssetInspectionDispatcher->dispatch($mode, $options);

    if ($releaseAssetInspectionExitCode !== null) {
        exit($releaseAssetInspectionExitCode);
    }

    if (isset($options['help']) && in_array($mode, ['scaffold-premium-provider', 'sync', 'framework-sync'], true)) {
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
        new FrameworkSyncModeHandler($config, $httpClient, $mutationLock, $repoRoot, $jsonOutput, $emitJson),
    ]);
    $syncExitCode = $syncDispatcher->dispatch($mode, $options);

    if ($syncExitCode !== null) {
        exit($syncExitCode);
    }

    $blockerDispatcher = new ModeDispatcher([
        new ManagedPullRequestCleanupModeHandler($config, $httpClient, $repoRoot, $jsonOutput, $emitJson),
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
