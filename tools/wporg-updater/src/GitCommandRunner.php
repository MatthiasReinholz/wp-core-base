<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class GitCommandRunner implements GitRunnerInterface
{
    public function __construct(
        private readonly string $repoRoot,
        private readonly bool $dryRun = false,
    ) {
    }

    public function checkoutBranch(string $baseBranch, string $branch, bool $resetToBase = false): void
    {
        $this->run(['git', 'fetch', 'origin', $baseBranch]);

        if (! $resetToBase && $this->remoteBranchExists($branch)) {
            $this->run(['git', 'fetch', 'origin', $branch]);
            $this->run(['git', 'checkout', '-B', $branch, 'origin/' . $branch]);
            return;
        }

        $this->run(['git', 'checkout', '-B', $branch, 'origin/' . $baseBranch]);
    }

    public function commitAndPush(string $branch, string $message, array $paths, bool $force = false): bool
    {
        $this->run(array_merge(['git', 'add', '--all', '--'], $paths));

        if (! $this->hasStagedChanges()) {
            return false;
        }

        $baselineRevision = trim($this->run(['git', 'rev-parse', 'HEAD']));
        $this->run(['git', 'commit', '-m', $message]);

        try {
            $pushCommand = ['git', 'push'];
            if ($force) {
                $pushCommand[] = '--force';
            }
            $pushCommand[] = '-u';
            $pushCommand[] = 'origin';
            $pushCommand[] = $branch;

            $this->run($pushCommand);
        } catch (RuntimeException $exception) {
            try {
                $this->run(['git', 'reset', '--hard', $baselineRevision]);
            } catch (RuntimeException $rollbackException) {
                throw new RuntimeException(sprintf(
                    "Push failed after creating a local commit and rollback failed.\nPush error: %s\nRollback error: %s",
                    $exception->getMessage(),
                    $rollbackException->getMessage()
                ), previous: $exception);
            }

            throw new RuntimeException(sprintf(
                "Push failed after creating a local commit. The branch was reset to %s.\n%s",
                $baselineRevision,
                $exception->getMessage()
            ), previous: $exception);
        }

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
        if (! $this->remoteBranchExists($branch)) {
            return null;
        }

        return $this->remoteRevision($branch);
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
        $this->run(['git', 'push', '--force', 'origin', $revision . ':' . $branch]);
    }

    public function deleteRemoteBranch(string $branch): void
    {
        if (! $this->remoteBranchExists($branch)) {
            return;
        }

        $this->run(['git', 'push', 'origin', '--delete', $branch]);
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

    private function remoteBranchExists(string $branch): bool
    {
        [$status] = $this->runWithStatus(['git', 'ls-remote', '--exit-code', '--heads', 'origin', $branch], true);
        return $status === 0;
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
    private function runWithStatus(array $command, bool $allowFailure): array
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
        $output = trim((string) $stdout . "\n" . (string) $stderr);

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
