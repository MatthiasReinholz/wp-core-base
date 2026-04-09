<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

interface FrameworkReleaseSource
{
    /**
     * @return list<array<string, mixed>>
     */
    public function fetchStableReleases(FrameworkConfig $framework): array;

    /**
     * @param array<string, mixed> $release
     * @return array<string, mixed>
     */
    public function releaseData(FrameworkConfig $framework, array $release): array;

    /**
     * @param array<string, mixed> $release
     */
    public function downloadVerifiedReleaseAsset(FrameworkConfig $framework, array $release, string $destination): void;
}
