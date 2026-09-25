<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class GitCommandRunner implements GuardedGitRunnerInterface
{
    /** @var array<string, string|null> */
    private array $expectedRemoteRevisions = [];
    /** @var array<string, string|null> */
    private array $expectedLocalRevisions = [];
    /** @var array<string, string> */
    private array $ownedCheckoutRevisions = [];
    /** @var array<string, string> */
    private array $successfulPushRevisions = [];

    public function expectRemoteRevision(string $branch, ?string $revision): void
    {
        $this->assertBranchName($branch);
        $this->expectedRemoteRevisions[$branch] = $revision;
        unset($this->successfulPushRevisions[$branch], $this->ownedCheckoutRevisions[$branch]);
    }

    public function ownedCheckoutRevision(string $branch): ?string
    {
        return $this->ownedCheckoutRevisions[$branch] ?? null;
    }

    public function expectLocalRevision(string $branch, ?string $revision): void
    {
        $this->assertBranchName($branch);
        $this->expectedLocalRevisions[$branch] = $revision;
    }

    public function successfulPushRevision(string $branch): ?string
    {
        return $this->successfulPushRevisions[$branch] ?? null;
    }

    public function __construct(
        private readonly string $repoRoot,
        private readonly bool $dryRun = false,
    ) {
    }

    public function checkoutBranch(string $baseBranch, string $branch, bool $resetToBase = false): void
    {
        $this->assertBranchName($baseBranch);
        $this->assertBranchName($branch);
        $this->assertBranchAvailable($branch, true);
        if (! array_key_exists($branch, $this->expectedRemoteRevisions)) {
            $this->expectRemoteRevision($branch, $this->remoteBranchRevision($branch));
        }
        if (! array_key_exists($branch, $this->expectedLocalRevisions)) {
            $this->expectLocalRevision($branch, $this->localBranchRevision($branch));
        }
        // Keep the observed remote object available for conditional recovery,
        // including refreshes that rebuild from the base rather than that ref.
        if ($this->expectedRemoteRevisions[$branch] !== null) {
            $this->run(['git', 'fetch', '--no-tags', 'origin', $this->expectedRemoteRevisions[$branch]]);
        }
        $this->run(['git', 'fetch', 'origin', $baseBranch]);
        if (! $resetToBase && $this->expectedRemoteRevisions[$branch] !== null) {
            $this->run(['git', 'fetch', 'origin', $branch]);
            $fetchedRevision = trim($this->run(['git', 'rev-parse', 'origin/' . $branch]));
            if ($fetchedRevision !== $this->expectedRemoteRevisions[$branch]) {
                throw new RuntimeException(sprintf('Remote branch %s changed before checkout; retry with fresh state.', $branch));
            }
            $targetRevision = $fetchedRevision;
        } else {
            $targetRevision = trim($this->run(['git', 'rev-parse', 'origin/' . $baseBranch]));
        }
        $expectedLocal = $this->expectedLocalRevisions[$branch];
        if ($this->localBranchRevision($branch) !== $expectedLocal) {
            throw new RuntimeException(sprintf('Local branch %s changed before checkout; refusing to replace it.', $branch));
        }
        // Detach first so update-ref cannot silently rewrite the checked-out
        // branch. The explicit old value also catches movement during fetch.
        $originalBranch = $this->currentBranch();
        $originalRevision = $this->currentRevision();
        $refUpdated = false;
        try {
            $this->run(['git', 'checkout', '--detach', $targetRevision]);
            $this->compareAndSwapLocalBranch($branch, $targetRevision, $expectedLocal);
            $refUpdated = true;
            $this->ownedCheckoutRevisions[$branch] = $targetRevision;
            $this->expectedLocalRevisions[$branch] = $targetRevision;
            $this->run(['git', 'checkout', $branch]);
        } catch (\Throwable $failure) {
            try {
                // Checkout hooks can fail after Git already changed HEAD. Only
                // recover our expected checkout, never an intervening edit.
                $currentBranch = $this->currentBranch();
                if (($currentBranch !== null && $currentBranch !== $branch)
                    || $this->currentRevision() !== $targetRevision) {
                    throw new RuntimeException('Checkout changed outside this operation; recovery state preserved.');
                }
                $this->assertCleanWorktree();
                if ($currentBranch !== null) {
                    $this->recoverCheckout(null, $targetRevision);
                }
                if ($refUpdated) {
                    $this->compareAndSwapLocalBranch($branch, $expectedLocal, $targetRevision);
                    $this->expectedLocalRevisions[$branch] = $expectedLocal;
                    unset($this->ownedCheckoutRevisions[$branch]);
                }
                if ($originalBranch !== null) {
                    if ($this->localBranchRevision($originalBranch) !== $originalRevision) {
                        throw new RuntimeException('Original branch changed; detached recovery checkout preserved.');
                    }
                    $this->recoverCheckout($originalBranch, $originalRevision);
                } else {
                    $this->recoverCheckout(null, $originalRevision);
                }
            } catch (\Throwable $recoveryFailure) {
                throw new RuntimeException($failure->getMessage() . "\nCheckout recovery incomplete: " . $recoveryFailure->getMessage(), previous: $failure);
            }
            throw $failure;
        }
    }

    public function commitAndPush(string $branch, string $message, array $paths, bool $force = false): bool
    {
        $this->assertBranchName($branch);
        if ($this->currentBranch() !== $branch) {
            throw new RuntimeException(sprintf('Refusing to commit: expected checked-out branch %s.', $branch));
        }
        if (! array_key_exists($branch, $this->expectedRemoteRevisions)) {
            $this->expectRemoteRevision($branch, $this->remoteBranchRevision($branch));
        }
        $ownedCheckout = $this->ownedCheckoutRevisions[$branch] ?? null;
        if ($ownedCheckout !== null && $this->currentRevision() !== $ownedCheckout) {
            throw new RuntimeException('Local branch changed outside this operation; refusing to commit.');
        }
        $this->run(array_merge(['git', 'add', '--all', '--'], $paths));
        if (! $this->hasStagedChanges()) {
            return false;
        }
        $staged = $this->runWithStatus(['git', 'diff', '--cached', '--name-only', '-z'], false, true)[1];
        foreach (array_filter(explode("\0", $staged)) as $stagedPath) {
            $owned = false;
            foreach ($paths as $path) {
                if ($stagedPath === $path || str_starts_with($stagedPath, rtrim($path, '/') . '/')) {
                    $owned = true;
                    break;
                }
            }
            if (! $owned) {
                throw new RuntimeException(sprintf('Staged path %s is outside this operation; refusing to commit.', $stagedPath));
            }
        }
        $this->run(['git', 'commit', '-m', $message]);
        $revision = $this->currentRevision();
        $this->ownedCheckoutRevisions[$branch] = $revision;
        $expected = $this->expectedRemoteRevisions[$branch];
        try {
            if (! $force && $expected !== null) {
                $this->run(['git', 'merge-base', '--is-ancestor', $expected, $revision]);
            }
            $this->compareAndSwapRemoteBranch($branch, $revision, $expected);
        } catch (RuntimeException $exception) {
            throw new RuntimeException(sprintf(
                'Push did not complete successfully. Local recovery commit %s was preserved; remote state was not rolled back. %s',
                $revision,
                $exception->getMessage()
            ), previous: $exception);
        }
        $this->successfulPushRevisions[$branch] = $revision;
        $this->expectedRemoteRevisions[$branch] = $revision;
        return true;
    }

    public function remoteRevision(string $branch): string
    {
        $this->run(['git', 'fetch', 'origin', $branch]);

        return trim($this->run(['git', 'rev-parse', 'origin/' . $branch]));
    }

    public function currentBranch(): ?string
    {
        [$status, $output] = $this->runWithStatus(['git', 'symbolic-ref', '--quiet', '--short', 'HEAD'], true);

        if ($status !== 0) {
            return null;
        }

        $branch = trim($output);
        return $branch === '' ? null : $branch;
    }

    public function currentRevision(): string
    {
        return trim($this->run(['git', 'rev-parse', 'HEAD']));
    }

    public function localBranchRevision(string $branch): ?string
    {
        [$status, $output] = $this->runWithStatus(['git', 'rev-parse', '--verify', '--quiet', 'refs/heads/' . $branch], true);

        if ($status !== 0) {
            return null;
        }

        $revision = trim($output);
        return $revision === '' ? null : $revision;
    }

    public function remoteBranchRevision(string $branch): ?string
    {
        $this->assertBranchName($branch);
        [$status, $output] = $this->runWithStatus(['git', 'ls-remote', '--exit-code', '--heads', 'origin', 'refs/heads/' . $branch], true);
        if ($status === 2) {
            return null;
        }
        if ($status !== 0) {
            throw new RuntimeException(sprintf('Unable to read remote branch %s; absence cannot be established. %s', $branch, $output));
        }
        if (! preg_match('/^([a-f0-9]{40,64})\s+refs\/heads\/' . preg_quote($branch, '/') . '$/m', $output, $matches)) {
            throw new RuntimeException(sprintf('Remote branch %s returned an invalid revision.', $branch));
        }
        return $matches[1];
    }

    public function checkoutRef(string $ref): void
    {
        $this->run(['git', 'checkout', $ref]);
    }

    public function checkoutDetached(string $revision): void
    {
        $this->run(['git', 'checkout', '--detach', $revision]);
    }

    public function hardReset(string $revision): void
    {
        $this->run(['git', 'reset', '--hard', $revision]);
    }

    public function cleanUntracked(): void
    {
        $this->run(['git', 'clean', '-fd']);
    }

    public function forceBranchToRevision(string $branch, string $revision): void
    {
        $this->run(['git', 'branch', '-f', $branch, $revision]);
    }

    public function deleteLocalBranch(string $branch): void
    {
        if ($this->localBranchRevision($branch) === null) {
            return;
        }

        $this->run(['git', 'branch', '-D', $branch]);
    }

    public function forcePushRevision(string $branch, string $revision): void
    {
        throw new RuntimeException('Unconditional remote restoration is disabled; use compareAndSwapRemoteBranch with an explicit expected revision.');
    }

    public function deleteRemoteBranch(string $branch, ?string $expectedRevision = null): void
    {
        if ($expectedRevision === null || $expectedRevision === '') {
            throw new RuntimeException('Remote branch deletion requires the exact expected revision.');
        }
        $this->compareAndSwapRemoteBranch($branch, null, $expectedRevision);
    }

    public function compareAndSwapRemoteBranch(string $branch, ?string $replacement, ?string $expected): void
    {
        $this->assertBranchName($branch);
        foreach ([$replacement, $expected] as $revision) {
            if ($revision !== null && ! preg_match('/^[a-f0-9]{40,64}$/', $revision)) {
                throw new RuntimeException('Remote branch mutation requires a full commit revision.');
            }
        }
        $ref = 'refs/heads/' . $branch;
        $this->run(['git', 'push', '--force-with-lease=' . $ref . ':' . ($expected ?? ''), 'origin', ($replacement ?? '') . ':' . $ref]);
    }

    public function compareAndSwapLocalBranch(string $branch, ?string $replacement, ?string $expected): void
    {
        $this->assertBranchName($branch);
        foreach ([$replacement, $expected] as $revision) {
            if ($revision !== null && preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/D', $revision) !== 1) {
                throw new RuntimeException('Local branch mutation requires a full commit revision.');
            }
        }
        if ($replacement === null && $expected === null) {
            throw new RuntimeException('Local branch deletion requires its exact expected revision.');
        }
        $this->assertBranchAvailable($branch);
        $command = $replacement === null
            ? ['git', 'update-ref', '-d', 'refs/heads/' . $branch, $expected]
            : ['git', 'update-ref', 'refs/heads/' . $branch, $replacement, $expected ?? str_repeat('0', strlen($replacement))];
        $this->run($command);
    }

    public function restoreMutationPaths(string $revision, array $paths): void
    {
        foreach (array_values(array_unique($paths)) as $path) {
            if ($path === '' || $path === '.' || str_starts_with($path, '/') || preg_match('#(^|/)\.\.?(/|$)#', $path)) {
                throw new RuntimeException(sprintf('Unsafe rollback path: %s', $path));
            }
            // Only restore files tracked in the baseline. Unknown untracked files
            // are retained for recovery, never removed with repository-wide clean.
            $tracked = $this->runWithStatus(['git', 'ls-tree', '-r', '--name-only', '-z', $revision, '--', $path], false, true)[1];
            $files = array_values(array_filter(explode("\0", $tracked), static fn (string $file): bool => $file !== ''));
            foreach (array_chunk($files, 100) as $batch) {
                $this->run(array_merge(['git', 'restore', '--source=' . $revision, '--staged', '--worktree', '--'], $batch));
            }
        }
    }

    private function assertBranchName(string $branch): void
    {
        [$status] = $this->runWithStatus(['git', 'check-ref-format', 'refs/heads/' . $branch], true);
        if ($status !== 0 || str_starts_with($branch, '-')) {
            throw new RuntimeException(sprintf('Invalid branch name: %s', $branch));
        }
    }

    private function assertBranchAvailable(string $branch, bool $allowCurrent = false): void
    {
        $output = $this->runWithStatus(['git', 'worktree', 'list', '--porcelain', '-z'], false, true)[1];
        $checkouts = count(array_filter(explode("\0", $output), static fn (string $field): bool => $field === 'branch refs/heads/' . $branch));
        $allowed = $allowCurrent && $this->currentBranch() === $branch ? 1 : 0;
        if ($checkouts > $allowed) {
            throw new RuntimeException(sprintf('Refusing to rewrite branch %s while it is checked out in a worktree.', $branch));
        }
    }

    private function recoverCheckout(?string $branch, string $revision): void
    {
        // A post-checkout hook may fail even though Git performed the checkout.
        // Verify actual state so that hook status alone cannot skip independent
        // ref restoration, while unexpected edits still stop recovery.
        $command = $branch === null
            ? ['git', 'checkout', '--detach', $revision]
            : ['git', 'checkout', $branch];
        [$status, $output] = $this->runWithStatus($command, true);
        if ($this->currentBranch() !== $branch || $this->currentRevision() !== $revision) {
            throw new RuntimeException(sprintf('Recovery checkout did not reach the expected state (status %d). %s', $status, $output));
        }
        $this->assertCleanWorktree();
    }

    public function assertCleanWorktree(): void
    {
        [$status, $output] = $this->runWithStatus(['git', 'status', '--porcelain'], true);

        if ($status !== 0) {
            throw new RuntimeException(sprintf('Unable to inspect Git worktree state.%s', $output === '' ? '' : "\n" . $output));
        }

        if (trim($output) !== '') {
            throw new RuntimeException('Git worktree must be clean before running automation sync commands.');
        }
    }

    private function hasStagedChanges(): bool
    {
        [$status] = $this->runWithStatus(['git', 'diff', '--cached', '--quiet'], true);
        return $status !== 0;
    }

    /**
     * @param list<string> $command
     */
    private function run(array $command): string
    {
        [$status, $output] = $this->runWithStatus($command, false);

        if ($status !== 0) {
            throw new RuntimeException(sprintf("Command failed: %s\n%s", $this->formatCommand($command), $output));
        }

        return $output;
    }

    /**
     * @param list<string> $command
     * @return array{int, string}
     */
    private function runWithStatus(array $command, bool $allowFailure, bool $preserveOutput = false): array
    {
        $displayCommand = $this->formatCommand($command);

        if ($this->dryRun) {
            fwrite(STDOUT, sprintf("[dry-run] %s\n", $displayCommand));
            return [0, ''];
        }

        $descriptorSpec = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes, $this->repoRoot);

        if (! is_resource($process)) {
            throw new RuntimeException(sprintf('Failed to start command: %s', $displayCommand));
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $status = proc_close($process);
        $output = $preserveOutput && $status === 0 ? (string) $stdout : trim((string) $stdout . "\n" . (string) $stderr);

        if (! $allowFailure && $status !== 0) {
            throw new RuntimeException(sprintf("Command failed: %s\n%s", $displayCommand, $output));
        }

        return [$status, $output];
    }

    /**
     * @param list<string> $command
     */
    private function formatCommand(array $command): string
    {
        return implode(' ', array_map(static fn (string $part): string => escapeshellarg($part), $command));
    }
}
