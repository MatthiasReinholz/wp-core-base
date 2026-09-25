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
    public static function assertSafeStageDirectory(string $stageDir, array $paths, array $ownershipRoots, ?string $repoRoot = null): void
    {
        $stageDir = self::normalizedRelativePath($stageDir, 'runtime.stage_dir');

        if ($stageDir === '.') {
            throw new RuntimeException('runtime.stage_dir may not be the repository root.');
        }

        if (self::pathStartsWith($stageDir, '.wp-core-base') && ! self::pathStartsWith($stageDir, '.wp-core-base/build')) {
            throw new RuntimeException('runtime.stage_dir may not overlap the framework control tree.');
        }

        $protectedRoots = array_values(array_unique(array_merge(
            [
                '.git', '.github', '.gitlab', '.gitea', '.forgejo', '.circleci', '.gitlab-ci.yml', '.gitignore', '.gitattributes', '.gitmodules', '.wp-core-base/build/locks',
                'bin', 'tools', 'scripts', 'docs', 'src', 'tests', 'vendor', 'wp-admin', 'wp-includes', 'wp-content',
                'index.php', 'license.txt', 'readme.html', 'xmlrpc.php',
                $paths['content_root'], $paths['plugins_root'], $paths['themes_root'], $paths['mu_plugins_root'],
            ],
            $ownershipRoots,
            $repoRoot !== null ? self::frameworkRoots($repoRoot) : []
        )));

        foreach ($protectedRoots as $protectedRoot) {
            if ($protectedRoot === '.') {
                continue;
            }
            if (self::pathStartsWith($stageDir, $protectedRoot) || self::pathStartsWith($protectedRoot, $stageDir)) {
                throw new RuntimeException(sprintf(
                    'runtime.stage_dir %s may not overlap protected repository path %s.',
                    $stageDir,
                    $protectedRoot
                ));
            }
        }

        $rootEntry = explode('/', $stageDir)[0];
        if (fnmatch('wp-*.php', $rootEntry)) {
            throw new RuntimeException(sprintf('runtime.stage_dir may not replace WordPress core file %s.', $rootEntry));
        }
        if ($repoRoot !== null) {
            self::assertNoSymlinkDescendants($repoRoot, $stageDir);
            $absolutePath = rtrim($repoRoot, '/') . '/' . $stageDir;
            if (file_exists($absolutePath) && ! is_dir($absolutePath)) {
                throw new RuntimeException(sprintf('Runtime stage output must be a directory: %s', $absolutePath));
            }
        }
    }

    /** Dependencies may own runtime paths, but never repository or framework control trees. */
    public static function assertSafeDependencyPath(string $repoRoot, string $path): void
    {
        $path = self::normalizedRelativePath($path, 'dependency path');
        if ($path === '.') {
            throw new RuntimeException('A managed or local dependency may not own the repository root.');
        }
        $protected = array_merge([
            '.git', '.github', '.gitlab', '.gitea', '.forgejo', '.circleci', '.gitlab-ci.yml',
            '.gitignore', '.gitattributes', '.gitmodules', '.wp-core-base',
            'tools/wporg-updater', 'bin/wp-core-base',
        ], self::frameworkRoots($repoRoot));
        foreach ($protected as $controlPath) {
            if (self::pathStartsWith($path, $controlPath) || self::pathStartsWith($controlPath, $path)) {
                throw new RuntimeException(sprintf('Dependency path %s may not overlap repository control path %s.', $path, $controlPath));
            }
        }
        self::assertNoSymlinkDescendants($repoRoot, $path);
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
        if (! is_string($value) || trim($value) === '' || str_contains($value, "\0")) {
            throw new RuntimeException(sprintf('Config value "%s" must be a non-empty string.', $key));
        }

        $normalized = str_replace('\\', '/', trim($value));
        if (str_contains($normalized, "\0") || str_starts_with($normalized, '/') || preg_match('/^[A-Za-z]:/', $normalized) === 1) {
            throw new RuntimeException(sprintf('Config value "%s" must be a safe relative path.', $key));
        }

        $segments = explode('/', $normalized);
        if (in_array('..', $segments, true)) {
            throw new RuntimeException(sprintf('Config value "%s" must not contain parent traversal.', $key));
        }

        $segments = array_values(array_filter($segments, static fn (string $segment): bool => $segment !== '' && $segment !== '.'));
        return $segments === [] ? '.' : implode('/', $segments);
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
        return self::normalizedRelativePath($value, 'stage-runtime --output');
    }

    /**
     * The repository root is a trusted boundary; symlinks below it are not.
     * Recheck immediately before any path is published, not only at config load time.
     */
    public static function assertNoSymlinkDescendants(string $repoRoot, string $relativePath): void
    {
        $root = realpath($repoRoot);
        if ($root === false || ! is_dir($root)) {
            throw new RuntimeException(sprintf('Repository root does not exist: %s', $repoRoot));
        }

        $relativePath = self::normalizedRelativePath($relativePath, 'repository path');
        $path = $root;
        foreach (explode('/', $relativePath) as $segment) {
            if ($segment === '.') {
                continue;
            }
            $path .= '/' . $segment;
            clearstatcache(true, $path);
            if (is_link($path)) {
                throw new RuntimeException(sprintf('Repository path contains a symlink: %s', $path));
            }
            if (file_exists($path) && ! is_dir($path) && $path !== $root . '/' . $relativePath) {
                throw new RuntimeException(sprintf('Repository path ancestor is not a directory: %s', $path));
            }
        }
    }

    /** @return list<string> */
    private static function frameworkRoots(string $repoRoot): array
    {
        $roots = [];
        if (is_file($repoRoot . '/.wp-core-base/framework.php')) {
            self::assertNoSymlinkDescendants($repoRoot, '.wp-core-base/framework.php');
            $installedPath = self::normalizedRelativePath(FrameworkConfig::load($repoRoot)->distributionPath(), 'distribution.path');
            if ($installedPath !== '.') {
                $roots[] = $installedPath;
            }
        }
        $canonicalRoot = realpath($repoRoot);
        $sourceRoot = realpath(dirname(__DIR__, 3));
        if ($canonicalRoot !== false && $sourceRoot !== false && str_starts_with($sourceRoot, $canonicalRoot . '/')) {
            $roots[] = substr($sourceRoot, strlen($canonicalRoot) + 1);
        }
        return $roots;
    }

    public static function pathStartsWith(string $path, string $prefix): bool
    {
        return $prefix === '.' || $path === $prefix || str_starts_with($path, $prefix . '/');
    }
}
