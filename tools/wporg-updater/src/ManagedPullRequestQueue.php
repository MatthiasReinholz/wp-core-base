<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

final class ManagedPullRequestQueue
{
    /**
     * @param list<mixed> $blockedBy
     * @param list<array<string, mixed>> $activePlannedPrs
     * @return list<int>
     */
    public static function blockedByForPlannedPullRequest(
        GitHubPullRequestReader $gitHubClient,
        array $blockedBy,
        array $activePlannedPrs,
    ): array {
        return array_values(array_unique(array_merge(
            self::unresolvedBlockedBy($gitHubClient, $blockedBy),
            array_values(array_map(
                static fn (array $previous): int => (int) $previous['number'],
                $activePlannedPrs
            ))
        )));
    }

    /**
     * @param list<mixed> $blockedBy
     * @return list<int>
     */
    public static function unresolvedBlockedBy(GitHubPullRequestReader $gitHubClient, array $blockedBy): array
    {
        $unresolved = [];

        foreach ($blockedBy as $number) {
            if (! is_int($number) && ! ctype_digit((string) $number)) {
                continue;
            }

            $pullRequestNumber = (int) $number;

            try {
                $pullRequest = $gitHubClient->getPullRequest($pullRequestNumber);
                $mergedAt = $pullRequest['merged_at'] ?? null;
                $state = (string) ($pullRequest['state'] ?? '');

                if ($state === 'open' && (! is_string($mergedAt) || $mergedAt === '')) {
                    $unresolved[] = $pullRequestNumber;
                }
            } catch (\Throwable) {
                $unresolved[] = $pullRequestNumber;
            }
        }

        return array_values(array_unique($unresolved));
    }

    /**
     * @param array<string, mixed> $pullRequest
     * @param list<int> $blockedBy
     */
    public static function syncDraftState(
        GitHubAutomationClient $gitHubClient,
        array $pullRequest,
        array $blockedBy,
    ): void {
        $nodeId = (string) ($pullRequest['node_id'] ?? '');

        if ($nodeId === '') {
            return;
        }

        $isDraft = (bool) ($pullRequest['draft'] ?? false);

        if ($blockedBy !== [] && ! $isDraft) {
            $gitHubClient->convertToDraft($nodeId);
            return;
        }

        if ($blockedBy === [] && $isDraft) {
            $gitHubClient->markReadyForReview($nodeId);
        }
    }
}
