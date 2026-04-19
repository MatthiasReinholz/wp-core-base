<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater\Cli\Handlers;

use Closure;
use RuntimeException;
use WpOrgPluginUpdater\Cli\CliModeHandler;
use WpOrgPluginUpdater\AutomationClientFactory;
use WpOrgPluginUpdater\Config;
use WpOrgPluginUpdater\HttpClient;
use WpOrgPluginUpdater\SyncReport;

final class SyncReportModeHandler implements CliModeHandler
{
    public function __construct(
        private readonly string $repoRoot,
        private readonly bool $jsonOutput,
        private readonly Closure $emitJson,
    ) {
    }

    public function supports(string $mode): bool
    {
        return in_array($mode, ['render-sync-report', 'sync-report-issue'], true);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function handle(string $mode, array $options): int
    {
        if ($mode === 'render-sync-report') {
            $reportPath = isset($options['report-json']) && is_string($options['report-json']) ? $options['report-json'] : null;

            if ($reportPath === null || trim($reportPath) === '') {
                throw new RuntimeException('render-sync-report requires --report-json=/path/to/report.json.');
            }

            $summary = SyncReport::exists($reportPath)
                ? SyncReport::renderSummary(SyncReport::read($reportPath))
                : "## wp-core-base Sync Report\n\nNo sync report was written before the workflow ended.\n";
            $summaryPath = isset($options['summary-path']) && is_string($options['summary-path']) ? $options['summary-path'] : null;

            if ($summaryPath !== null && trim($summaryPath) !== '') {
                if (file_put_contents($summaryPath, $summary) === false) {
                    throw new RuntimeException(sprintf('Unable to write sync summary: %s', $summaryPath));
                }
            } else {
                fwrite(STDOUT, $summary);
            }

            return 0;
        }

        $reportPath = isset($options['report-json']) && is_string($options['report-json']) ? $options['report-json'] : null;

        if ($reportPath === null || trim($reportPath) === '') {
            throw new RuntimeException('sync-report-issue requires --report-json=/path/to/report.json.');
        }

        if (! SyncReport::exists($reportPath)) {
            fwrite(STDOUT, "No sync report was written; skipping source-failure issue sync.\n");
            return 0;
        }

        $config = Config::load($this->repoRoot);
        $automationClient = AutomationClientFactory::fromEnvironment(
            $config,
            new HttpClient(userAgent: 'wp-core-base/sync-report-issue')
        );
        SyncReport::syncIssue($automationClient, SyncReport::read($reportPath), AutomationClientFactory::workflowRunUrl($config));

        return 0;
    }
}
