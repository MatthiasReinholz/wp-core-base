<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class AutomationClientFactory
{
    public static function fromEnvironment(Config $config, HttpClient $httpClient): AutomationClient
    {
        return match ($config->automationProvider()) {
            'github' => GitHubClient::fromEnvironment($httpClient, $config->automationApiBase(), $config->dryRun()),
            'gitlab' => GitLabClient::fromEnvironment($httpClient, $config->automationApiBase(), $config->dryRun()),
            default => throw new RuntimeException(sprintf(
                'Unsupported automation provider: %s',
                $config->automationProvider()
            )),
        };
    }

    public static function workflowRunUrl(Config $config): ?string
    {
        return match ($config->automationProvider()) {
            'github' => self::githubRunUrl(),
            'gitlab' => self::gitLabRunUrl(),
            default => null,
        };
    }

    private static function githubRunUrl(): ?string
    {
        $serverUrl = getenv('GITHUB_SERVER_URL');
        $repository = getenv('GITHUB_REPOSITORY');
        $runId = getenv('GITHUB_RUN_ID');

        if (! is_string($serverUrl) || $serverUrl === '' || ! is_string($repository) || $repository === '' || ! is_string($runId) || $runId === '') {
            return null;
        }

        return sprintf('%s/%s/actions/runs/%s', rtrim($serverUrl, '/'), $repository, $runId);
    }

    private static function gitLabRunUrl(): ?string
    {
        $jobUrl = getenv('CI_JOB_URL');

        if (is_string($jobUrl) && $jobUrl !== '') {
            return $jobUrl;
        }

        $pipelineUrl = getenv('CI_PIPELINE_URL');

        return is_string($pipelineUrl) && $pipelineUrl !== '' ? $pipelineUrl : null;
    }
}
