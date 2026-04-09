<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class PathSwapWithRollback
{
    public function __construct(
        private readonly RuntimeInspector $runtimeInspector,
    ) {
    }

    public function swap(string $targetPath, string $stagingPath, string $backupPath, string $repoRoot): void
    {
        if ($targetPath === $repoRoot) {
            throw new RuntimeException('Path swap cannot replace the repository root in place.');
        }

        $parent = dirname($targetPath);

        if (! is_dir($parent) && ! mkdir($parent, 0775, true) && ! is_dir($parent)) {
            throw new RuntimeException(sprintf('Unable to create target parent directory: %s', $parent));
        }

        if (file_exists($backupPath) || is_link($backupPath)) {
            $this->runtimeInspector->clearPath($backupPath);
        }

        if (file_exists($targetPath) || is_link($targetPath)) {
            if (! rename($targetPath, $backupPath)) {
                throw new RuntimeException(sprintf('Unable to move existing path out of the way: %s', $targetPath));
            }
        }

        if (! rename($stagingPath, $targetPath)) {
            $this->rollback($targetPath, $backupPath);
            throw new RuntimeException(sprintf('Unable to move staged path into place at %s.', $targetPath));
        }
    }

    public function rollback(string $targetPath, string $backupPath): void
    {
        if (file_exists($targetPath) || is_link($targetPath)) {
            $this->runtimeInspector->clearPath($targetPath);
        }

        if (file_exists($backupPath) || is_link($backupPath)) {
            if (! rename($backupPath, $targetPath)) {
                throw new RuntimeException(sprintf('Unable to restore backup path for %s.', $targetPath));
            }
        }
    }

    public function finalize(string $backupPath): void
    {
        $this->runtimeInspector->clearPath($backupPath);
    }
}
