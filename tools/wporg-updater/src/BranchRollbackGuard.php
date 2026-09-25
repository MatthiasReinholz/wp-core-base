<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;
use Throwable;

final class BranchRollbackGuard
{
    private ?string $originalBranch = null;
    private string $originalRevision = '';
    /** @var array<string, array{local_revision:?string, remote_revision:?string, checkout_revision:?string, pushed_revision:?string}> */
    private array $trackedBranches = [];
    /** @var list<string> */
    private array $mutationPaths = [];
    /** @var list<string> */
    private array $cleanupPaths = [];
    private bool $completed = false;
    private bool $preserveRecoveryCommit = false;

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
        $remote = $this->gitRunner->remoteBranchRevision($branch);
        $this->trackedBranches[$branch] = [
            'local_revision' => $this->gitRunner->localBranchRevision($branch),
            'remote_revision' => $remote,
            'checkout_revision' => null,
            'pushed_revision' => null,
        ];
        if ($this->gitRunner instanceof GuardedGitRunnerInterface) {
            $this->gitRunner->expectRemoteRevision($branch, $remote);
            $this->gitRunner->expectLocalRevision($branch, $this->trackedBranches[$branch]['local_revision']);
        }
    }

    public function recordCheckout(string $branch): void
    {
        if (! isset($this->trackedBranches[$branch]) || $this->gitRunner->currentBranch() !== $branch) {
            throw new RuntimeException('Cannot record an untracked or unexpected checkout.');
        }
        $this->trackedBranches[$branch]['checkout_revision'] = $this->gitRunner instanceof GuardedGitRunnerInterface
            ? $this->gitRunner->ownedCheckoutRevision($branch)
            : $this->gitRunner->currentRevision();
    }

    /** @param list<string> $paths */
    public function trackMutationPaths(array $paths): void
    {
        foreach ($paths as $path) {
            if ($path === '' || $path === '.' || str_starts_with($path, '/') || preg_match('#(^|/)\.\.?(/|$)#', $path)) {
                throw new RuntimeException(sprintf('Unsafe mutation recovery path: %s', $path));
            }
            $this->mutationPaths[] = $path;
        }
    }

    public function trackSourceBaselinePaths(): void
    {
        if (! is_file($this->repoRoot . '/.wp-core-base/framework.php')) {
            return;
        }
        $framework = FrameworkConfig::load($this->repoRoot);
        if ($framework->distributionPath() === '.') {
            $this->trackMutationPaths([
                '.wp-core-base/framework.php',
                'README.md',
                'docs/releases/' . $framework->normalizedVersion() . '.md',
            ]);
        }
    }

    /** @param list<string> $paths */
    public function commitAndPush(string $branch, string $message, array $paths, bool $force = false): bool
    {
        $this->trackMutationPaths($paths);
        if ($force && ! $this->gitRunner instanceof GuardedGitRunnerInterface) {
            throw new RuntimeException('Forced branch refresh requires a Git runner with conditional push support.');
        }
        try {
            $changed = $this->gitRunner->commitAndPush($branch, $message, $paths, $force);
        } catch (Throwable $throwable) {
            // A transport error may occur after the server accepted the push.
            // Keep the local commit and never infer remote ownership afterward.
            $this->preserveRecoveryCommit = true;
            throw $throwable;
        }
        if ($changed) {
            $this->trackedBranches[$branch]['pushed_revision'] = $this->gitRunner instanceof GuardedGitRunnerInterface
                ? $this->gitRunner->successfulPushRevision($branch)
                : $this->gitRunner->currentRevision();
        }
        return $changed;
    }

    public function trackCleanupPath(string $path): void
    {
        $this->assertCleanupPath($path);
        $this->cleanupPaths[] = $path;
    }

    public function complete(): void
    {
        if (! $this->completed) {
            $this->restoreOriginalCheckout();
            $this->completed = true;
        }
    }

    public function rollback(Throwable $throwable): never
    {
        $failures = [];
        $attempt = static function (string $operation, callable $restore) use (&$failures): void {
            try {
                $restore();
            } catch (Throwable $failure) {
                $failures[] = $operation . ': ' . OutputRedactor::redact($failure->getMessage());
            }
        };
        $attempt('worktree recovery', fn () => $this->restoreCurrentBranchWorktree());
        $attempt('original checkout recovery', fn () => $this->restoreOriginalCheckout());
        foreach ($this->trackedBranches as $branch => $state) {
            $beforeRemoteRecovery = count($failures);
            $attempt('remote branch ' . $branch, fn () => $this->restoreRemoteBranch($branch, $state));
            if (count($failures) === $beforeRemoteRecovery) {
                $attempt('local branch ' . $branch, fn () => $this->restoreLocalBranch($branch, $state));
            }
            // If the remote lease was lost, retain our local commit/ref as a
            // recovery handle rather than deleting the last owned reference.
        }
        // Retain operation residue when any restoration failed: it may be the
        // only remaining recovery copy. Explicit cleanup never uses git clean.
        if ($failures === [] && ! $this->preserveRecoveryCommit) {
            foreach (array_unique($this->cleanupPaths) as $path) {
                $attempt('cleanup ' . $path, function () use ($path): void {
                    $this->assertCleanupPath($path);
                    $this->clearPath($path);
                });
            }
        }
        if ($failures !== []) {
            throw new RuntimeException(sprintf(
                "%s\nRollback incomplete; recovery files were preserved. %s",
                OutputRedactor::redact($throwable->getMessage()),
                implode('; ', $failures)
            ), previous: $throwable);
        }
        throw $throwable;
    }

    private function restoreCurrentBranchWorktree(): void
    {
        if ($this->preserveRecoveryCommit) {
            return;
        }
        $branch = $this->gitRunner->currentBranch();
        if ($branch === null || ! isset($this->trackedBranches[$branch])) {
            if ($branch !== $this->originalBranch || $this->gitRunner->currentRevision() !== $this->originalRevision) {
                throw new RuntimeException('Checkout changed outside this operation; refusing to reset it.');
            }
            return;
        }
        $state = $this->trackedBranches[$branch];
        $owned = $state['pushed_revision'] ?? $state['checkout_revision'];
        if ($owned === null) {
            return; // Observation alone does not authorize a reset.
        }
        if ($this->gitRunner->currentRevision() !== $owned) {
            throw new RuntimeException('Local branch changed outside this operation; refusing to reset it.');
        }
        if ($this->gitRunner instanceof GuardedGitRunnerInterface) {
            $this->gitRunner->restoreMutationPaths($owned, array_values(array_unique($this->mutationPaths)));
        } else {
            // Older custom runners cannot prove scoped recovery. Preserve data.
            $this->gitRunner->assertCleanWorktree();
        }
    }

    private function restoreOriginalCheckout(): void
    {
        $current = $this->gitRunner->currentBranch();
        if ($current !== $this->originalBranch && ($current === null || ! isset($this->trackedBranches[$current]))) {
            if ($current === null && $this->gitRunner->currentRevision() === $this->originalRevision) {
                return;
            }
            throw new RuntimeException('Checkout ownership changed; original checkout was not restored.');
        }
        if ($this->originalBranch !== null) {
            if ($current !== $this->originalBranch) {
                $this->gitRunner->checkoutRef($this->originalBranch);
            }
        } else {
            $this->gitRunner->checkoutDetached($this->originalRevision);
        }
    }

    /** @param array{local_revision:?string, remote_revision:?string, checkout_revision:?string, pushed_revision:?string} $state */
    private function restoreLocalBranch(string $branch, array $state): void
    {
        if ($this->preserveRecoveryCommit || $state['checkout_revision'] === null) {
            return;
        }
        $expected = $state['pushed_revision'] ?? $state['checkout_revision'];
        if ($expected === $state['local_revision']) {
            return;
        }
        if (! $this->gitRunner instanceof GuardedGitRunnerInterface) {
            throw new RuntimeException('Custom Git runner lacks conditional local recovery; branch preserved.');
        }
        $restoreCheckout = $this->gitRunner->currentBranch() === $branch;
        if ($restoreCheckout) {
            if ($this->gitRunner->currentRevision() !== $expected) {
                throw new RuntimeException('Recovery branch changed; branch preserved.');
            }
            $this->gitRunner->checkoutDetached($expected);
        }
        try {
            $this->gitRunner->compareAndSwapLocalBranch($branch, $state['local_revision'], $expected);
        } finally {
            if ($restoreCheckout && $this->gitRunner->localBranchRevision($branch) !== null) {
                $this->gitRunner->checkoutRef($branch);
            }
        }
    }

    /** @param array{local_revision:?string, remote_revision:?string, checkout_revision:?string, pushed_revision:?string} $state */
    private function restoreRemoteBranch(string $branch, array $state): void
    {
        if ($state['pushed_revision'] === null) {
            return;
        }
        if (! $this->gitRunner instanceof GuardedGitRunnerInterface) {
            throw new RuntimeException('Custom Git runner lacks conditional remote recovery; pushed branch preserved.');
        }
        $this->gitRunner->compareAndSwapRemoteBranch($branch, $state['remote_revision'], $state['pushed_revision']);
    }

    private function assertCleanupPath(string $path): void
    {
        $root = realpath($this->repoRoot);
        $parent = realpath(dirname($path));
        if ($root === false || $parent === false || ! str_starts_with($parent . '/', $root . '/')) {
            throw new RuntimeException('Cleanup paths must belong to this repository.');
        }
    }

    private function clearPath(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            if (! unlink($path)) {
                throw new RuntimeException(sprintf('Unable to remove cleanup path %s', $path));
            }
            return;
        }
        if (! is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->clearPath($path . '/' . $entry);
            }
        }
        if (! rmdir($path)) {
            throw new RuntimeException(sprintf('Unable to remove cleanup directory %s', $path));
        }
    }
}
