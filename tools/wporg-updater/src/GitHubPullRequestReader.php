<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

interface GitHubPullRequestReader
{
    /**
     * @return list<array<string, mixed>>
     */
    public function listOpenPullRequests(?string $label = null): array;

    /**
     * @return array<string, mixed>
     */
    public function getPullRequest(int $number): array;
}
