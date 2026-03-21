<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class DependencyScanner
{
    /**
     * @param array<string, mixed> $dependency
     * @return array{name:string, version:?string, path:string, absolute_path:string, main_file:string, kind:string}
     */
    public function inspect(string $repoRoot, array $dependency): array
    {
        $relativePath = trim((string) $dependency['path'], '/');
        $absolutePath = $repoRoot . '/' . $relativePath;

        if (! is_dir($absolutePath)) {
            throw new RuntimeException(sprintf('Dependency path is not a directory: %s', $absolutePath));
        }

        $mainFileRelative = ltrim((string) $dependency['main_file'], '/');
        $mainFile = $absolutePath . '/' . $mainFileRelative;

        if (! is_file($mainFile)) {
            throw new RuntimeException(sprintf('Main dependency file not found: %s', $mainFile));
        }

        $contents = file_get_contents($mainFile);

        if (! is_string($contents)) {
            throw new RuntimeException(sprintf('Failed to read dependency file: %s', $mainFile));
        }

        $kind = (string) $dependency['kind'];
        $nameHeader = $kind === 'theme' ? 'Theme Name' : 'Plugin Name';
        $name = $this->matchHeader($contents, $nameHeader) ?? (string) $dependency['name'];
        $version = $this->matchHeader($contents, 'Version');

        return [
            'name' => $name,
            'version' => $version !== null ? trim($version) : null,
            'path' => $relativePath,
            'absolute_path' => $absolutePath,
            'main_file' => $mainFileRelative,
            'kind' => $kind,
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
