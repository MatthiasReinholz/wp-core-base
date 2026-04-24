<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use DateTimeImmutable;

final class ManagedPullRequestCanonicalizer
{
    /**
     * @param list<array<string, mixed>> $plannedPrs
     * @return array{0:list<array<string, mixed>>,1:list<array<string, mixed>>}
     */
    public static function partitionByTargetVersion(array $plannedPrs): array
    {
        $grouped = [];
        $duplicates = [];

        foreach ($plannedPrs as $plannedPr) {
            $targetVersion = (string) ($plannedPr['planned_target_version'] ?? '');

            if ($targetVersion === '') {
                $duplicates[] = $plannedPr;
                continue;
            }

            $grouped[$targetVersion][] = $plannedPr;
        }

        $canonical = [];

        foreach ($grouped as $candidates) {
            usort($candidates, [self::class, 'compare']);
            $canonical[] = array_shift($candidates);
            $duplicates = array_merge($duplicates, $candidates);
        }

        return [$canonical, $duplicates];
    }

    /**
     * @param list<array<string, mixed>> $pullRequests
     */
    public static function selectCanonical(array $pullRequests): ?array
    {
        if ($pullRequests === []) {
            return null;
        }

        usort($pullRequests, [self::class, 'compare']);
        return $pullRequests[0];
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     */
    private static function compare(array $left, array $right): int
    {
        $score = self::metadataScore($right) <=> self::metadataScore($left);

        if ($score !== 0) {
            return $score;
        }

        $branchScore = (self::hasLiveBranch($right) ? 1 : 0) <=> (self::hasLiveBranch($left) ? 1 : 0);

        if ($branchScore !== 0) {
            return $branchScore;
        }

        $updatedScore = self::updatedAtTimestamp($right) <=> self::updatedAtTimestamp($left);

        if ($updatedScore !== 0) {
            return $updatedScore;
        }

        return ((int) ($left['number'] ?? PHP_INT_MAX)) <=> ((int) ($right['number'] ?? PHP_INT_MAX));
    }

    /**
     * @param array<string, mixed> $pullRequest
     */
    private static function metadataScore(array $pullRequest): int
    {
        $score = 0;

        if ((string) ($pullRequest['planned_target_version'] ?? '') !== '') {
            $score += 4;
        }

        if ((string) ($pullRequest['planned_release_at'] ?? '') !== '') {
            $score += 2;
        }

        $metadata = is_array($pullRequest['metadata'] ?? null) ? $pullRequest['metadata'] : [];
        $branch = (string) ($metadata['branch'] ?? $pullRequest['head']['ref'] ?? '');

        if ($branch !== '') {
            $score += 1;
        }

        return $score;
    }

    /**
     * @param array<string, mixed> $pullRequest
     */
    private static function hasLiveBranch(array $pullRequest): bool
    {
        $head = is_array($pullRequest['head'] ?? null) ? $pullRequest['head'] : [];
        $branch = (string) ($head['ref'] ?? '');

        if ($branch === '') {
            return false;
        }

        $headRepo = is_array($head['repo'] ?? null) ? $head['repo'] : [];
        $base = is_array($pullRequest['base'] ?? null) ? $pullRequest['base'] : [];
        $baseRepo = is_array($base['repo'] ?? null) ? $base['repo'] : [];
        $headFullName = strtolower((string) ($headRepo['full_name'] ?? ''));
        $baseFullName = strtolower((string) ($baseRepo['full_name'] ?? ''));

        return $headFullName !== '' && $headFullName === $baseFullName;
    }

    /**
     * @param array<string, mixed> $pullRequest
     */
    private static function updatedAtTimestamp(array $pullRequest): int
    {
        $updatedAt = $pullRequest['updated_at'] ?? null;

        if (! is_string($updatedAt) || trim($updatedAt) === '') {
            return PHP_INT_MIN;
        }

        try {
            return (new DateTimeImmutable($updatedAt))->getTimestamp();
        } catch (\Throwable) {
            return PHP_INT_MIN;
        }
    }
}
