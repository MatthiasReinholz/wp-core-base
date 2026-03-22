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
            [$globalAllowPaths, $globalStripPaths, $globalStripFiles, $globalSanitizePaths, $globalSanitizeFiles] = $this->translatedRuntimeRulesForRoot($relativePath);
            [$sourceStripPaths, $sourceStripFiles] = $this->sourceValidationRules(
                $dependency,
                $globalStripPaths,
                $globalStripFiles,
                $globalSanitizePaths,
                $globalSanitizeFiles
            );

            $this->runtimeInspector->assertPathIsClean(
                $source,
                array_values(array_unique(array_merge((array) $dependency['policy']['allow_runtime_paths'], $globalAllowPaths))),
                [],
                $sourceStripPaths,
                $sourceStripFiles
            );

            if ($dependency['management'] === 'managed') {
                [$sanitizePaths, $sanitizeFiles] = $this->sanitizeRules($dependency, $globalSanitizePaths, $globalSanitizeFiles);
                $checksum = $this->runtimeInspector->computeChecksum($source, [], $sanitizePaths, $sanitizeFiles);

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
            $this->runtimeInspector->copyPath($source, $destination);

            if ($dependency['management'] === 'managed') {
                [$sanitizePaths, $sanitizeFiles] = $this->sanitizeRules($dependency, $globalSanitizePaths, $globalSanitizeFiles);
                $this->runtimeInspector->stripPath($destination, $sanitizePaths, $sanitizeFiles);
            } else {
                $this->runtimeInspector->stripPath(
                    $destination,
                    array_values(array_unique(array_merge($this->config->dependencyStripPaths($dependency), $globalStripPaths))),
                    array_values(array_unique(array_merge($this->config->dependencyStripFiles($dependency), $globalStripFiles)))
                );
            }

            $stagedPaths[] = $relativePath;
        }

        $ownershipInspector = new RuntimeOwnershipInspector($this->config);

        if ($this->config->isKindStaged('runtime-file') || $this->config->isKindStaged('runtime-directory')) {
            foreach ($ownershipInspector->allowedRuntimePaths() as $path) {
                if (! file_exists($path['absolute_path']) && ! is_link($path['absolute_path'])) {
                    continue;
                }

                [$allowPaths, $stripPaths, $stripFiles] = $this->translatedRuntimeRulesForRoot($path['path']);

                $this->runtimeInspector->assertPathIsClean(
                    $path['absolute_path'],
                    $allowPaths,
                    [],
                    $this->config->isStagedCleanValidationMode() ? $stripPaths : [],
                    $this->config->isStagedCleanValidationMode() ? $stripFiles : []
                );

                $destination = $absoluteOutput . '/' . $path['path'];
                $this->runtimeInspector->copyPath($path['absolute_path'], $destination);
                $this->runtimeInspector->stripPath($destination, $stripPaths, $stripFiles);
                $stagedPaths[] = $path['path'];
            }
        }

        if ($this->config->isRelaxedManifestMode()) {
            foreach ($ownershipInspector->undeclaredRuntimePaths() as $entry) {
                if (! $this->config->isKindStaged($entry['kind'])) {
                    continue;
                }

                [$allowPaths, $stripPaths, $stripFiles] = $this->translatedRuntimeRulesForRoot($entry['path']);
                $this->runtimeInspector->assertPathIsClean(
                    $entry['absolute_path'],
                    $allowPaths,
                    [],
                    $this->config->isStagedCleanValidationMode() ? $stripPaths : [],
                    $this->config->isStagedCleanValidationMode() ? $stripFiles : []
                );

                $destination = $absoluteOutput . '/' . $entry['path'];
                $this->runtimeInspector->copyPath($entry['absolute_path'], $destination);
                $this->runtimeInspector->stripPath($destination, $stripPaths, $stripFiles);
                $stagedPaths[] = $entry['path'];
            }
        }

        $this->runtimeInspector->assertTreeIsClean($absoluteOutput);

        $stagedPaths = array_values(array_unique($stagedPaths));
        sort($stagedPaths);

        return $stagedPaths;
    }

    /**
     * @param array<string, mixed> $dependency
     * @return array{0:list<string>,1:list<string>}
     */
    private function sourceValidationRules(
        array $dependency,
        array $globalStripPaths,
        array $globalStripFiles,
        array $globalSanitizePaths,
        array $globalSanitizeFiles,
    ): array
    {
        if ($dependency['management'] === 'managed') {
            return $this->sanitizeRules($dependency, $globalSanitizePaths, $globalSanitizeFiles);
        }

        if (! $this->config->isStagedCleanValidationMode()) {
            return [[], []];
        }

        $dependencyStripPaths = $this->config->shouldAllowStripOnStage($dependency) ? $this->config->dependencyStripPaths($dependency) : [];
        $dependencyStripFiles = $this->config->shouldAllowStripOnStage($dependency) ? $this->config->dependencyStripFiles($dependency) : [];

        return [
            array_values(array_unique(array_merge($dependencyStripPaths, $globalStripPaths))),
            array_values(array_unique(array_merge($dependencyStripFiles, $globalStripFiles))),
        ];
    }

    /**
     * @param array<string, mixed> $dependency
     * @return array{0:list<string>,1:list<string>}
     */
    private function sanitizeRules(array $dependency, array $globalSanitizePaths, array $globalSanitizeFiles): array
    {
        return [
            array_values(array_unique(array_merge($globalSanitizePaths, $this->config->dependencySanitizePaths($dependency)))),
            array_values(array_unique(array_merge($globalSanitizeFiles, $this->config->dependencySanitizeFiles($dependency)))),
        ];
    }

    /**
     * @return array{0:list<string>,1:list<string>,2:list<string>,3:list<string>,4:list<string>}
     */
    private function translatedRuntimeRulesForRoot(string $rootPath): array
    {
        $allowPaths = [];
        $stripPaths = [];
        $stripFiles = $this->config->stripFiles();
        $sanitizePaths = [];
        $sanitizeFiles = $this->config->managedSanitizeFiles();

        foreach ((array) $this->config->runtime['allow_runtime_paths'] as $allowPath) {
            if ($allowPath === $rootPath) {
                $allowPaths[] = '';
                continue;
            }

            if (str_starts_with($allowPath, $rootPath . '/')) {
                $allowPaths[] = substr($allowPath, strlen($rootPath) + 1);
            }
        }

        foreach ((array) $this->config->runtime['strip_paths'] as $stripPath) {
            if ($stripPath === $rootPath) {
                $stripPaths[] = '';
                continue;
            }

            if (str_starts_with($stripPath, $rootPath . '/')) {
                $stripPaths[] = substr($stripPath, strlen($rootPath) + 1);
            }
        }

        foreach ((array) $this->config->runtime['managed_sanitize_paths'] as $sanitizePath) {
            if ($sanitizePath === $rootPath) {
                $sanitizePaths[] = '';
                continue;
            }

            if (str_starts_with($sanitizePath, $rootPath . '/')) {
                $sanitizePaths[] = substr($sanitizePath, strlen($rootPath) + 1);
            }
        }

        return [
            array_values(array_unique($allowPaths)),
            array_values(array_unique($stripPaths)),
            array_values(array_unique($stripFiles)),
            array_values(array_unique($sanitizePaths)),
            array_values(array_unique($sanitizeFiles)),
        ];
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
