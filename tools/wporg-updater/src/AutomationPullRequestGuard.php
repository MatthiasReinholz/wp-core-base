<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class AutomationPullRequestGuard
{
    /**
     * @param array<string, mixed> $metadata
     */
    public static function branchRefreshRequired(array $metadata, string $baseRevision): bool
    {
        if ($baseRevision === '') {
            return false;
        }

        $recordedBaseRevision = $metadata['base_revision'] ?? null;

        return ! is_string($recordedBaseRevision)
            || $recordedBaseRevision === ''
            || ! hash_equals($recordedBaseRevision, $baseRevision);
    }

    /**
     * @param array<string, mixed> $pullRequest
     */
    public static function assertRefreshable(array $pullRequest, string $branch, string $defaultBranch, string $subject): void
    {
        $baseRef = (string) ($pullRequest['base']['ref'] ?? '');

        if ($branch === $defaultBranch || ($baseRef !== '' && $branch === $baseRef)) {
            throw new RuntimeException(sprintf(
                '%s #%d resolved to protected branch %s and will not be refreshed.',
                $subject,
                (int) ($pullRequest['number'] ?? 0),
                $branch
            ));
        }

        if (! self::isSameRepositoryAutomationPullRequest($pullRequest)) {
            throw new RuntimeException(sprintf(
                '%s #%d does not use a same-repository automation branch and will not be refreshed.',
                $subject,
                (int) ($pullRequest['number'] ?? 0)
            ));
        }
    }

    /**
     * @param array<string, mixed> $pullRequest
     */
    public static function isSameRepositoryAutomationPullRequest(array $pullRequest): bool
    {
        $head = is_array($pullRequest['head'] ?? null) ? $pullRequest['head'] : [];
        $base = is_array($pullRequest['base'] ?? null) ? $pullRequest['base'] : [];
        $headRef = (string) ($head['ref'] ?? '');
        $headRepo = is_array($head['repo'] ?? null) ? $head['repo'] : [];
        $baseRepo = is_array($base['repo'] ?? null) ? $base['repo'] : [];
        $headFullName = strtolower((string) ($headRepo['full_name'] ?? ''));
        $baseFullName = strtolower((string) ($baseRepo['full_name'] ?? ''));

        return $headRef !== '' && $headFullName !== '' && $headFullName === $baseFullName;
    }
}
