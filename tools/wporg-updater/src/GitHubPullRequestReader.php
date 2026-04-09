<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

interface GitHubPullRequestReader
{
    /**
     * @return list<array<string, mixed>>
     */
    public function listOpenPullRequests(): array;

    /**
     * @return array<string, mixed>
     */
    public function getPullRequest(int $number): array;
}
