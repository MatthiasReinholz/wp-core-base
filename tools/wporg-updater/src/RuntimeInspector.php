<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class RuntimeInspector
{
    /**
     * @param array{stage_dir:string, manifest_mode:string, validation_mode:string, ownership_roots:list<string>, staged_kinds:list<string>, validated_kinds:list<string>, forbidden_paths:list<string>, forbidden_files:list<string>, allow_runtime_paths:list<string>, strip_paths:list<string>, strip_files:list<string>, managed_sanitize_paths:list<string>, managed_sanitize_files:list<string>} $runtimeConfig
     */
    public function __construct(
        private readonly array $runtimeConfig,
    ) {
    }

    /**
     * @param list<string> $allowRuntimePaths
     * @param list<string> $excludedPaths
     * @param list<string> $stripPaths
     * @param list<string> $stripFiles
     */
    public function assertPathIsClean(
        string $path,
        array $allowRuntimePaths = [],
        array $excludedPaths = [],
        array $stripPaths = [],
        array $stripFiles = [],
    ): void {
        if (! file_exists($path) && ! is_link($path)) {
            throw new RuntimeException(sprintf('Runtime path does not exist: %s', $path));
        }

        foreach ($this->iterableEntries($path) as $entry) {
            $relativePath = $entry['relative_path'];

            if (
                $this->isExcluded($relativePath, $excludedPaths)
                || $this->isStripped($relativePath, $stripPaths, $stripFiles)
            ) {
                continue;
            }

            if ($entry['is_symlink']) {
                throw new RuntimeException(sprintf('Symlink detected in runtime tree: %s', $relativePath));
            }

            if ($this->isAllowed($relativePath, $allowRuntimePaths)) {
                continue;
            }

            $this->assertPathAllowed($relativePath, $entry['is_dir']);
        }
    }

    /**
     * @param list<string> $allowRuntimePaths
     * @param list<string> $excludedPaths
     * @param list<string> $stripPaths
     * @param list<string> $stripFiles
     */
    public function assertTreeIsClean(
        string $root,
        array $allowRuntimePaths = [],
        array $excludedPaths = [],
        array $stripPaths = [],
        array $stripFiles = [],
    ): void {
        if (! is_dir($root)) {
            throw new RuntimeException(sprintf('Runtime root does not exist: %s', $root));
        }

        $this->assertPathIsClean($root, $allowRuntimePaths, $excludedPaths, $stripPaths, $stripFiles);
    }

    /**
     * @param list<string> $excludedPaths
     * @param list<string> $stripPaths
     * @param list<string> $stripFiles
     */
    public function computeChecksum(string $path, array $excludedPaths = [], array $stripPaths = [], array $stripFiles = []): string
    {
        if (! file_exists($path) && ! is_link($path)) {
            throw new RuntimeException(sprintf('Checksum path does not exist: %s', $path));
        }

        $entries = [];

        foreach ($this->iterableEntries($path) as $entry) {
            if (
                $this->isExcluded($entry['relative_path'], $excludedPaths)
                || $this->isStripped($entry['relative_path'], $stripPaths, $stripFiles)
                || $entry['is_dir']
            ) {
                continue;
            }

            if ($entry['is_symlink']) {
                throw new RuntimeException(sprintf('Symlink detected in checksum tree: %s', $entry['relative_path']));
            }

            $contents = file_get_contents($entry['absolute_path']);

            if (! is_string($contents)) {
                throw new RuntimeException(sprintf('Failed to read runtime file for checksum: %s', $entry['absolute_path']));
            }

            $entries[] = $entry['relative_path'] . "\0" . hash('sha256', $contents);
        }

        sort($entries);

        return 'sha256:' . hash('sha256', implode("\n", $entries));
    }

    /**
     * @param list<string> $excludedPaths
     * @param list<string> $stripPaths
     * @param list<string> $stripFiles
     */
    public function computeTreeChecksum(string $root, array $excludedPaths = [], array $stripPaths = [], array $stripFiles = []): string
    {
        if (! is_dir($root)) {
            throw new RuntimeException(sprintf('Checksum root does not exist: %s', $root));
        }

        return $this->computeChecksum($root, $excludedPaths, $stripPaths, $stripFiles);
    }

    /**
     * @param list<string> $excludedPaths
     */
    public function clearPath(string $path, array $excludedPaths = []): void
    {
        if (! file_exists($path) && ! is_link($path)) {
            return;
        }

        if (is_file($path) || is_link($path)) {
            if (! unlink($path)) {
                throw new RuntimeException(sprintf('Failed to remove file: %s', $path));
            }
            return;
        }

        if (! is_dir($path)) {
            return;
        }

        $entries = scandir($path);

        if ($entries === false) {
            throw new RuntimeException(sprintf('Failed to list directory: %s', $path));
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $entryPath = $path . '/' . $entry;
            $relative = ltrim(str_replace('\\', '/', substr($entryPath, strlen($path))), '/');

            if ($relative !== '' && $this->isExcluded($relative, $excludedPaths)) {
                continue;
            }

            $this->clearPath($entryPath);
        }

        if (! rmdir($path)) {
            throw new RuntimeException(sprintf('Failed to remove directory: %s', $path));
        }
    }

    /**
     * @param list<string> $excludedPaths
     */
    public function clearDirectory(string $path, array $excludedPaths = []): void
    {
        $this->clearPath($path, $excludedPaths);
    }

    /**
     * @param list<string> $excludedPaths
     */
    public function copyPath(string $source, string $destination, array $excludedPaths = []): void
    {
        if (! file_exists($source) && ! is_link($source)) {
            throw new RuntimeException(sprintf('Source path does not exist: %s', $source));
        }

        if (is_link($source)) {
            throw new RuntimeException(sprintf('Symlink detected while copying runtime path: %s', $source));
        }

        if (is_file($source)) {
            $targetDir = dirname($destination);

            if (! is_dir($targetDir) && ! mkdir($targetDir, 0775, true) && ! is_dir($targetDir)) {
                throw new RuntimeException(sprintf('Failed to create directory: %s', $targetDir));
            }

            if (! copy($source, $destination)) {
                throw new RuntimeException(sprintf('Failed to copy file to %s', $destination));
            }

            $this->preservePermissions($source, $destination);

            return;
        }

        if (! is_dir($destination) && ! mkdir($destination, 0775, true) && ! is_dir($destination)) {
            throw new RuntimeException(sprintf('Failed to create directory: %s', $destination));
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $relative = ltrim(str_replace('\\', '/', $iterator->getSubPathName()), '/');

            if ($relative !== '' && $this->isExcluded($relative, $excludedPaths)) {
                continue;
            }

            $target = $destination . '/' . $relative;

            if ($item->isLink()) {
                throw new RuntimeException(sprintf('Symlink detected while copying runtime tree: %s', $relative));
            }

            if ($item->isDir()) {
                if (! is_dir($target) && ! mkdir($target, 0775, true) && ! is_dir($target)) {
                    throw new RuntimeException(sprintf('Failed to create directory: %s', $target));
                }
                continue;
            }

            $targetDir = dirname($target);

            if (! is_dir($targetDir) && ! mkdir($targetDir, 0775, true) && ! is_dir($targetDir)) {
                throw new RuntimeException(sprintf('Failed to create directory: %s', $targetDir));
            }

            if (! copy($item->getPathname(), $target)) {
                throw new RuntimeException(sprintf('Failed to copy file to %s', $target));
            }

            $this->preservePermissions($item->getPathname(), $target);
        }
    }

    /**
     * @param list<string> $excludedPaths
     */
    public function copyTree(string $source, string $destination, array $excludedPaths = []): void
    {
        if (! is_dir($source)) {
            throw new RuntimeException(sprintf('Source directory does not exist: %s', $source));
        }

        $this->copyPath($source, $destination, $excludedPaths);
    }

    private function preservePermissions(string $source, string $destination): void
    {
        $sourcePermissions = fileperms($source);

        if ($sourcePermissions === false) {
            throw new RuntimeException(sprintf('Failed to read permissions from %s', $source));
        }

        if (! chmod($destination, $sourcePermissions & 0777)) {
            throw new RuntimeException(sprintf('Failed to apply permissions to %s', $destination));
        }
    }

    /**
     * @param list<string> $stripPaths
     * @param list<string> $stripFiles
     */
    public function stripPath(string $path, array $stripPaths = [], array $stripFiles = []): void
    {
        if (($stripPaths === [] && $stripFiles === []) || (! file_exists($path) && ! is_link($path))) {
            return;
        }

        if (is_file($path) || is_link($path)) {
            if ($this->isStripped(basename($path), $stripPaths, $stripFiles)) {
                if (! unlink($path)) {
                    throw new RuntimeException(sprintf('Failed to strip file: %s', $path));
                }
            }

            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $relative = ltrim(str_replace('\\', '/', substr($item->getPathname(), strlen($path))), '/');

            if (! $this->isStripped($relative, $stripPaths, $stripFiles)) {
                continue;
            }

            if ($item->isLink() || $item->isFile()) {
                if (! unlink($item->getPathname())) {
                    throw new RuntimeException(sprintf('Failed to strip file: %s', $item->getPathname()));
                }
            } elseif ($item->isDir()) {
                $this->clearPath($item->getPathname());
            }
        }
    }

    /**
     * @param list<string> $stripPaths
     * @param list<string> $stripFiles
     * @return list<string>
     */
    public function matchingStrippedEntries(string $path, array $stripPaths = [], array $stripFiles = []): array
    {
        if (($stripPaths === [] && $stripFiles === []) || (! file_exists($path) && ! is_link($path))) {
            return [];
        }

        $matches = [];

        foreach ($this->iterableEntries($path) as $entry) {
            if ($this->isStripped($entry['relative_path'], $stripPaths, $stripFiles)) {
                $matches[] = $entry['relative_path'];
            }
        }

        $matches = array_values(array_unique($matches));
        sort($matches);

        return $matches;
    }

    /**
     * @return list<array{absolute_path:string, relative_path:string, is_dir:bool, is_symlink:bool}>
     */
    private function iterableEntries(string $root): array
    {
        if (is_file($root) || is_link($root)) {
            return [[
                'absolute_path' => $root,
                'relative_path' => basename($root),
                'is_dir' => false,
                'is_symlink' => is_link($root),
            ]];
        }

        $entries = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $relativePath = ltrim(str_replace('\\', '/', $iterator->getSubPathName()), '/');

            if ($relativePath === '') {
                continue;
            }

            $entries[] = [
                'absolute_path' => $item->getPathname(),
                'relative_path' => $relativePath,
                'is_dir' => $item->isDir(),
                'is_symlink' => $item->isLink(),
            ];
        }

        return $entries;
    }

    private function assertPathAllowed(string $relativePath, bool $isDir): void
    {
        $segments = explode('/', $relativePath);

        foreach ($segments as $segment) {
            foreach ($this->runtimeConfig['forbidden_paths'] as $forbiddenPath) {
                if (fnmatch($forbiddenPath, $segment)) {
                    throw new RuntimeException(sprintf('Forbidden runtime path detected: %s', $relativePath));
                }
            }
        }

        $basename = basename($relativePath);

        foreach ($this->runtimeConfig['forbidden_files'] as $pattern) {
            if (fnmatch($pattern, $basename)) {
                throw new RuntimeException(sprintf('Forbidden runtime file detected: %s', $relativePath));
            }
        }

        if (! $isDir && preg_match('/(^|\/)\.(github|gitlab|gitea|forgejo|circleci)\//', $relativePath) === 1) {
            throw new RuntimeException(sprintf('Forbidden CI metadata detected: %s', $relativePath));
        }
    }

    /**
     * @param list<string> $allowRuntimePaths
     */
    private function isAllowed(string $relativePath, array $allowRuntimePaths): bool
    {
        $combined = array_values(array_unique(array_merge($this->runtimeConfig['allow_runtime_paths'], $allowRuntimePaths)));

        foreach ($combined as $allowedPath) {
            if ($allowedPath === '' || $allowedPath === '.') {
                return true;
            }

            if ($relativePath === $allowedPath || str_starts_with($relativePath, $allowedPath . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $excludedPaths
     */
    private function isExcluded(string $relativePath, array $excludedPaths): bool
    {
        foreach ($excludedPaths as $excludedPath) {
            if ($relativePath === $excludedPath || str_starts_with($relativePath, $excludedPath . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $stripPaths
     * @param list<string> $stripFiles
     */
    private function isStripped(string $relativePath, array $stripPaths, array $stripFiles): bool
    {
        foreach ($stripPaths as $stripPath) {
            if ($this->matchesStripPath($relativePath, $stripPath)) {
                return true;
            }
        }

        $basename = basename($relativePath);

        foreach ($stripFiles as $pattern) {
            if (fnmatch($pattern, $basename)) {
                return true;
            }
        }

        return false;
    }

    private function matchesStripPath(string $relativePath, string $stripPath): bool
    {
        if ($stripPath === '' || $stripPath === '.') {
            return true;
        }

        if (str_starts_with($stripPath, '**/')) {
            $suffix = substr($stripPath, 3);

            if ($suffix === '') {
                return true;
            }

            return $relativePath === $suffix
                || str_starts_with($relativePath, $suffix . '/')
                || str_ends_with($relativePath, '/' . $suffix)
                || str_contains($relativePath, '/' . $suffix . '/');
        }

        return $relativePath === $stripPath || str_starts_with($relativePath, $stripPath . '/');
    }
}
