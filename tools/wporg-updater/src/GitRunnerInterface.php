<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

interface GitRunnerInterface
{
    public function checkoutBranch(string $baseBranch, string $branch, bool $resetToBase = false): void;

    public function commitAndPush(string $branch, string $message, array $paths, bool $force = false): bool;

    public function remoteRevision(string $branch): string;

    public function currentBranch(): ?string;

    public function currentRevision(): string;

    public function localBranchRevision(string $branch): ?string;

    public function remoteBranchRevision(string $branch): ?string;

    public function checkoutRef(string $ref): void;

    public function checkoutDetached(string $revision): void;

    public function hardReset(string $revision): void;

    public function cleanUntracked(): void;

    public function forceBranchToRevision(string $branch, string $revision): void;

    public function deleteLocalBranch(string $branch): void;

    public function forcePushRevision(string $branch, string $revision): void;

    public function deleteRemoteBranch(string $branch): void;

    public function assertCleanWorktree(): void;
}
