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
        $comparisonPath = $repoRoot === null ? $stageDir : self::filesystemPath($repoRoot, $stageDir);

        if ($stageDir === '.') {
            throw new RuntimeException('runtime.stage_dir may not be the repository root.');
        }

        $controlRoot = $repoRoot === null ? '.wp-core-base' : self::filesystemPath($repoRoot, '.wp-core-base');
        $buildRoot = $repoRoot === null ? '.wp-core-base/build' : self::filesystemPath($repoRoot, '.wp-core-base/build');
        if (self::pathStartsWith($comparisonPath, $controlRoot) && ! self::pathStartsWith($comparisonPath, $buildRoot)) {
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
            $protectedPath = $repoRoot === null ? $protectedRoot : self::filesystemPath($repoRoot, $protectedRoot);
            if (self::pathStartsWith($comparisonPath, $protectedPath) || self::pathStartsWith($protectedPath, $comparisonPath)) {
                throw new RuntimeException(sprintf(
                    'runtime.stage_dir %s may not overlap protected repository path %s.',
                    $stageDir,
                    $protectedRoot
                ));
            }
        }

        $rootEntry = explode('/', $comparisonPath)[0];
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
        $comparisonPath = self::filesystemPath($repoRoot, $path);
        if ($path === '.') {
            throw new RuntimeException('A managed or local dependency may not own the repository root.');
        }
        $protected = array_merge([
            '.git', '.github', '.gitlab', '.gitea', '.forgejo', '.circleci', '.gitlab-ci.yml',
            '.gitignore', '.gitattributes', '.gitmodules', '.wp-core-base',
            'tools/wporg-updater', 'bin/wp-core-base',
        ], self::frameworkRoots($repoRoot));
        foreach ($protected as $controlPath) {
            $controlPath = self::filesystemPath($repoRoot, $controlPath);
            if (self::pathStartsWith($comparisonPath, $controlPath) || self::pathStartsWith($controlPath, $comparisonPath)) {
                throw new RuntimeException(sprintf('Dependency path %s may not overlap repository control path %s.', $path, $controlPath));
            }
        }
        self::assertNoSymlinkDescendants($repoRoot, $path);
    }

    /**
     * Resolve existing aliases by filesystem identity, not realpath's spelling.
     * On macOS realpath('.GIT') can retain that spelling while naming '.git'.
     * Missing components retain their spelling; distinct Linux case names stay distinct.
     */
    public static function filesystemPath(string $repoRoot, string $relativePath): string
    {
        $relativePath = self::normalizedRelativePath($relativePath, 'repository path');
        $parent = realpath($repoRoot);
        if ($parent === false || ! is_dir($parent)) {
            throw new RuntimeException(sprintf('Repository root does not exist: %s', $repoRoot));
        }
        $resolved = [];
        foreach (explode('/', $relativePath) as $segment) {
            if ($segment === '.') {
                continue;
            }
            $candidate = $parent . '/' . $segment;
            clearstatcache(true, $candidate);
            $identity = @lstat($candidate);
            if (is_array($identity) && is_dir($parent) && ! is_link($parent)) {
                $entries = scandir($parent);
                if (! is_array($entries)) {
                    throw new RuntimeException(sprintf('Unable to resolve repository path aliases beneath %s.', $parent));
                }
                if (! in_array($segment, $entries, true)) {
                    foreach ($entries as $entry) {
                        if ($entry === '.' || $entry === '..') {
                            continue;
                        }
                        $actual = @lstat($parent . '/' . $entry);
                        if (is_array($actual) && $identity['dev'] === $actual['dev'] && $identity['ino'] === $actual['ino']) {
                            $segment = $entry;
                            break;
                        }
                    }
                }
            }
            $resolved[] = $segment;
            $parent .= '/' . $segment;
        }
        return $resolved === [] ? '.' : implode('/', $resolved);
    }

    /** @param array{content_root:string, plugins_root:string, themes_root:string, mu_plugins_root:string} $paths
     * @param list<string> $ownershipRoots
     */
    public static function assertSafeFrameworkDistributionPath(string $repoRoot, string $path, array $paths, array $ownershipRoots): void
    {
        $path = self::normalizedRelativePath($path, 'distribution.path');
        if ($path === '.') {
            throw new RuntimeException('Framework installation cannot replace the repository root.');
        }
        $comparisonPath = self::filesystemPath($repoRoot, $path);
        $protectedRoots = array_merge([
            '.git', '.github', '.gitlab', '.gitea', '.forgejo', '.circleci', '.gitlab-ci.yml',
            '.gitignore', '.gitattributes', '.gitmodules', '.wp-core-base',
            'tools/wporg-updater', 'bin/wp-core-base',
            'wp-admin', 'wp-includes', 'wp-content', 'index.php', 'license.txt', 'readme.html', 'xmlrpc.php',
        ], array_values($paths), $ownershipRoots);
        foreach ($protectedRoots as $protectedRoot) {
            if ($protectedRoot === '.') {
                continue;
            }
            $protectedPath = self::filesystemPath($repoRoot, $protectedRoot);
            if (self::pathStartsWith($comparisonPath, $protectedPath) || self::pathStartsWith($protectedPath, $comparisonPath)) {
                throw new RuntimeException(sprintf('Framework distribution path %s may not overlap protected repository path %s.', $path, $protectedRoot));
            }
        }
        if (fnmatch('wp-*.php', explode('/', $comparisonPath)[0])) {
            throw new RuntimeException('Framework distribution path may not replace a WordPress core file.');
        }
        self::assertNoSymlinkDescendants($repoRoot, $path);
    }

    /** Provider implementation files are tooling; they must not overwrite control or runtime data. */
    public static function assertSafePremiumProviderPath(string $repoRoot, string $path): void
    {
        $path = self::normalizedRelativePath($path, 'premium provider class path');
        $comparisonPath = self::filesystemPath($repoRoot, $path);
        $providerRoot = self::filesystemPath($repoRoot, '.wp-core-base/premium-providers');
        if ($comparisonPath !== $providerRoot && self::pathStartsWith($comparisonPath, $providerRoot)) {
            self::assertNoSymlinkDescendants($repoRoot, $path);
            return;
        }
        self::assertSafeDependencyPath($repoRoot, $path);
        $runtimeRoots = ['wp-admin', 'wp-includes', 'wp-content', 'index.php', 'xmlrpc.php'];
        if (is_file($repoRoot . '/.wp-core-base/manifest.php')) {
            $config = Config::load($repoRoot);
            $runtimeRoots = array_merge($runtimeRoots, array_values($config->paths), $config->ownershipRoots(), array_column($config->dependencies(), 'path'));
        }
        foreach ($runtimeRoots as $runtimeRoot) {
            if ($runtimeRoot === '.') {
                continue;
            }
            $runtimePath = self::filesystemPath($repoRoot, $runtimeRoot);
            if (self::pathStartsWith($comparisonPath, $runtimePath) || self::pathStartsWith($runtimePath, $comparisonPath)) {
                throw new RuntimeException(sprintf('Premium provider class path %s may not overlap runtime path %s.', $path, $runtimeRoot));
            }
        }
        if (fnmatch('wp-*.php', explode('/', $comparisonPath)[0])) {
            throw new RuntimeException('Premium provider class path may not replace a WordPress core file.');
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
