<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

interface ArchiveDownloader
{
    /**
     * @param array<string, string> $headers
     */
    public function downloadToFile(string $url, string $destination, array $headers = []): void;
}
