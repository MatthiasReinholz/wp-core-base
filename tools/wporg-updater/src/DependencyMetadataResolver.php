<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class DependencyMetadataResolver
{
    public function __construct(?DependencyScanner $dependencyScanner = null)
    {
        unset($dependencyScanner);
    }

    /**
     * @return array{name:string, version:?string, main_file:?string}
     */
    public function resolveFromExistingPath(Config $config, string $path, string $kind, ?string $name = null, ?string $mainFile = null, ?string $version = null): array
    {
        $absolutePath = $config->repoRoot . '/' . trim($path, '/');
        return $this->resolveFromAbsolutePath($absolutePath, $kind, $name, $mainFile, $version);
    }

    /**
     * @return array{name:string, version:?string, main_file:?string}
     */
    public function resolveFromAbsolutePath(string $absolutePath, string $kind, ?string $name = null, ?string $mainFile = null, ?string $version = null): array
    {
        $resolvedMainFile = $this->resolveMainFile($absolutePath, $kind, $mainFile);
        $fallbackName = $name ?? $this->displayNameFromPath(basename($absolutePath));

        $dependency = [
            'name' => $fallbackName,
            'kind' => $kind,
            'path' => trim($absolutePath, '/'),
            'main_file' => $resolvedMainFile,
            'version' => $version,
        ];

        $state = $this->scanAbsolutePath($absolutePath, $dependency);

        return [
            'name' => $state['name'] !== '' ? $state['name'] : $fallbackName,
            'version' => $state['version'] ?? $version,
            'main_file' => $resolvedMainFile,
        ];
    }

    public function resolveMainFile(string $absolutePath, string $kind, ?string $mainFile = null): ?string
    {
        if (in_array($kind, ['mu-plugin-file', 'runtime-file', 'runtime-directory'], true)) {
            return null;
        }

        if ($mainFile !== null && trim($mainFile) !== '') {
            $normalized = trim(str_replace('\\', '/', $mainFile), '/');
            $expected = $absolutePath . '/' . $normalized;

            if (! is_file($expected)) {
                throw new RuntimeException(sprintf('Configured main file not found: %s', $expected));
            }

            return $normalized;
        }

        $candidates = $this->findHeaderCandidates($absolutePath, $kind);

        if (count($candidates) === 1) {
            return $candidates[0];
        }

        if ($candidates === []) {
            throw new RuntimeException(sprintf(
                'Could not infer main_file for %s at %s. Re-run with --main-file.',
                $kind,
                $absolutePath
            ));
        }

        throw new RuntimeException(sprintf(
            'Multiple main_file candidates found for %s at %s: %s. Re-run with --main-file.',
            $kind,
            $absolutePath,
            implode(', ', $candidates)
        ));
    }

    public function displayNameFromPath(string $basename): string
    {
        $name = preg_replace('/\.[^.]+$/', '', $basename) ?? $basename;
        $name = str_replace(['-', '_'], ' ', $name);
        $name = preg_replace('/\s+/', ' ', $name) ?? $name;
        return ucwords(trim($name));
    }

    /**
     * @return list<string>
     */
    private function findHeaderCandidates(string $absolutePath, string $kind): array
    {
        if (! is_dir($absolutePath)) {
            throw new RuntimeException(sprintf('Dependency directory does not exist: %s', $absolutePath));
        }

        $header = $kind === 'theme' ? 'Theme Name' : 'Plugin Name';
        $candidates = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($absolutePath, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $item) {
            if (! $item->isFile()) {
                continue;
            }

            $filename = $item->getFilename();

            if ($kind === 'theme') {
                if (strtolower($filename) !== 'style.css') {
                    continue;
                }
            } elseif (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'php') {
                continue;
            }

            $contents = file_get_contents($item->getPathname());

            if (! is_string($contents)) {
                continue;
            }

            if ($this->matchHeader($contents, $header) === null) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($absolutePath) + 1));
            $candidates[] = $relative;
        }

        sort($candidates);

        return array_values(array_unique($candidates));
    }

    private function matchHeader(string $contents, string $header): ?string
    {
        $pattern = sprintf('/^[ \t\/*#@]*%s:(.*)$/mi', preg_quote($header, '/'));

        if (preg_match($pattern, $contents, $matches) === 1) {
            return trim($matches[1]);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $dependency
     * @return array{name:string, version:?string}
     */
    private function scanAbsolutePath(string $absolutePath, array $dependency): array
    {
        $kind = (string) $dependency['kind'];

        if (in_array($kind, ['runtime-directory'], true)) {
            return [
                'name' => (string) $dependency['name'],
                'version' => $dependency['version'] ?? null,
            ];
        }

        $mainFile = $absolutePath;

        if (! in_array($kind, ['mu-plugin-file', 'runtime-file'], true)) {
            $mainFile = $absolutePath . '/' . trim((string) $dependency['main_file'], '/');
        }

        if (! is_file($mainFile)) {
            throw new RuntimeException(sprintf('Main dependency file not found: %s', $mainFile));
        }

        $contents = file_get_contents($mainFile);

        if (! is_string($contents)) {
            throw new RuntimeException(sprintf('Failed to read dependency file: %s', $mainFile));
        }

        $nameHeader = $kind === 'theme' ? 'Theme Name' : 'Plugin Name';

        return [
            'name' => $this->matchHeader($contents, $nameHeader) ?? (string) $dependency['name'],
            'version' => $this->matchHeader($contents, 'Version') ?? (($dependency['version'] ?? null) !== null ? (string) $dependency['version'] : null),
        ];
    }
}
