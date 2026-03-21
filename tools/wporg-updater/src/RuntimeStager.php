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
        $this->runtimeInspector->clearDirectory($absoluteOutput);

        if (! is_dir($absoluteOutput) && ! mkdir($absoluteOutput, 0775, true) && ! is_dir($absoluteOutput)) {
            throw new RuntimeException(sprintf('Unable to create runtime staging directory: %s', $absoluteOutput));
        }

        if ($this->config->profile === 'full-core' && $this->config->coreEnabled()) {
            foreach ($this->fullCoreEntries() as $entry) {
                $source = $this->config->repoRoot . '/' . $entry;

                if (! file_exists($source)) {
                    continue;
                }

                $destination = $absoluteOutput . '/' . $entry;

                if (is_dir($source)) {
                    $this->runtimeInspector->copyTree($source, $destination, [$this->config->paths['content_root']]);
                } else {
                    $targetDir = dirname($destination);

                    if (! is_dir($targetDir) && ! mkdir($targetDir, 0775, true) && ! is_dir($targetDir)) {
                        throw new RuntimeException(sprintf('Unable to create directory: %s', $targetDir));
                    }

                    if (! copy($source, $destination)) {
                        throw new RuntimeException(sprintf('Failed to stage runtime file: %s', $entry));
                    }
                }

                $stagedPaths[] = $entry;
            }
        }

        $contentRoot = $this->config->paths['content_root'];
        $contentOutput = $absoluteOutput . '/' . $contentRoot;

        if (! is_dir($contentOutput) && ! mkdir($contentOutput, 0775, true) && ! is_dir($contentOutput)) {
            throw new RuntimeException(sprintf('Unable to create staged content root: %s', $contentOutput));
        }

        $dependencyRoots = [];

        foreach ($this->config->dependencies() as $dependency) {
            $relativePath = $dependency['path'];
            $source = $this->config->repoRoot . '/' . $relativePath;

            if (! file_exists($source)) {
                continue;
            }

            if ($dependency['management'] === 'ignored') {
                $dependencyRoots[] = $relativePath;
                continue;
            }

            $this->runtimeInspector->assertTreeIsClean($source, (array) $dependency['policy']['allow_runtime_paths']);

            if ($dependency['management'] === 'managed') {
                $checksum = $this->runtimeInspector->computeTreeChecksum($source);

                if ($checksum !== $dependency['checksum']) {
                    throw new RuntimeException(sprintf(
                        'Managed dependency checksum mismatch for %s. Expected %s but found %s.',
                        $dependency['component_key'],
                        $dependency['checksum'],
                        $checksum
                    ));
                }
            }

            $destination = $absoluteOutput . '/' . $relativePath;

            if (is_dir($source)) {
                $this->runtimeInspector->copyTree($source, $destination);
            } else {
                $targetDir = dirname($destination);

                if (! is_dir($targetDir) && ! mkdir($targetDir, 0775, true) && ! is_dir($targetDir)) {
                    throw new RuntimeException(sprintf('Unable to create directory: %s', $targetDir));
                }

                if (! copy($source, $destination)) {
                    throw new RuntimeException(sprintf('Failed to stage runtime file: %s', $relativePath));
                }
            }

            $stagedPaths[] = $relativePath;
            $dependencyRoots[] = $relativePath;
        }

        $sourceContentRoot = $this->config->repoRoot . '/' . $contentRoot;

        if (is_dir($sourceContentRoot)) {
            $this->runtimeInspector->copyTree(
                $sourceContentRoot,
                $contentOutput,
                $this->dependencyPathsRelativeToContentRoot($dependencyRoots, $contentRoot)
            );
            $stagedPaths[] = $contentRoot;
        }

        $this->runtimeInspector->assertTreeIsClean($absoluteOutput);

        return array_values(array_unique($stagedPaths));
    }

    /**
     * @param list<string> $dependencyRoots
     * @return list<string>
     */
    private function dependencyPathsRelativeToContentRoot(array $dependencyRoots, string $contentRoot): array
    {
        $paths = [];

        foreach ($dependencyRoots as $path) {
            if (! str_starts_with($path, $contentRoot . '/')) {
                continue;
            }

            $paths[] = substr($path, strlen($contentRoot) + 1);
        }

        return array_values(array_filter($paths, static fn (string $path): bool => $path !== ''));
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
