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
        $declaredPaths = array_map(static fn (array $dependency): string => (string) $dependency['path'], $this->config->dependencies());
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
                $kind = $spec['kind'];

                if ($spec['root'] === $this->config->paths['mu_plugins_root']) {
                    $kind = $isDir ? 'mu-plugin-package' : 'mu-plugin-file';
                }

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
        return [
            ['root' => $this->config->paths['plugins_root'], 'kind' => 'plugin'],
            ['root' => $this->config->paths['themes_root'], 'kind' => 'theme'],
            ['root' => $this->config->paths['mu_plugins_root'], 'kind' => 'mu-plugin-package'],
        ];
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
