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
        $this->run(sprintf('git fetch origin %s', escapeshellarg($baseBranch)));

        if (! $resetToBase && $this->remoteBranchExists($branch)) {
            $this->run(sprintf('git fetch origin %s', escapeshellarg($branch)));
            $this->run(sprintf('git checkout -B %1$s origin/%1$s', escapeshellarg($branch)));
            return;
        }

        $this->run(sprintf('git checkout -B %s origin/%s', escapeshellarg($branch), escapeshellarg($baseBranch)));
    }

    public function commitAndPush(string $branch, string $message, array $paths, bool $force = false): bool
    {
        $pathArgs = implode(' ', array_map(static fn (string $path): string => escapeshellarg($path), $paths));
        $this->run(sprintf('git add --all -- %s', $pathArgs));

        if (! $this->hasStagedChanges()) {
            return false;
        }

        $this->run(sprintf('git commit -m %s', escapeshellarg($message)));
        $this->run(sprintf('git push %s-u origin %s', $force ? '--force ' : '', escapeshellarg($branch)));

        return true;
    }

    public function remoteRevision(string $branch): string
    {
        $this->run(sprintf('git fetch origin %s', escapeshellarg($branch)));

        return trim($this->run(sprintf('git rev-parse origin/%s', escapeshellarg($branch))));
    }

    public function currentBranch(): ?string
    {
        [$status, $output] = $this->runWithStatus('git symbolic-ref --quiet --short HEAD', true);

        if ($status !== 0) {
            return null;
        }

        $branch = trim($output);
        return $branch === '' ? null : $branch;
    }

    public function currentRevision(): string
    {
        return trim($this->run('git rev-parse HEAD'));
    }

    public function localBranchRevision(string $branch): ?string
    {
        [$status, $output] = $this->runWithStatus(sprintf('git rev-parse --verify --quiet refs/heads/%s', escapeshellarg($branch)), true);

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
        $this->run(sprintf('git checkout %s', escapeshellarg($ref)));
    }

    public function checkoutDetached(string $revision): void
    {
        $this->run(sprintf('git checkout --detach %s', escapeshellarg($revision)));
    }

    public function hardReset(string $revision): void
    {
        $this->run(sprintf('git reset --hard %s', escapeshellarg($revision)));
    }

    public function cleanUntracked(): void
    {
        $this->run('git clean -fd');
    }

    public function forceBranchToRevision(string $branch, string $revision): void
    {
        $this->run(sprintf('git branch -f %s %s', escapeshellarg($branch), escapeshellarg($revision)));
    }

    public function deleteLocalBranch(string $branch): void
    {
        if ($this->localBranchRevision($branch) === null) {
            return;
        }

        $this->run(sprintf('git branch -D %s', escapeshellarg($branch)));
    }

    public function forcePushRevision(string $branch, string $revision): void
    {
        $this->run(sprintf('git push --force origin %s:%s', escapeshellarg($revision), escapeshellarg($branch)));
    }

    public function deleteRemoteBranch(string $branch): void
    {
        if (! $this->remoteBranchExists($branch)) {
            return;
        }

        $this->run(sprintf('git push origin --delete %s', escapeshellarg($branch)));
    }

    public function assertCleanWorktree(): void
    {
        [$status, $output] = $this->runWithStatus('git status --porcelain', true);

        if ($status !== 0) {
            throw new RuntimeException(sprintf('Unable to inspect Git worktree state.%s', $output === '' ? '' : "\n" . $output));
        }

        if (trim($output) !== '') {
            throw new RuntimeException('Git worktree must be clean before running automation sync commands.');
        }
    }

    private function hasStagedChanges(): bool
    {
        [$status] = $this->runWithStatus('git diff --cached --quiet', true);
        return $status !== 0;
    }

    private function remoteBranchExists(string $branch): bool
    {
        [$status] = $this->runWithStatus(sprintf('git ls-remote --exit-code --heads origin %s', escapeshellarg($branch)), true);
        return $status === 0;
    }

    private function run(string $command): string
    {
        [$status, $output] = $this->runWithStatus($command, false);

        if ($status !== 0) {
            throw new RuntimeException(sprintf("Command failed: %s\n%s", $command, $output));
        }

        return $output;
    }

    /**
     * @return array{int, string}
     */
    private function runWithStatus(string $command, bool $allowFailure): array
    {
        if ($this->dryRun) {
            fwrite(STDOUT, sprintf("[dry-run] %s\n", $command));
            return [0, ''];
        }

        $descriptorSpec = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(['/bin/sh', '-lc', $command], $descriptorSpec, $pipes, $this->repoRoot);

        if (! is_resource($process)) {
            throw new RuntimeException(sprintf('Failed to start command: %s', $command));
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $status = proc_close($process);
        $output = trim((string) $stdout . "\n" . (string) $stderr);

        if (! $allowFailure && $status !== 0) {
            throw new RuntimeException(sprintf("Command failed: %s\n%s", $command, $output));
        }

        return [$status, $output];
    }
}
