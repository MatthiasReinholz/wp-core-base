<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;
use Throwable;

final class PathSwapWithRollback
{
    /** @var array<string, array{backup:string,had_target:bool}> */
    private array $pending = [];

    public function __construct(private readonly RuntimeInspector $runtimeInspector) {}

    public function swap(string $targetPath, string $stagingPath, string $backupPath, string $repoRoot): void
    {
        $root = rtrim($repoRoot, '/');
        if (! str_starts_with($targetPath, $root . '/') || $targetPath === $root) {
            throw new RuntimeException('Path swap target must be below the repository root.');
        }
        $relative = ConfigPathRules::normalizedRelativePath(substr($targetPath, strlen($root) + 1), 'path swap target');
        if ($relative === '.') {
            throw new RuntimeException('Path swap cannot replace the repository root in place.');
        }
        ConfigPathRules::assertNoSymlinkDescendants($root, $relative);
        if ((! is_dir($stagingPath) && ! is_file($stagingPath)) || is_link($stagingPath)) {
            throw new RuntimeException(sprintf('Path swap staging input is missing or unsafe: %s', $stagingPath));
        }
        $swapPaths = [$targetPath, $stagingPath, $backupPath];
        foreach ($swapPaths as $leftIndex => $left) {
            foreach ($swapPaths as $rightIndex => $right) {
                if ($leftIndex !== $rightIndex && ($left === $right || str_starts_with($left, $right . '/'))) {
                    throw new RuntimeException('Path swap inputs must be distinct and non-overlapping.');
                }
            }
        }
        if (file_exists($backupPath) || is_link($backupPath)) {
            throw new RuntimeException(sprintf('Refusing to overwrite existing recovery backup: %s', $backupPath));
        }
        foreach ([dirname($targetPath), dirname($backupPath)] as $parent) {
            if (! is_dir($parent) && ! mkdir($parent, 0775, true) && ! is_dir($parent)) {
                throw new RuntimeException(sprintf('Unable to create path swap parent: %s', $parent));
            }
        }
        $hadTarget = file_exists($targetPath);
        if ($hadTarget && ! rename($targetPath, $backupPath)) {
            throw new RuntimeException(sprintf('Unable to preserve existing target: %s', $targetPath));
        }
        $this->pending[$targetPath] = ['backup' => $backupPath, 'had_target' => $hadTarget];
        if (! @rename($stagingPath, $targetPath)) {
            try {
                $this->rollback($targetPath, $backupPath);
            } catch (Throwable $restoreFailure) {
                throw new RuntimeException(sprintf('Unable to publish staged path; recovery failed. Preserve backup %s: %s', $backupPath, $restoreFailure->getMessage()), 0, $restoreFailure);
            }
            throw new RuntimeException(sprintf('Unable to move staged path into place at %s; previous target restored.', $targetPath));
        }
    }

    public function rollback(string $targetPath, string $backupPath): void
    {
        $state = $this->pending[$targetPath] ?? null;
        if ($state === null || $state['backup'] !== $backupPath) {
            throw new RuntimeException('Refusing rollback without matching completed swap state.');
        }
        if ($state['had_target'] && (! file_exists($backupPath) || is_link($backupPath))) {
            throw new RuntimeException(sprintf('Recovery backup is unavailable; preserving current target: %s', $backupPath));
        }
        if (file_exists($targetPath) || is_link($targetPath)) {
            $this->runtimeInspector->clearPath($targetPath);
        }
        if ($state['had_target'] && ! @rename($backupPath, $targetPath)) {
            throw new RuntimeException(sprintf('Unable to restore %s; recovery backup retained at %s.', $targetPath, $backupPath));
        }
        unset($this->pending[$targetPath]);
    }

    public function finalize(string $backupPath): void
    {
        $this->runtimeInspector->clearPath($backupPath);
        foreach ($this->pending as $target => $state) {
            if ($state['backup'] === $backupPath) {
                unset($this->pending[$target]);
            }
        }
    }
}
