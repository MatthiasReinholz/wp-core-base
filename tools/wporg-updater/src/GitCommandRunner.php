<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class GitCommandRunner
{
    public function __construct(
        private readonly string $repoRoot,
        private readonly bool $dryRun = false,
    ) {
    }

    public function checkoutBranch(string $baseBranch, string $branch): void
    {
        $this->run(sprintf('git fetch origin %s', escapeshellarg($baseBranch)));

        if ($this->remoteBranchExists($branch)) {
            $this->run(sprintf('git fetch origin %s', escapeshellarg($branch)));
            $this->run(sprintf('git checkout -B %1$s origin/%1$s', escapeshellarg($branch)));
            return;
        }

        $this->run(sprintf('git checkout -B %s origin/%s', escapeshellarg($branch), escapeshellarg($baseBranch)));
    }

    public function commitAndPush(string $branch, string $message, array $paths): bool
    {
        $pathArgs = implode(' ', array_map(static fn (string $path): string => escapeshellarg($path), $paths));
        $this->run(sprintf('git add --all -- %s', $pathArgs));

        if (! $this->hasStagedChanges()) {
            return false;
        }

        $this->run(sprintf('git commit -m %s', escapeshellarg($message)));
        $this->run(sprintf('git push -u origin %s', escapeshellarg($branch)));

        return true;
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
