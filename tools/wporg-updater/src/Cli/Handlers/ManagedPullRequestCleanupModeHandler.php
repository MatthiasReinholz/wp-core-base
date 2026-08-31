<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater\Cli\Handlers;

use RuntimeException;
use WpOrgPluginUpdater\AutomationClientFactory;
use WpOrgPluginUpdater\Cli\CliModeHandler;
use WpOrgPluginUpdater\Config;
use WpOrgPluginUpdater\GitCommandRunner;
use WpOrgPluginUpdater\HttpClient;
use WpOrgPluginUpdater\ManagedPullRequestBranchCleaner;

final class ManagedPullRequestCleanupModeHandler implements CliModeHandler
{
    public function __construct(
        private readonly Config $config,
        private readonly HttpClient $httpClient,
        private readonly string $repoRoot,
        private readonly bool $jsonOutput,
        private readonly \Closure $emitJson,
    ) {
    }

    public function supports(string $mode): bool
    {
        return $mode === 'managed-pr-cleanup';
    }

    /**
     * @param array<string, mixed> $options
     */
    public function handle(string $mode, array $options): int
    {
        $number = filter_var($options['pr-number'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if (! is_int($number)) {
            throw new RuntimeException('managed-pr-cleanup requires --pr-number=<positive integer>.');
        }

        if ($this->config->dryRun()) {
            throw new RuntimeException('managed-pr-cleanup does not support dry-run because safe deletion requires live remote revision checks.');
        }

        $cleaner = new ManagedPullRequestBranchCleaner(
            AutomationClientFactory::fromEnvironment($this->config, $this->httpClient),
            new GitCommandRunner($this->repoRoot, $this->config->dryRun())
        );
        $result = $cleaner->cleanupClosedPullRequest($number);

        if ($this->jsonOutput) {
            ($this->emitJson)([
                'status' => 'success',
                'mode' => $mode,
                'pull_request' => $number,
                'branch' => $result['branch'],
                'deleted' => $result['deleted'],
            ]);

            return 0;
        }

        fwrite(STDOUT, sprintf(
            "%s managed branch %s for pull request #%d.\n",
            $result['deleted'] ? 'Deleted' : 'No cleanup needed for',
            $result['branch'],
            $number
        ));

        return 0;
    }
}
