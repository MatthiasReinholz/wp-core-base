<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class PluginScanner
{
    /**
     * @param array<string, mixed> $pluginConfig
     * @return array{name:string, version:string, path:string, absolute_path:string, main_file:string}
     */
    public function inspect(string $repoRoot, array $pluginConfig): array
    {
        $relativePath = trim((string) $pluginConfig['path'], '/');
        $absolutePath = $repoRoot . '/' . $relativePath;

        if (! is_dir($absolutePath)) {
            throw new RuntimeException(sprintf('Plugin path is not a directory: %s', $absolutePath));
        }

        $mainFile = $absolutePath . '/' . ltrim((string) $pluginConfig['main_file'], '/');

        if (! is_file($mainFile)) {
            throw new RuntimeException(sprintf('Main plugin file not found: %s', $mainFile));
        }

        $contents = file_get_contents($mainFile);

        if (! is_string($contents)) {
            throw new RuntimeException(sprintf('Failed to read plugin file: %s', $mainFile));
        }

        $name = $this->matchHeader($contents, 'Plugin Name') ?? (string) $pluginConfig['slug'];
        $version = $this->matchHeader($contents, 'Version');

        if ($version === null) {
            throw new RuntimeException(sprintf('Could not find Version header in %s', $mainFile));
        }

        return [
            'name' => $name,
            'version' => trim($version),
            'path' => $relativePath,
            'absolute_path' => $absolutePath,
            'main_file' => basename($mainFile),
        ];
    }

    private function matchHeader(string $contents, string $header): ?string
    {
        $pattern = sprintf('/^[ \t\/*#@]*%s:(.*)$/mi', preg_quote($header, '/'));

        if (preg_match($pattern, $contents, $matches) === 1) {
            return trim($matches[1]);
        }

        return null;
    }
}
