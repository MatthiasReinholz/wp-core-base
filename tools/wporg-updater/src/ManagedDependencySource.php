<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

interface ManagedDependencySource
{
    public function key(): string;

    /**
     * @param array<string, mixed> $dependency
     * @return array<string, mixed>
     */
    public function fetchCatalog(array $dependency): array;

    /**
     * @param array<string, mixed> $dependency
     * @param array<string, mixed> $catalog
     * @return array<string, mixed>
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
