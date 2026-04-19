<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater\Cli\Handlers;

use WpOrgPluginUpdater\Cli\CliModeHandler;
use WpOrgPluginUpdater\Config;
use WpOrgPluginUpdater\AutomationClientFactory;
use WpOrgPluginUpdater\FrameworkConfig;
use WpOrgPluginUpdater\FrameworkReleaseClient;
use WpOrgPluginUpdater\FrameworkSyncer;
use WpOrgPluginUpdater\GitCommandRunner;
use WpOrgPluginUpdater\HttpClient;
use WpOrgPluginUpdater\MutationLock;
use WpOrgPluginUpdater\PrBodyRenderer;
use WpOrgPluginUpdater\ReleaseClassifier;
use WpOrgPluginUpdater\RuntimeInspector;
use WpOrgPluginUpdater\StructuredLogger;
use WpOrgPluginUpdater\TempDirectoryJanitor;

final class FrameworkSyncModeHandler implements CliModeHandler
{
    public function __construct(
        private readonly Config $config,
        private readonly HttpClient $httpClient,
        private readonly MutationLock $mutationLock,
        private readonly string $repoRoot,
        private readonly bool $jsonOutput,
        private readonly \Closure $emitJson,
    ) {
    }

    public function supports(string $mode): bool
    {
        return $mode === 'framework-sync';
    }

    /**
     * @param array<string, mixed> $options
     */
    public function handle(string $mode, array $options): int
    {
        $operationStartedAt = StructuredLogger::startTimer();
        $this->cleanupStaleTemporaryDirectories();
        StructuredLogger::log('info', 'framework-sync', 'Starting framework-sync run.', startedAt: $operationStartedAt);

        $framework = FrameworkConfig::load($this->repoRoot);
        $checkOnly = isset($options['check-only']);
        $failOnSkippedManagedFiles = isset($options['fail-on-skipped-managed-files']);
        $automationClient = $checkOnly
            ? null
            : AutomationClientFactory::fromEnvironment($this->config, $this->httpClient);
        $frameworkSyncer = new FrameworkSyncer(
            framework: $framework,
            repoRoot: $this->repoRoot,
            config: $this->config,
            frameworkReleaseClient: new FrameworkReleaseClient($this->httpClient),
            releaseClassifier: new ReleaseClassifier(),
            prBodyRenderer: new PrBodyRenderer(),
            automationClient: $automationClient,
            gitRunner: new GitCommandRunner($this->repoRoot, $this->config->dryRun()),
            runtimeInspector: new RuntimeInspector($this->config->runtime),
        );

        if ($checkOnly) {
            $report = $frameworkSyncer->checkOnlyReport($failOnSkippedManagedFiles);
            $exitCode = $report['would_fail_on_skipped_managed_files'] ? 1 : 0;

            if ($this->jsonOutput) {
                ($this->emitJson)([
                    'status' => $exitCode === 0 ? 'success' : 'failure',
                    'mode' => 'framework-sync',
                    'check_only' => true,
                    'strict_mode' => $failOnSkippedManagedFiles,
                ] + $report + ($exitCode === 0 ? [] : [
                    'error' => 'framework-sync would skip framework-managed files in strict mode.',
                ]), $exitCode);
            }

            $frameworkSyncer->printCheckOnlyResult($report);
            StructuredLogger::log('info', 'framework-sync', 'Framework check-only sync completed.', startedAt: $operationStartedAt);
            return $exitCode;
        }

        $this->mutationLock->synchronized(
            $this->repoRoot,
            static function () use ($frameworkSyncer, $failOnSkippedManagedFiles): void {
                $frameworkSyncer->sync($failOnSkippedManagedFiles);
            },
            'framework-sync'
        );

        StructuredLogger::log('info', 'framework-sync', 'Framework sync completed.', startedAt: $operationStartedAt);

        if ($this->jsonOutput) {
            ($this->emitJson)([
                'status' => 'success',
                'mode' => 'framework-sync',
                'check_only' => false,
                'strict_mode' => $failOnSkippedManagedFiles,
            ]);
        }

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
