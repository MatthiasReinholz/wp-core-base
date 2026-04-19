<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

interface JsonHttpTransport
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function getJsonWithOptions(string $url, array $headers = [], array $options = []): array;

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $options
     */
    public function downloadToFileWithOptions(string $url, string $destination, array $headers = [], array $options = []): void;
}
