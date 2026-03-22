<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class RuntimeStager
{
    public function __construct(
        private readonly Config $config,
        private readonly RuntimeInspector $runtimeInspector,
    ) {
    }

    /**
     * @return list<string>
     */
    public function stage(string $outputDirectory): array
    {
        $stagedPaths = [];
        $absoluteOutput = $this->config->stageDir($outputDirectory);
        $this->runtimeInspector->clearPath($absoluteOutput);

        if (! is_dir($absoluteOutput) && ! mkdir($absoluteOutput, 0775, true) && ! is_dir($absoluteOutput)) {
            throw new RuntimeException(sprintf('Unable to create runtime staging directory: %s', $absoluteOutput));
        }

        if ($this->config->profile === 'full-core' && $this->config->coreEnabled()) {
            foreach ($this->fullCoreEntries() as $entry) {
                $source = $this->config->repoRoot . '/' . $entry;

                if (! file_exists($source)) {
                    continue;
                }

                $this->runtimeInspector->copyPath($source, $absoluteOutput . '/' . $entry, [$this->config->paths['content_root']]);
                $stagedPaths[] = $entry;
            }
        }

        foreach ($this->config->dependencies() as $dependency) {
            if ($dependency['management'] === 'ignored' || ! $this->config->shouldStageDependency($dependency)) {
                continue;
            }

            $relativePath = (string) $dependency['path'];
            $source = $this->config->repoRoot . '/' . $relativePath;
            $this->runtimeInspector->assertPathIsClean($source, (array) $dependency['policy']['allow_runtime_paths']);

            if ($dependency['management'] === 'managed') {
                $checksum = $this->runtimeInspector->computeChecksum($source);

                if ($checksum !== $dependency['checksum']) {
                    throw new RuntimeException(sprintf(
                        'Managed dependency checksum mismatch for %s. Expected %s but found %s.',
                        $dependency['component_key'],
                        $dependency['checksum'],
                        $checksum
                    ));
                }
            }

            $this->runtimeInspector->copyPath($source, $absoluteOutput . '/' . $relativePath);
            $stagedPaths[] = $relativePath;
        }

        if ($this->config->isKindStaged('runtime-file')) {
            foreach ((new RuntimeOwnershipInspector($this->config))->allowedRuntimePaths() as $path) {
                if (! file_exists($path['absolute_path']) && ! is_link($path['absolute_path'])) {
                    continue;
                }

                $translatedAllowList = $this->translatedAllowPathsForRoot($path['path'], (array) $this->config->runtime['allow_runtime_paths']);
                $this->runtimeInspector->assertPathIsClean($path['absolute_path'], $translatedAllowList);
                $this->runtimeInspector->copyPath($path['absolute_path'], $absoluteOutput . '/' . $path['path']);
                $stagedPaths[] = $path['path'];
            }
        }

        if ($this->config->isRelaxedManifestMode()) {
            foreach ((new RuntimeOwnershipInspector($this->config))->undeclaredRuntimePaths() as $entry) {
                if (! $this->config->isKindStaged($entry['kind'])) {
                    continue;
                }

                $this->runtimeInspector->assertPathIsClean($entry['absolute_path']);
                $this->runtimeInspector->copyPath($entry['absolute_path'], $absoluteOutput . '/' . $entry['path']);
                $stagedPaths[] = $entry['path'];
            }
        }

        $this->runtimeInspector->assertTreeIsClean($absoluteOutput);

        $stagedPaths = array_values(array_unique($stagedPaths));
        sort($stagedPaths);

        return $stagedPaths;
    }

    /**
     * @param list<string> $allowPaths
     * @return list<string>
     */
    private function translatedAllowPathsForRoot(string $rootPath, array $allowPaths): array
    {
        $translated = [];

        foreach ($allowPaths as $allowPath) {
            if ($allowPath === $rootPath) {
                $translated[] = '';
                continue;
            }

            if (str_starts_with($allowPath, $rootPath . '/')) {
                $translated[] = substr($allowPath, strlen($rootPath) + 1);
            }
        }

        return array_values(array_unique($translated));
    }

    /**
     * @return list<string>
     */
    private function fullCoreEntries(): array
    {
        $entries = [];

        foreach (scandir($this->config->repoRoot) ?: [] as $entry) {
            if (in_array($entry, ['.', '..', '.git', '.github', '.wp-core-base', 'docs', 'tools', $this->config->paths['content_root']], true)) {
                continue;
            }

            if ($entry === 'wp-admin' || $entry === 'wp-includes') {
                $entries[] = $entry;
                continue;
            }

            if ($entry === 'index.php' || $entry === 'license.txt' || $entry === 'readme.html' || $entry === 'xmlrpc.php' || fnmatch('wp-*.php', $entry)) {
                $entries[] = $entry;
            }
        }

        sort($entries);
        return $entries;
    }
}
