<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use Throwable;

final class BranchRollbackGuard
{
    private ?string $originalBranch = null;
    private string $originalRevision = '';

    /** @var array<string, array{local_revision:?string, remote_revision:?string}> */
    private array $trackedBranches = [];

    /** @var list<string> */
    private array $cleanupPaths = [];

    private bool $completed = false;

    public function __construct(
        private readonly string $repoRoot,
        private readonly GitRunnerInterface $gitRunner,
    ) {
    }

    public function begin(): void
    {
        $this->gitRunner->assertCleanWorktree();
        $this->originalBranch = $this->gitRunner->currentBranch();
        $this->originalRevision = $this->gitRunner->currentRevision();
    }

    public function trackBranch(string $branch): void
    {
        if ($branch === '' || isset($this->trackedBranches[$branch])) {
            return;
        }

        $this->trackedBranches[$branch] = [
            'local_revision' => $this->gitRunner->localBranchRevision($branch),
            'remote_revision' => $this->gitRunner->remoteBranchRevision($branch),
        ];
    }

    public function trackCleanupPath(string $path): void
    {
        if ($path === '') {
            return;
        }

        $this->cleanupPaths[] = $path;
    }

    public function complete(): void
    {
        if ($this->completed) {
            return;
        }

        $this->restoreOriginalCheckout();
        $this->completed = true;
    }

    public function rollback(Throwable $throwable): never
    {
        try {
            $this->restoreCurrentBranchWorktree();
            $this->restoreOriginalCheckout();
            $this->restoreTrackedBranches();
            $this->clearCleanupPaths();
        } catch (Throwable $rollbackFailure) {
            throw new \RuntimeException(sprintf(
                "%s\nRollback failure: %s",
                OutputRedactor::redact($throwable->getMessage()),
                OutputRedactor::redact($rollbackFailure->getMessage())
            ), previous: $throwable);
        }

        throw $throwable;
    }

    private function restoreCurrentBranchWorktree(): void
    {
        $currentBranch = $this->gitRunner->currentBranch();

        if ($currentBranch !== null && isset($this->trackedBranches[$currentBranch])) {
            $baseline = $this->trackedBranches[$currentBranch]['local_revision']
                ?? $this->trackedBranches[$currentBranch]['remote_revision']
                ?? 'HEAD';
            $this->gitRunner->hardReset($baseline);
            $this->gitRunner->cleanUntracked();
            return;
        }

        $this->gitRunner->hardReset('HEAD');
        $this->gitRunner->cleanUntracked();
    }

    private function restoreOriginalCheckout(): void
    {
        if ($this->originalBranch !== null) {
            if ($this->gitRunner->currentBranch() !== $this->originalBranch) {
                $this->gitRunner->checkoutRef($this->originalBranch);
            }

            return;
        }

        $this->gitRunner->checkoutDetached($this->originalRevision);
    }

    private function restoreTrackedBranches(): void
    {
        foreach ($this->trackedBranches as $branch => $state) {
            if ($branch === $this->originalBranch) {
                $baseline = $state['local_revision'] ?? $state['remote_revision'] ?? $this->originalRevision;
                $this->gitRunner->hardReset($baseline);
                $this->gitRunner->cleanUntracked();

                if ($state['remote_revision'] !== null) {
                    $this->gitRunner->forcePushRevision($branch, $state['remote_revision']);
                }

                continue;
            }

            if ($state['local_revision'] !== null) {
                $this->gitRunner->forceBranchToRevision($branch, $state['local_revision']);
            } elseif ($state['remote_revision'] !== null) {
                $this->gitRunner->forceBranchToRevision($branch, $state['remote_revision']);
            } else {
                $this->gitRunner->deleteLocalBranch($branch);
            }

            if ($state['remote_revision'] !== null) {
                $this->gitRunner->forcePushRevision($branch, $state['remote_revision']);
            } else {
                $this->gitRunner->deleteRemoteBranch($branch);
            }
        }
    }

    private function clearCleanupPaths(): void
    {
        foreach (array_values(array_unique($this->cleanupPaths)) as $path) {
            $this->clearPath($path);
        }
    }

    private function clearPath(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }

        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $this->clearPath($path . '/' . $entry);
        }

        @rmdir($path);
    }
}
