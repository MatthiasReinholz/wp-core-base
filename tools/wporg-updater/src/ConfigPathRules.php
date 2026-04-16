<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class ConfigPathRules
{
    /**
     * @param array{content_root:string, plugins_root:string, themes_root:string, mu_plugins_root:string} $paths
     * @param list<string> $ownershipRoots
     */
    public static function assertSafeStageDirectory(string $stageDir, array $paths, array $ownershipRoots): void
    {
        if ($stageDir === '.') {
            throw new RuntimeException('runtime.stage_dir may not be the repository root.');
        }

        if (
            $stageDir === '.wp-core-base'
            || (self::pathStartsWith($stageDir, '.wp-core-base') && ! self::pathStartsWith($stageDir, '.wp-core-base/build'))
        ) {
            throw new RuntimeException(sprintf(
                'runtime.stage_dir %s may not overlap the framework control tree outside .wp-core-base/build.',
                $stageDir
            ));
        }

        $protectedRoots = array_values(array_unique(array_merge(
            [$paths['content_root'], $paths['plugins_root'], $paths['themes_root'], $paths['mu_plugins_root']],
            $ownershipRoots
        )));

        foreach ($protectedRoots as $protectedRoot) {
            if (self::pathStartsWith($stageDir, $protectedRoot) || self::pathStartsWith($protectedRoot, $stageDir)) {
                throw new RuntimeException(sprintf(
                    'runtime.stage_dir %s may not overlap live runtime root %s.',
                    $stageDir,
                    $protectedRoot
                ));
            }
        }
    }

    /**
     * @param list<string> $allowRuntimePaths
     * @param array{content_root:string, plugins_root:string, themes_root:string, mu_plugins_root:string} $paths
     * @param list<string> $ownershipRoots
     */
    public static function assertSafeRuntimeAllowPaths(array $allowRuntimePaths, array $paths, array $ownershipRoots): void
    {
        $broadRoots = array_values(array_unique(array_merge(
            [$paths['content_root'], $paths['plugins_root'], $paths['themes_root'], $paths['mu_plugins_root']],
            $ownershipRoots
        )));

        foreach ($allowRuntimePaths as $allowPath) {
            if (! self::pathStartsWith($allowPath, $paths['content_root'])) {
                throw new RuntimeException(sprintf(
                    'runtime.allow_runtime_paths entry %s must live under paths.content_root.',
                    $allowPath
                ));
            }

            if (in_array($allowPath, $broadRoots, true)) {
                throw new RuntimeException(sprintf(
                    'runtime.allow_runtime_paths entry %s is too broad. Declare specific child paths instead.',
                    $allowPath
                ));
            }
        }
    }

    public static function normalizedRelativePath(mixed $value, string $key): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException(sprintf('Config value "%s" must be a non-empty string.', $key));
        }

        $normalized = str_replace('\\', '/', trim($value));
        $normalized = trim($normalized, '/');

        if ($normalized === '' || str_contains($normalized, '../') || str_starts_with($normalized, '..')) {
            throw new RuntimeException(sprintf('Config value "%s" must be a safe relative path.', $key));
        }

        return $normalized;
    }

    public static function nullableNormalizedRelativePath(mixed $value, string $key): ?string
    {
        if ($value === null) {
            return null;
        }

        return self::normalizedRelativePath($value, $key);
    }

    public static function normalizeStageOutputOverride(string $value): string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            throw new RuntimeException('stage-runtime --output must be a non-empty repo-relative path.');
        }

        $normalized = str_replace('\\', '/', $trimmed);

        if (
            str_starts_with($normalized, '/')
            || preg_match('/^[A-Za-z]:\//', $normalized) === 1
            || str_starts_with($normalized, '//')
        ) {
            throw new RuntimeException('stage-runtime --output must stay repo-relative; absolute paths are not allowed.');
        }

        while (str_starts_with($normalized, './')) {
            $normalized = substr($normalized, 2);
        }

        $normalized = trim($normalized, '/');

        if (
            $normalized === ''
            || str_contains($normalized, '/../')
            || str_starts_with($normalized, '../')
            || str_ends_with($normalized, '/..')
            || $normalized === '..'
        ) {
            throw new RuntimeException('stage-runtime --output must be a safe repo-relative path without traversal.');
        }

        return $normalized;
    }

    public static function pathStartsWith(string $path, string $prefix): bool
    {
        return $path === $prefix || str_starts_with($path, $prefix . '/');
    }
}
