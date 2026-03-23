<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

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

        $this->runtimeInspector->clearPath($stagingPath);
        $this->runtimeInspector->clearPath($backupPath);

        try {
            $this->runtimeInspector->copyPath($payloadRoot, $stagingPath);
            $this->swapPaths($targetPath, $stagingPath, $backupPath);

            $renderedFiles = (new DownstreamScaffolder($targetPath, $this->repoRoot))->renderFrameworkManagedFiles($distributionPath);
            $managedFileChecksums = [];
            $changedPaths = [$distributionPath];
            $refreshedFiles = [];
            $skippedFiles = [];

            foreach ($renderedFiles as $relativePath => $contents) {
                $absolutePath = $this->repoRoot . '/' . $relativePath;
                $managedChecksum = $currentFramework->managedFiles()[$relativePath] ?? null;

                if ($managedChecksum !== null && is_file($absolutePath)) {
                    $currentChecksum = $this->contentsChecksum((string) file_get_contents($absolutePath));

                    if (! hash_equals($managedChecksum, $currentChecksum)) {
                        $managedFileChecksums[$relativePath] = $managedChecksum;
                        $skippedFiles[] = $relativePath;
                        continue;
                    }
                }

                $directory = dirname($absolutePath);

                if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
                    throw new RuntimeException(sprintf('Unable to create framework-managed directory: %s', $directory));
                }

                if (file_put_contents($absolutePath, $contents) === false) {
                    throw new RuntimeException(sprintf('Unable to write framework-managed file: %s', $absolutePath));
                }

                $managedFileChecksums[$relativePath] = $this->contentsChecksum($contents);
                $refreshedFiles[] = $relativePath;
                $changedPaths[] = $relativePath;
            }

            $framework = $currentFramework->withInstalledRelease(
                version: $payloadFramework->version,
                wordPressCoreVersion: $payloadFramework->baseline['wordpress_core'],
                managedComponents: $payloadFramework->baseline['managed_components'],
                managedFiles: $managedFileChecksums,
                distributionPath: $distributionPath
            );
            (new FrameworkWriter())->write($framework);
            $changedPaths[] = '.wp-core-base/framework.php';

            return [
                'changed_paths' => array_values(array_unique($changedPaths)),
                'refreshed_files' => $refreshedFiles,
                'skipped_files' => $skippedFiles,
                'framework_version' => $framework->version,
                'wordpress_core' => $framework->baseline['wordpress_core'],
                'managed_components' => $framework->baseline['managed_components'],
            ];
        } finally {
            $this->runtimeInspector->clearPath($stagingPath);
            $this->runtimeInspector->clearPath($backupPath);
        }
    }

    private function swapPaths(string $targetPath, string $stagingPath, string $backupPath): void
    {
        if ($targetPath === $this->repoRoot) {
            throw new RuntimeException('Framework self-update cannot replace the repository root in place.');
        }

        $parent = dirname($targetPath);

        if (! is_dir($parent) && ! mkdir($parent, 0775, true) && ! is_dir($parent)) {
            throw new RuntimeException(sprintf('Unable to create distribution parent directory: %s', $parent));
        }

        if (file_exists($targetPath) || is_link($targetPath)) {
            if (! @rename($targetPath, $backupPath)) {
                throw new RuntimeException(sprintf('Unable to move existing framework snapshot out of the way: %s', $targetPath));
            }
        }

        if (! @rename($stagingPath, $targetPath)) {
            if (file_exists($backupPath) || is_link($backupPath)) {
                @rename($backupPath, $targetPath);
            }

            throw new RuntimeException(sprintf('Unable to install new framework snapshot at %s.', $targetPath));
        }

        $this->runtimeInspector->clearPath($backupPath);
    }

    private function contentsChecksum(string $contents): string
    {
        return 'sha256:' . hash('sha256', $contents);
    }
}
