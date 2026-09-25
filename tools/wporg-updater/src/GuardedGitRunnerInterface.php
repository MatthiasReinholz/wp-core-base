<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

/** Optional transactional capability; custom Git runners can retain the basic interface. */
interface GuardedGitRunnerInterface extends GitRunnerInterface
{
    public function expectRemoteRevision(string $branch, ?string $revision): void;

    public function expectLocalRevision(string $branch, ?string $revision): void;

    public function ownedCheckoutRevision(string $branch): ?string;

    public function successfulPushRevision(string $branch): ?string;

    public function compareAndSwapRemoteBranch(string $branch, ?string $replacement, ?string $expected): void;

    public function compareAndSwapLocalBranch(string $branch, ?string $replacement, ?string $expected): void;

    /** @param list<string> $paths */
    public function restoreMutationPaths(string $revision, array $paths): void;
}
