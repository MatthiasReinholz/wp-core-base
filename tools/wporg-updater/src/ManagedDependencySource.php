<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

interface ManagedDependencySource
{
    public function key(): string;

    /**
     * @param array<string, mixed> $dependency
     * @return array<string, mixed>
     *
     * Expected minimum shape:
     * - latest_version: non-empty string
     * - latest_release_at: ISO-8601 timestamp string
     */
    public function fetchCatalog(array $dependency): array;

    /**
     * @param array<string, mixed> $dependency
     * @param array<string, mixed> $catalog
     * @return array<string, mixed>
     *
     * Expected minimum shape:
     * - version: non-empty string
     * - release_at: ISO-8601 timestamp string
     *
     * Common additional keys:
     * - download_url: direct archive URL
     * - source_reference: human-readable upstream reference
     * - source_details: list of PR detail rows
     */
    public function releaseDataForVersion(array $dependency, array $catalog, string $targetVersion, string $fallbackReleaseAt): array;

    /**
     * @param array<string, mixed> $dependency
     * @param array<string, mixed> $releaseData
     */
    public function downloadReleaseToFile(array $dependency, array $releaseData, string $destination): void;

    /**
     * @param array<string, mixed> $dependency
     */
    public function supportsForumSync(array $dependency): bool;
}
