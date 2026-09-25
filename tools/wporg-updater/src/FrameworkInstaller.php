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
    public function plan(string $payloadRoot, string $distributionPath): array
    {
        $plan = $this->buildPlan($payloadRoot, $distributionPath);

        return $this->publicPlan($plan);
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
        $plan = $this->buildPlan($payloadRoot, $distributionPath);
        /** @var FrameworkConfig $currentFramework */
        $currentFramework = $plan['current_framework'];
        /** @var FrameworkConfig $payloadFramework */
        $payloadFramework = $plan['payload_framework'];
        /** @var Config $downstreamConfig */
        $downstreamConfig = $plan['downstream_config'];
        /** @var string $distributionPath */
        $distributionPath = $plan['distribution_path'];
        /** @var string $targetPath */
        $targetPath = $plan['target_path'];
        /** @var array<string, string> $renderedFiles */
        $renderedFiles = $plan['rendered_files'];
        /** @var array<string, string> $managedFileChecksums */
        $managedFileChecksums = $plan['managed_file_checksums'];
        /** @var list<string> $changedPaths */
        $changedPaths = $plan['changed_paths'];
        /** @var list<string> $refreshedFiles */
        $refreshedFiles = $plan['refreshed_files'];
        /** @var list<string> $removedFiles */
        $removedFiles = $plan['removed_files'];
        /** @var list<string> $skippedFiles */
        $skippedFiles = $plan['skipped_files'];
        $stagingPath = $this->repoRoot . '/.wp-core-base/build/framework-install-' . bin2hex(random_bytes(4));
        $backupPath = $this->repoRoot . '/.wp-core-base/build/framework-install-backup-' . bin2hex(random_bytes(4));
        $stateBackupRoot = $this->repoRoot . '/.wp-core-base/build/framework-install-state-' . bin2hex(random_bytes(4));
        $pathSwapper = new PathSwapWithRollback($this->runtimeInspector);
        $managedFileStates = [];
        $frameworkState = null;
        $governanceState = null;
        $swappedIntoPlace = false;
        $committed = false;
        $preserveRecovery = false;
        $refreshedFileLookup = array_fill_keys($refreshedFiles, true);

        ConfigPathRules::assertNoSymlinkDescendants($this->repoRoot, ".wp-core-base/build");
        $this->runtimeInspector->clearPath($stagingPath);
        $this->runtimeInspector->clearPath($backupPath);
        $this->runtimeInspector->clearPath($stateBackupRoot);

        try {
            $this->runtimeInspector->copyPath($payloadRoot, $stagingPath);
            // ZipArchive::extractTo does not retain ZIP executable modes, including
            // payloads extracted by legacy clients. Only the approved launcher needs it.
            $launcher = $stagingPath . '/bin/wp-core-base';
            if (! is_file($launcher) || is_link($launcher) || ! chmod($launcher, 0755)) {
                throw new RuntimeException('Unable to make the installed framework launcher executable.');
            }
            $pathSwapper->swap($targetPath, $stagingPath, $backupPath, $this->repoRoot);
            $swappedIntoPlace = true;

            foreach ($renderedFiles as $relativePath => $contents) {
                if (! isset($refreshedFileLookup[$relativePath])) {
                    continue;
                }

                ConfigPathRules::assertNoSymlinkDescendants($this->repoRoot, $relativePath);
                $absolutePath = $this->repoRoot . '/' . $relativePath;
                $directory = dirname($absolutePath);

                if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
                    throw new RuntimeException(sprintf('Unable to create framework-managed directory: %s', $directory));
                }

                $managedFileStates[$relativePath] ??= $this->captureFileState(
                    $absolutePath,
                    $stateBackupRoot . '/managed/' . str_replace('/', '--', $relativePath)
                );
                (new AtomicFileWriter())->write($absolutePath, $contents);
            }

            foreach ($removedFiles as $relativePath) {
                ConfigPathRules::assertNoSymlinkDescendants($this->repoRoot, $relativePath);
                $absolutePath = $this->repoRoot . '/' . $relativePath;
                $managedFileStates[$relativePath] ??= $this->captureFileState(
                    $absolutePath,
                    $stateBackupRoot . '/managed/' . str_replace('/', '--', $relativePath)
                );
                $this->runtimeInspector->clearPath($absolutePath);
            }

            $framework = $currentFramework->withInstalledRelease(
                version: $payloadFramework->version,
                wordPressCoreVersion: $payloadFramework->baseline['wordpress_core'],
                managedComponents: $payloadFramework->baseline['managed_components'],
                managedFiles: $managedFileChecksums,
                releaseSource: $payloadFramework->releaseSource,
                distributionPath: $distributionPath
            );
            ConfigPathRules::assertNoSymlinkDescendants($this->repoRoot, ".wp-core-base/framework.php");
            $frameworkState = $this->captureFileState($framework->path, $stateBackupRoot . '/framework.php');
            (new FrameworkWriter())->write($framework);
            $changedPaths[] = '.wp-core-base/framework.php';
            $governancePath = $this->repoRoot . '/' . FrameworkRuntimeFiles::governanceDataPath($downstreamConfig);
            ConfigPathRules::assertNoSymlinkDescendants($this->repoRoot, FrameworkRuntimeFiles::governanceDataPath($downstreamConfig));
            $governanceState = $this->captureFileState($governancePath, $stateBackupRoot . '/admin-governance.php');
            (new AdminGovernanceExporter())->refresh($downstreamConfig);
            $changedPaths[] = FrameworkRuntimeFiles::governanceDataPath($downstreamConfig);
            // The installation is committed before best-effort backup cleanup.
            $committed = true;
            $swappedIntoPlace = false;
            try {
                $pathSwapper->finalize($backupPath);
            } catch (Throwable $cleanupFailure) {
                $preserveRecovery = true;
                fwrite(STDERR, sprintf("[warn] Framework installation committed; cleanup failed. Recovery data retained at %s and %s: %s\n", $backupPath, $stateBackupRoot, $cleanupFailure->getMessage()));
            }

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
            $recoveryErrors = [];
            if (! $committed) {
                foreach (array_filter([$governanceState, $frameworkState, ...array_reverse($managedFileStates)]) as $state) {
                    try {
                        $this->restoreFileState($state);
                    } catch (Throwable $restoreFailure) {
                        $recoveryErrors[] = $restoreFailure->getMessage();
                    }
                }
                if ($swappedIntoPlace) {
                    try {
                        $pathSwapper->rollback($targetPath, $backupPath);
                    } catch (Throwable $restoreFailure) {
                        $recoveryErrors[] = $restoreFailure->getMessage();
                    }
                }
            }
            // A failed swap may already have attempted rollback internally.
            $preserveRecovery = $recoveryErrors !== [] || file_exists($backupPath);
            if ($preserveRecovery) {
                throw new RuntimeException(sprintf(
                    'Framework installation failed: %s. Recovery data retained at %s and %s. Recovery errors: %s',
                    $throwable->getMessage(), $backupPath, $stateBackupRoot,
                    $recoveryErrors === [] ? 'Inspect retained backup before retrying.' : implode('; ', $recoveryErrors)
                ), 0, $throwable);
            }
            throw $throwable;
        } finally {
            foreach ($preserveRecovery ? [$stagingPath] : [$stagingPath, $backupPath, $stateBackupRoot] as $cleanupPath) {
                try {
                    $this->runtimeInspector->clearPath($cleanupPath);
                } catch (Throwable $cleanupFailure) {
                    fwrite(STDERR, sprintf("[warn] Recovery/cleanup path retained at %s: %s\n", $cleanupPath, $cleanupFailure->getMessage()));
                }
            }
        }
    }

    /**
     * @return array{
     *   current_framework:FrameworkConfig,
     *   payload_framework:FrameworkConfig,
     *   downstream_config:Config,
     *   distribution_path:string,
     *   target_path:string,
     *   rendered_files:array<string, string>,
     *   managed_file_checksums:array<string, string>,
     *   changed_paths:list<string>,
     *   refreshed_files:list<string>,
     *   removed_files:list<string>,
     *   skipped_files:list<string>
     * }
     */
    private function buildPlan(string $payloadRoot, string $distributionPath): array
    {
        $currentFramework = FrameworkConfig::load($this->repoRoot);
        $downstreamConfig = Config::load($this->repoRoot);
        $distributionPath = ConfigPathRules::normalizedRelativePath(
            trim($distributionPath) === '' ? $currentFramework->distributionPath() : $distributionPath,
            'distribution.path'
        );
        ConfigPathRules::assertSafeFrameworkDistributionPath(
            $this->repoRoot,
            $distributionPath,
            $downstreamConfig->paths,
            array_merge($downstreamConfig->runtime['ownership_roots'], array_column($downstreamConfig->dependencies(), 'path'))
        );
        $payloadFramework = FrameworkConfig::load($payloadRoot);
        $targetPath = $this->repoRoot . '/' . $distributionPath;
        $renderedFiles = (new DownstreamScaffolder($payloadRoot, $this->repoRoot))->renderFrameworkManagedFiles(
            $distributionPath,
            [],
            $downstreamConfig->paths,
            $downstreamConfig->automationProvider()
        );
        $previousRenderedFiles = $this->previousRenderedFrameworkManagedFiles($targetPath, $distributionPath, $downstreamConfig);
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
                $currentContents = file_get_contents($absolutePath);
                $currentChecksum = $this->contentsChecksum($currentContents === false ? '' : $currentContents);

                if (! hash_equals((string) $managedChecksum, $currentChecksum)) {
                    $skippedFiles[] = $relativePath;
                    continue;
                }
            }

            $removedFiles[] = $relativePath;
            $changedPaths[] = $relativePath;
        }

        foreach ($renderedFiles as $relativePath => $contents) {
            $absolutePath = $this->repoRoot . '/' . $relativePath;
            $managedChecksum = $currentFramework->managedFiles()[$relativePath] ?? null;
            $renderedChecksum = $this->contentsChecksum($contents);

            if ($managedChecksum !== null && is_file($absolutePath)) {
                $currentContents = file_get_contents($absolutePath);
                $currentChecksum = $this->contentsChecksum($currentContents === false ? '' : $currentContents);

                if (! hash_equals($managedChecksum, $currentChecksum)) {
                    $managedFileChecksums[$relativePath] = $managedChecksum;
                    $skippedFiles[] = $relativePath;
                    continue;
                }
            } elseif ($managedChecksum === null && is_file($absolutePath)) {
                $currentContents = file_get_contents($absolutePath);
                $currentChecksum = $this->contentsChecksum($currentContents === false ? '' : $currentContents);
                $previousRenderedChecksum = isset($previousRenderedFiles[$relativePath])
                    ? $this->contentsChecksum($previousRenderedFiles[$relativePath])
                    : null;

                if ($previousRenderedChecksum === null || ! hash_equals($previousRenderedChecksum, $currentChecksum)) {
                    $managedFileChecksums[$relativePath] = $currentChecksum;
                    $skippedFiles[] = $relativePath;
                    continue;
                }
            }

            $managedFileChecksums[$relativePath] = $renderedChecksum;
            $refreshedFiles[] = $relativePath;
            $changedPaths[] = $relativePath;
        }

        return [
            'current_framework' => $currentFramework,
            'payload_framework' => $payloadFramework,
            'downstream_config' => $downstreamConfig,
            'distribution_path' => $distributionPath,
            'target_path' => $targetPath,
            'rendered_files' => $renderedFiles,
            'managed_file_checksums' => $managedFileChecksums,
            'changed_paths' => array_values(array_unique($changedPaths)),
            'refreshed_files' => array_values(array_unique($refreshedFiles)),
            'removed_files' => array_values(array_unique($removedFiles)),
            'skipped_files' => array_values(array_unique($skippedFiles)),
        ];
    }

    /**
     * @param array{
     *   changed_paths:list<string>,
     *   refreshed_files:list<string>,
     *   removed_files:list<string>,
     *   skipped_files:list<string>,
     *   payload_framework:FrameworkConfig
     * } $plan
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
    private function publicPlan(array $plan): array
    {
        /** @var FrameworkConfig $payloadFramework */
        $payloadFramework = $plan['payload_framework'];

        return [
            'changed_paths' => $plan['changed_paths'],
            'refreshed_files' => $plan['refreshed_files'],
            'removed_files' => $plan['removed_files'],
            'skipped_files' => $plan['skipped_files'],
            'framework_version' => $payloadFramework->version,
            'wordpress_core' => $payloadFramework->baseline['wordpress_core'],
            'managed_components' => $payloadFramework->baseline['managed_components'],
        ];
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

        if (! $state['existed']) {
            $this->runtimeInspector->clearPath($path);
            return;
        }

        $backupPath = $state['backup_path'];

        if (! is_string($backupPath) || $backupPath === '' || (! file_exists($backupPath) && ! is_link($backupPath))) {
            throw new RuntimeException(sprintf('Unable to restore backup for %s.', $path));
        }

        $this->runtimeInspector->clearPath($path);
        $this->runtimeInspector->copyPath($backupPath, $path);
    }
}
