<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;
use ZipArchive;

final class ManagedDependencyInstaller
{
    public function __construct(
        private readonly GitRunnerInterface $gitRunner,
        private readonly RuntimeInspector $runtimeInspector,
        private readonly ManifestWriter $manifestWriter,
        private readonly ManagedSourceRegistry $managedSourceRegistry,
        private readonly ?AdminGovernanceExporter $adminGovernanceExporter = null,
    ) {
    }

    /**
     * @param array<string, mixed> $dependency
     * @param array<string, mixed> $releaseData
     * @return array{config:Config,dependency:array<string, mixed>}
     */
    public function checkoutAndApplyDependencyVersion(
        Config $config,
        string $defaultBranch,
        string $branch,
        array $dependency,
        array $releaseData,
        bool $resetToBase = false,
    ): array {
        $this->gitRunner->checkoutBranch($defaultBranch, $branch, $resetToBase);
        $tempDir = sys_get_temp_dir() . '/wporg-update-' . bin2hex(random_bytes(6));

        if (! mkdir($tempDir, 0775, true) && ! is_dir($tempDir)) {
            throw new RuntimeException(sprintf('Failed to create temp directory: %s', $tempDir));
        }

        $archivePath = $tempDir . '/dependency.zip';
        $extractPath = $tempDir . '/extract';

        if (! mkdir($extractPath, 0775, true) && ! is_dir($extractPath)) {
            throw new RuntimeException(sprintf('Failed to create extraction directory: %s', $extractPath));
        }

        try {
            $this->downloadArchiveForRelease($dependency, $releaseData, $archivePath);

            $zip = new ZipArchive();

            if ($zip->open($archivePath) !== true) {
                throw new RuntimeException(sprintf('Failed to open dependency archive: %s', $archivePath));
            }

            ZipExtractor::extractValidated($zip, $extractPath);
            $zip->close();

            $sourcePath = ExtractedPayloadLocator::locateByExpectedEntry(
                $extractPath,
                trim((string) ($releaseData['archive_subdir'] ?? ''), '/'),
                $this->expectedArchiveEntry($config, $dependency),
                (string) $dependency['slug'],
                $config->isFileKind((string) $dependency['kind']),
            );

            [$sanitizePaths, $sanitizeFiles] = $config->managedSanitizeRules($dependency);
            $this->runtimeInspector->stripPath($sourcePath, $sanitizePaths, $sanitizeFiles);
            $this->runtimeInspector->assertPathIsClean(
                $sourcePath,
                (array) $dependency['policy']['allow_runtime_paths'],
                [],
                $sanitizePaths,
                $sanitizeFiles
            );

            $destinationPath = $config->repoRoot . '/' . trim((string) $dependency['path'], '/');
            $this->runtimeInspector->clearPath($destinationPath);
            $this->runtimeInspector->copyPath($sourcePath, $destinationPath);

            $expectedMainFile = $config->isFileKind((string) $dependency['kind'])
                ? $destinationPath
                : $destinationPath . '/' . trim((string) $dependency['main_file'], '/');

            if (! is_file($expectedMainFile)) {
                throw new RuntimeException(sprintf('Updated archive did not contain expected main file %s.', $expectedMainFile));
            }

            $checksum = $this->runtimeInspector->computeChecksum($destinationPath, [], $sanitizePaths, $sanitizeFiles);
            $nextConfig = $this->updateDependencyInManifest(
                $config,
                (string) $dependency['component_key'],
                (string) $releaseData['version'],
                $checksum
            );
            $this->refreshAdminGovernance($nextConfig);

            return [
                'config' => $nextConfig,
                'dependency' => $nextConfig->dependencyByKey((string) $dependency['component_key']),
            ];
        } finally {
            $this->runtimeInspector->clearPath($tempDir);
        }
    }

    /**
     * @param array<string, mixed> $dependency
     * @return list<string>
     */
    public function commitPathsForDependency(Config $config, array $dependency): array
    {
        $paths = [$dependency['path'], $this->relativeManifestPath($config)];

        if ($this->adminGovernanceExporter !== null) {
            $paths[] = FrameworkRuntimeFiles::governanceDataPath($config);
        }

        return array_values(array_unique($paths));
    }

    /**
     * @param array<string, mixed> $dependency
     * @param array<string, mixed> $releaseData
     */
    private function downloadArchiveForRelease(array $dependency, array $releaseData, string $archivePath): void
    {
        $this->managedSourceRegistry->for($dependency)->downloadReleaseToFile($dependency, $releaseData, $archivePath);

        $expectedChecksum = $releaseData['expected_checksum_sha256'] ?? null;

        if (is_string($expectedChecksum) && $expectedChecksum !== '') {
            FileChecksum::assertSha256Matches(
                $archivePath,
                $expectedChecksum,
                sprintf('%s archive for %s', (string) $dependency['source'], (string) $dependency['component_key'])
            );
        }
    }

    private function updateDependencyInManifest(Config $config, string $componentKey, string $version, string $checksum): Config
    {
        $dependencies = $config->dependencies();

        foreach ($dependencies as $index => $dependency) {
            if ($dependency['component_key'] !== $componentKey) {
                continue;
            }

            $dependencies[$index]['version'] = $version;
            $dependencies[$index]['checksum'] = $checksum;
            $nextConfig = $config->withDependencies($dependencies);
            $this->manifestWriter->write($nextConfig);

            return $nextConfig;
        }

        throw new RuntimeException(sprintf('Unable to update manifest for %s.', $componentKey));
    }

    private function relativeManifestPath(Config $config): string
    {
        return ltrim(str_replace($config->repoRoot, '', $config->manifestPath), '/');
    }

    private function refreshAdminGovernance(Config $config): void
    {
        if ($this->adminGovernanceExporter === null) {
            return;
        }

        $this->adminGovernanceExporter->refresh($config);
    }

    /**
     * @param array<string, mixed> $dependency
     */
    private function expectedArchiveEntry(Config $config, array $dependency): string
    {
        if ($config->isFileKind((string) $dependency['kind'])) {
            $mainFile = $dependency['main_file'] ?? null;

            return is_string($mainFile) && $mainFile !== ''
                ? trim($mainFile, '/')
                : basename((string) $dependency['path']);
        }

        return trim((string) $dependency['main_file'], '/');
    }
}
