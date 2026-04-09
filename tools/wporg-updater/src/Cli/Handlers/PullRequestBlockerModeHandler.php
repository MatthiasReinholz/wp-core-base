<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater\Cli\Handlers;

use Closure;
use WpOrgPluginUpdater\Cli\CliModeHandler;
use WpOrgPluginUpdater\Config;
use WpOrgPluginUpdater\GitHubClient;
use WpOrgPluginUpdater\HttpClient;
use WpOrgPluginUpdater\PullRequestBlocker;

final class PullRequestBlockerModeHandler implements CliModeHandler
{
    public function __construct(
        private readonly Config $config,
        private readonly HttpClient $httpClient,
        private readonly bool $jsonOutput,
        private readonly Closure $emitJson,
    ) {
    }

    public function supports(string $mode): bool
    {
        return in_array($mode, ['pr-blocker', 'pr-blocker-reconcile'], true);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function handle(string $mode, array $options): int
    {
        $gitHubClient = GitHubClient::fromEnvironment($this->httpClient, $this->config->githubApiBase(), $this->config->dryRun());
        $blocker = new PullRequestBlocker($gitHubClient);
        $result = $mode === 'pr-blocker-reconcile'
            ? $blocker->evaluateOpenAutomationPullRequestsStatus()
            : $this->evaluateSinglePullRequest($blocker, $options);

        if ($this->jsonOutput) {
            ($this->emitJson)($result, (int) ($result['exit_code'] ?? 1));
        }

        return (int) ($result['exit_code'] ?? 1);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function evaluateSinglePullRequest(PullRequestBlocker $blocker, array $options): array
    {
        $pullRequestNumber = isset($options['pr-number']) && is_string($options['pr-number']) && ctype_digit($options['pr-number'])
            ? (int) $options['pr-number']
            : null;

        return $pullRequestNumber !== null
            ? $blocker->evaluatePullRequestNumberStatus($pullRequestNumber)
            : $blocker->evaluateCurrentPullRequestStatus();
    }
}
