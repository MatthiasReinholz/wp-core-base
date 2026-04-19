<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use Throwable;
use RuntimeException;

final class FrameworkInstaller
{
    public function __construct(
        private readonly string $repoRoot,
        private readonly RuntimeInspector $runtimeInspector,
    ) {
    }

    /**
     * @return array{
     *   changed_paths:list<string>,
     *   refreshed_files:list<string>,
     *   removed_files:list<string>,
     *   skipped_files:list<string>,
     *   framework_version:string,
     *   wordpress_core:string,
     *   managed_components:list<array{name:string, version:string, kind:string}>
     * }
     */
    public function apply(string $payloadRoot, string $distributionPath): array
    {
        $currentFramework = FrameworkConfig::load($this->repoRoot);
        $payloadFramework = FrameworkConfig::load($payloadRoot);
        $distributionPath = trim($distributionPath) === '' ? $currentFramework->distributionPath() : trim($distributionPath, '/');
        $targetPath = $distributionPath === '.' ? $this->repoRoot : $this->repoRoot . '/' . $distributionPath;
        $stagingPath = $this->repoRoot . '/.wp-core-base/build/framework-install-' . bin2hex(random_bytes(4));
        $backupPath = $this->repoRoot . '/.wp-core-base/build/framework-install-backup-' . bin2hex(random_bytes(4));
        $stateBackupRoot = $this->repoRoot . '/.wp-core-base/build/framework-install-state-' . bin2hex(random_bytes(4));
        $pathSwapper = new PathSwapWithRollback($this->runtimeInspector);
        $managedFileStates = [];
        $frameworkState = null;
        $governanceState = null;
        $swappedIntoPlace = false;

        $this->runtimeInspector->clearPath($stagingPath);
        $this->runtimeInspector->clearPath($backupPath);
        $this->runtimeInspector->clearPath($stateBackupRoot);

        try {
            $this->runtimeInspector->copyPath($payloadRoot, $stagingPath);
            $pathSwapper->swap($targetPath, $stagingPath, $backupPath, $this->repoRoot);
            $swappedIntoPlace = true;
            $downstreamConfig = Config::load($this->repoRoot);
            $renderedFiles = (new DownstreamScaffolder($targetPath, $this->repoRoot))->renderFrameworkManagedFiles(
                $distributionPath,
                [],
                $downstreamConfig->paths,
                $downstreamConfig->automationProvider()
            );
            $previousRenderedFiles = $this->previousRenderedFrameworkManagedFiles(
                $backupPath,
                $distributionPath,
                $downstreamConfig
            );
            $managedFileChecksums = [];
            $changedPaths = [$distributionPath];
            $refreshedFiles = [];
            $removedFiles = [];
            $skippedFiles = [];
            $staleManagedFiles = array_diff_key($currentFramework->managedFiles(), $renderedFiles);

            foreach ($staleManagedFiles as $relativePath => $managedChecksum) {
                $absolutePath = $this->repoRoot . '/' . $relativePath;

                if (! file_exists($absolutePath) && ! is_link($absolutePath)) {
                    continue;
                }

                if (is_file($absolutePath)) {
                    $currentChecksum = $this->contentsChecksum((string) file_get_contents($absolutePath));

                    if (! hash_equals((string) $managedChecksum, $currentChecksum)) {
                        $skippedFiles[] = $relativePath;
                        continue;
                    }
                }

                $managedFileStates[$relativePath] ??= $this->captureFileState(
                    $absolutePath,
                    $stateBackupRoot . '/managed/' . str_replace('/', '--', $relativePath)
                );
                $this->runtimeInspector->clearPath($absolutePath);
                $removedFiles[] = $relativePath;
                $changedPaths[] = $relativePath;
            }

            foreach ($renderedFiles as $relativePath => $contents) {
                $absolutePath = $this->repoRoot . '/' . $relativePath;
                $managedChecksum = $currentFramework->managedFiles()[$relativePath] ?? null;
                $renderedChecksum = $this->contentsChecksum($contents);

                if ($managedChecksum !== null && is_file($absolutePath)) {
                    $currentChecksum = $this->contentsChecksum((string) file_get_contents($absolutePath));

                    if (! hash_equals($managedChecksum, $currentChecksum)) {
                        $managedFileChecksums[$relativePath] = $managedChecksum;
                        $skippedFiles[] = $relativePath;
                        continue;
                    }
                } elseif ($managedChecksum === null && is_file($absolutePath)) {
                    $currentChecksum = $this->contentsChecksum((string) file_get_contents($absolutePath));
                    $previousRenderedChecksum = isset($previousRenderedFiles[$relativePath])
                        ? $this->contentsChecksum($previousRenderedFiles[$relativePath])
                        : null;

                    if ($previousRenderedChecksum === null || ! hash_equals($previousRenderedChecksum, $currentChecksum)) {
                        $managedFileChecksums[$relativePath] = $currentChecksum;
                        $skippedFiles[] = $relativePath;
                        continue;
                    }
                }

                $directory = dirname($absolutePath);

                if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
                    throw new RuntimeException(sprintf('Unable to create framework-managed directory: %s', $directory));
                }

                $managedFileStates[$relativePath] ??= $this->captureFileState(
                    $absolutePath,
                    $stateBackupRoot . '/managed/' . str_replace('/', '--', $relativePath)
                );
                (new AtomicFileWriter())->write($absolutePath, $contents);

                $managedFileChecksums[$relativePath] = $renderedChecksum;
                $refreshedFiles[] = $relativePath;
                $changedPaths[] = $relativePath;
            }

            $framework = $currentFramework->withInstalledRelease(
                version: $payloadFramework->version,
                wordPressCoreVersion: $payloadFramework->baseline['wordpress_core'],
                managedComponents: $payloadFramework->baseline['managed_components'],
                managedFiles: $managedFileChecksums,
                releaseSource: $payloadFramework->releaseSource,
                distributionPath: $distributionPath
            );
            $frameworkState = $this->captureFileState($framework->path, $stateBackupRoot . '/framework.php');
            (new FrameworkWriter())->write($framework);
            $changedPaths[] = '.wp-core-base/framework.php';
            $governancePath = $this->repoRoot . '/' . FrameworkRuntimeFiles::governanceDataPath($downstreamConfig);
            $governanceState = $this->captureFileState($governancePath, $stateBackupRoot . '/admin-governance.php');
            (new AdminGovernanceExporter($this->runtimeInspector))->refresh($downstreamConfig);
            $changedPaths[] = FrameworkRuntimeFiles::governanceDataPath($downstreamConfig);
            $pathSwapper->finalize($backupPath);
            $swappedIntoPlace = false;

            return [
                'changed_paths' => array_values(array_unique($changedPaths)),
                'refreshed_files' => $refreshedFiles,
                'removed_files' => $removedFiles,
                'skipped_files' => $skippedFiles,
                'framework_version' => $framework->version,
                'wordpress_core' => $framework->baseline['wordpress_core'],
                'managed_components' => $framework->baseline['managed_components'],
            ];
        } catch (Throwable $throwable) {
            if ($governanceState !== null) {
                $this->restoreFileState($governanceState);
            }

            if ($frameworkState !== null) {
                $this->restoreFileState($frameworkState);
            }

            foreach ($managedFileStates as $state) {
                $this->restoreFileState($state);
            }

            if ($swappedIntoPlace) {
                $pathSwapper->rollback($targetPath, $backupPath);
            }

            throw $throwable;
        } finally {
            $this->runtimeInspector->clearPath($stagingPath);
            $this->runtimeInspector->clearPath($backupPath);
            $this->runtimeInspector->clearPath($stateBackupRoot);
        }
    }

    private function contentsChecksum(string $contents): string
    {
        return 'sha256:' . hash('sha256', $contents);
    }

    /**
     * @return array<string, string>
     */
    private function previousRenderedFrameworkManagedFiles(string $frameworkRoot, string $distributionPath, Config $config): array
    {
        if (! is_dir($frameworkRoot)) {
            return [];
        }

        try {
            return (new DownstreamScaffolder($frameworkRoot, $this->repoRoot))->renderFrameworkManagedFiles(
                $distributionPath,
                [],
                $config->paths,
                $config->automationProvider()
            );
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array{path:string, existed:bool, backup_path:?string}
     */
    private function captureFileState(string $path, string $backupPath): array
    {
        if (! file_exists($path) && ! is_link($path)) {
            return [
                'path' => $path,
                'existed' => false,
                'backup_path' => null,
            ];
        }

        $backupDirectory = dirname($backupPath);

        if (! is_dir($backupDirectory) && ! mkdir($backupDirectory, 0775, true) && ! is_dir($backupDirectory)) {
            throw new RuntimeException(sprintf('Unable to create backup directory: %s', $backupDirectory));
        }

        $this->runtimeInspector->copyPath($path, $backupPath);

        return [
            'path' => $path,
            'existed' => true,
            'backup_path' => $backupPath,
        ];
    }

    /**
     * @param array{path:string, existed:bool, backup_path:?string} $state
     */
    private function restoreFileState(array $state): void
    {
        $path = $state['path'];

        $this->runtimeInspector->clearPath($path);

        if (! $state['existed']) {
            return;
        }

        $backupPath = $state['backup_path'];

        if (! is_string($backupPath) || $backupPath === '' || (! file_exists($backupPath) && ! is_link($backupPath))) {
            throw new RuntimeException(sprintf('Unable to restore backup for %s.', $path));
        }

        $this->runtimeInspector->copyPath($backupPath, $path);
    }
}
