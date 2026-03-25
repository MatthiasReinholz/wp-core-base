<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

final class RuntimeOwnershipInspector
{
    public function __construct(
        private readonly Config $config,
    ) {
    }

    /**
     * @return list<array{path:string, absolute_path:string, kind:string, root:string, is_dir:bool, is_symlink:bool}>
     */
    public function undeclaredRuntimePaths(): array
    {
        $entries = [];
        $declaredPaths = array_merge(
            array_map(static fn (array $dependency): string => (string) $dependency['path'], $this->config->dependencies()),
            array_map(static fn (array $entry): string => $entry['path'], FrameworkRuntimeFiles::runtimeEntries($this->config))
        );
        $allowedPaths = (array) $this->config->runtime['allow_runtime_paths'];

        foreach ($this->rootSpecifications() as $spec) {
            $absoluteRoot = $this->config->repoRoot . '/' . $spec['root'];

            if (! is_dir($absoluteRoot)) {
                continue;
            }

            foreach (scandir($absoluteRoot) ?: [] as $entry) {
                if (in_array($entry, ['.', '..'], true)) {
                    continue;
                }

                $relativePath = $spec['root'] . '/' . $entry;
                $absolutePath = $absoluteRoot . '/' . $entry;

                if ($this->isCovered($relativePath, $declaredPaths) || $this->isCovered($relativePath, $allowedPaths)) {
                    continue;
                }

                $isSymlink = is_link($absolutePath);
                $isDir = is_dir($absolutePath);
                $kind = $this->inferredKindForSpec($spec, $isDir);

                $entries[] = [
                    'path' => $relativePath,
                    'absolute_path' => $absolutePath,
                    'kind' => $kind,
                    'root' => $spec['root'],
                    'is_dir' => $isDir,
                    'is_symlink' => $isSymlink,
                ];
            }
        }

        usort($entries, static fn (array $left, array $right): int => strcmp($left['path'], $right['path']));

        return $entries;
    }

    /**
     * @param array{path:string, absolute_path:string, kind:string, root:string, is_dir:bool, is_symlink:bool} $entry
     * @return array<string, mixed>
     */
    public function suggestedManifestEntry(array $entry): array
    {
        $basename = basename($entry['path']);

        return [
            'name' => $this->displayNameFromPath($basename),
            'slug' => pathinfo($basename, PATHINFO_FILENAME) ?: $basename,
            'kind' => $entry['kind'],
            'management' => 'local',
            'source' => 'local',
            'path' => $entry['path'],
            'main_file' => $this->suggestedMainFile($entry),
            'version' => null,
            'checksum' => null,
            'archive_subdir' => '',
            'extra_labels' => [],
            'source_config' => [
                'github_repository' => null,
                'github_release_asset_pattern' => null,
                'github_token_env' => null,
                'credential_key' => null,
                'provider_product_id' => null,
            ],
            'policy' => [
                'class' => 'local-owned',
                'allow_runtime_paths' => [],
                'strip_paths' => [],
                'strip_files' => [],
                'sanitize_paths' => [],
                'sanitize_files' => [],
            ],
        ];
    }

    /**
     * @return list<array{path:string, absolute_path:string}>
     */
    public function allowedRuntimePaths(): array
    {
        $paths = [];

        foreach ((array) $this->config->runtime['allow_runtime_paths'] as $path) {
            $paths[] = [
                'path' => $path,
                'absolute_path' => $this->config->repoRoot . '/' . $path,
            ];
        }

        return $paths;
    }

    /**
     * @return list<array{root:string, kind:string}>
     */
    private function rootSpecifications(): array
    {
        $specs = [];

        foreach ($this->config->ownershipRoots() as $root) {
            $kind = match ($root) {
                $this->config->paths['plugins_root'] => 'plugin',
                $this->config->paths['themes_root'] => 'theme',
                $this->config->paths['mu_plugins_root'] => 'mu-root',
                default => 'runtime-root',
            };

            $specs[] = [
                'root' => $root,
                'kind' => $kind,
            ];
        }

        return $specs;
    }

    private function inferredKindForSpec(array $spec, bool $isDir): string
    {
        return match ($spec['kind']) {
            'plugin' => 'plugin',
            'theme' => 'theme',
            'mu-root' => $isDir ? 'mu-plugin-package' : 'mu-plugin-file',
            default => $isDir ? 'runtime-directory' : 'runtime-file',
        };
    }

    private function suggestedMainFile(array $entry): ?string
    {
        return match ($entry['kind']) {
            'plugin', 'theme', 'mu-plugin-package' => null,
            'mu-plugin-file', 'runtime-file' => null,
            'runtime-directory' => null,
            default => null,
        };
    }

    private function displayNameFromPath(string $basename): string
    {
        $name = preg_replace('/\.[^.]+$/', '', $basename) ?? $basename;
        $name = str_replace(['-', '_'], ' ', $name);
        $name = preg_replace('/\s+/', ' ', $name) ?? $name;
        return ucwords(trim($name));
    }

    /**
     * @param list<string> $coveredPaths
     */
    private function isCovered(string $path, array $coveredPaths): bool
    {
        foreach ($coveredPaths as $coveredPath) {
            if ($path === $coveredPath || str_starts_with($path, $coveredPath . '/') || str_starts_with($coveredPath, $path . '/')) {
                return true;
            }
        }

        return false;
    }
}
