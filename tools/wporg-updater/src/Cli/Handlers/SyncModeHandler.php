<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater\Cli\Handlers;

use Throwable;
use WpOrgPluginUpdater\AdminGovernanceExporter;
use WpOrgPluginUpdater\AutomationClientFactory;
use WpOrgPluginUpdater\Cli\CliModeHandler;
use WpOrgPluginUpdater\Config;
use WpOrgPluginUpdater\CoreScanner;
use WpOrgPluginUpdater\CoreUpdater;
use WpOrgPluginUpdater\DependencyScanner;
use WpOrgPluginUpdater\GitCommandRunner;
use WpOrgPluginUpdater\GitHubReleaseClient;
use WpOrgPluginUpdater\HttpClient;
use WpOrgPluginUpdater\ManagedSourceRegistry;
use WpOrgPluginUpdater\ManifestWriter;
use WpOrgPluginUpdater\MutationLock;
use WpOrgPluginUpdater\OutputRedactor;
use WpOrgPluginUpdater\PrBodyRenderer;
use WpOrgPluginUpdater\ReleaseClassifier;
use WpOrgPluginUpdater\RuntimeInspector;
use WpOrgPluginUpdater\SyncExecutionResult;
use WpOrgPluginUpdater\SupportForumClient;
use WpOrgPluginUpdater\StructuredLogger;
use WpOrgPluginUpdater\TempDirectoryJanitor;
use WpOrgPluginUpdater\SyncReport;
use WpOrgPluginUpdater\Updater;
use WpOrgPluginUpdater\WordPressCoreClient;
use WpOrgPluginUpdater\WordPressOrgClient;

final class SyncModeHandler implements CliModeHandler
{
    public function __construct(
        private readonly Config $config,
        private readonly HttpClient $httpClient,
        private readonly ManagedSourceRegistry $managedSourceRegistry,
        private readonly AdminGovernanceExporter $adminGovernanceExporter,
        private readonly MutationLock $mutationLock,
        private readonly string $repoRoot,
    ) {
    }

    public function supports(string $mode): bool
    {
        return $mode === 'sync';
    }

    /**
     * @param array<string, mixed> $options
     */
    public function handle(string $mode, array $options): int
    {
        $operationStartedAt = StructuredLogger::startTimer();
        $this->cleanupStaleTemporaryDirectories();
        StructuredLogger::log('info', 'sync', 'Starting sync run.', startedAt: $operationStartedAt);

        $automationClient = AutomationClientFactory::fromEnvironment($this->config, $this->httpClient);
        $runtimeInspector = new RuntimeInspector($this->config->runtime);
        $dependencyUpdater = new Updater(
            config: $this->config,
            dependencyScanner: new DependencyScanner(),
            wordPressOrgClient: new WordPressOrgClient($this->httpClient),
            gitHubReleaseClient: new GitHubReleaseClient($this->httpClient, $this->config->githubApiBase()),
            managedSourceRegistry: $this->managedSourceRegistry,
            supportForumClient: new SupportForumClient($this->httpClient, 100),
            releaseClassifier: new ReleaseClassifier(),
            prBodyRenderer: new PrBodyRenderer(),
            automationClient: $automationClient,
            gitRunner: new GitCommandRunner($this->repoRoot, $this->config->dryRun()),
            runtimeInspector: $runtimeInspector,
            manifestWriter: new ManifestWriter(),
            httpClient: $this->httpClient,
            adminGovernanceExporter: $this->adminGovernanceExporter,
        );
        $coreUpdater = new CoreUpdater(
            config: $this->config,
            coreScanner: new CoreScanner(),
            coreClient: new WordPressCoreClient($this->httpClient),
            releaseClassifier: new ReleaseClassifier(),
            prBodyRenderer: new PrBodyRenderer(),
            automationClient: $automationClient,
            gitRunner: new GitCommandRunner($this->repoRoot, $this->config->dryRun()),
        );
        $errors = [];
        $dependencyWarnings = [];
        $reportPath = isset($options['report-json']) && is_string($options['report-json']) ? $options['report-json'] : null;
        $failOnSourceErrors = isset($options['fail-on-source-errors']);

        $this->mutationLock->synchronized($this->repoRoot, static function () use (
            $coreUpdater,
            $dependencyUpdater,
            &$dependencyWarnings,
            &$errors
        ): void {
            foreach ([
                'core' => static fn () => $coreUpdater->sync(),
                'dependencies' => static function () use ($dependencyUpdater, &$dependencyWarnings): void {
                    $dependencyWarnings = $dependencyUpdater->sync();
                },
            ] as $name => $syncPass) {
                $passStartedAt = StructuredLogger::startTimer();
                StructuredLogger::log('info', 'sync-pass', sprintf('Starting %s sync pass.', $name), componentKey: $name, startedAt: $passStartedAt);

                try {
                    $syncPass();
                    StructuredLogger::log('info', 'sync-pass', sprintf('%s sync pass completed.', $name), componentKey: $name, startedAt: $passStartedAt);
                } catch (Throwable $throwable) {
                    $errors[] = OutputRedactor::redact(sprintf('%s: %s', $name, $throwable->getMessage()));
                    StructuredLogger::log(
                        'error',
                        'sync-pass',
                        end($errors) ?: sprintf('%s sync pass failed.', $name),
                        componentKey: $name,
                        startedAt: $passStartedAt
                    );
                }
            }
        }, 'sync');

        $syncResult = new SyncExecutionResult(
            fatalErrors: $errors,
            dependencyWarnings: $dependencyWarnings,
            dependencyTrustStates: $dependencyUpdater->lastRunTrustStates(),
        );

        if ($reportPath !== null && trim($reportPath) !== '') {
            SyncReport::write($syncResult->toSyncReport(), $reportPath);
        }

        $syncResult->throwOnFatalErrors();

        if ($syncResult->hasDependencyWarnings()) {
            StructuredLogger::log(
                'warn',
                'sync',
                'Sync completed with dependency-source warnings.',
                startedAt: $operationStartedAt,
                context: ['warning_count' => count($syncResult->dependencyWarnings)]
            );
            fwrite(
                STDERR,
                "[warn] Non-fatal dependency-source failures were reported during sync:\n- " .
                implode("\n- ", $syncResult->dependencyWarnings) .
                "\n"
            );

            if ($failOnSourceErrors) {
                StructuredLogger::log('error', 'sync', 'Failing sync due to --fail-on-source-errors.', startedAt: $operationStartedAt);
                return SyncReport::EXIT_SOURCE_WARNINGS;
            }
        }

        StructuredLogger::log(
            'info',
            'sync',
            'Sync run completed.',
            startedAt: $operationStartedAt,
            context: [
                'fatal_error_count' => count($syncResult->fatalErrors),
                'dependency_warning_count' => count($syncResult->dependencyWarnings),
            ]
        );

        return 0;
    }

    private function cleanupStaleTemporaryDirectories(): void
    {
        $result = (new TempDirectoryJanitor(
            TempDirectoryJanitor::defaultPrefixes(),
            TempDirectoryJanitor::defaultMaxAgeSeconds()
        ))->cleanup();

        foreach ($result['failed'] as $warning) {
            fwrite(STDERR, sprintf("[warn] %s\n", $warning));
        }
    }
}
