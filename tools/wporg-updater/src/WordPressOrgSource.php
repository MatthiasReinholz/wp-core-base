<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

interface WordPressOrgSource
{
    /**
     * @return array<string, mixed>
     */
    public function fetchComponentInfo(string $kind, string $slug): array;

    /**
     * @param array<string, mixed> $info
     */
    public function latestVersion(string $kind, array $info): string;

    /**
     * @param array<string, mixed> $info
     */
    public function downloadUrlForVersion(string $kind, array $info, string $version): string;
}
