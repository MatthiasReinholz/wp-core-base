<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

interface GitHubReleaseSource
{
    /**
     * @param array<string, mixed> $dependency
     * @return list<array<string, mixed>>
     */
    public function fetchStableReleases(array $dependency): array;

    /**
     * @param array<string, mixed> $release
     * @param array<string, mixed> $dependency
     */
    public function latestVersion(array $release, array $dependency): string;

    /**
     * @param array<string, mixed> $release
     * @param array<string, mixed> $dependency
     */
    public function downloadReleaseToFile(array $release, array $dependency, string $destination): void;
}
