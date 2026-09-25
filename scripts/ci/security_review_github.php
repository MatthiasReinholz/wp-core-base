<?php

declare(strict_types=1);

namespace WpCoreBaseCi;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/** Read-only, bounded GitHub inventories for source-repository release review. */
final class SecurityReviewGitHub
{
    /** @param Closure(string):array<mixed> $fetch */
    public function __construct(private Closure $fetch)
    {
    }

    /**
     * Fetch every page, rejecting duplicate IDs, changing totals and truncation.
     * @return list<array<string,mixed>>
     */
    public function inventory(string $endpoint, ?string $collection = null): array
    {
        $items = [];
        $ids = [];
        $expectedTotal = null;
        for ($page = 1; $page <= 100; $page++) {
            $data = ($this->fetch)($endpoint . (str_contains($endpoint, '?') ? '&' : '?') . 'per_page=100&page=' . $page);
            if ($collection !== null) {
                $total = $data['total_count'] ?? null;
                if (! is_int($total) || $total < 0 || ($expectedTotal !== null && $total !== $expectedTotal)) {
                    throw new RuntimeException('Incomplete or changing GitHub inventory total.');
                }
                $expectedTotal = $total;
                $data = $data[$collection] ?? null;
            }
            if (! is_array($data) || ! array_is_list($data) || count($data) > 100) {
                throw new RuntimeException('Invalid GitHub inventory page.');
            }
            foreach ($data as $item) {
                if (! is_array($item)) {
                    throw new RuntimeException('Invalid GitHub inventory record.');
                }
                $id = $item['id'] ?? $item['number'] ?? null;
                if (! is_int($id) || $id < 1 || isset($ids[$id])) {
                    throw new RuntimeException('Missing or duplicate GitHub inventory identity.');
                }
                $ids[$id] = true;
                $items[] = $item;
            }
            if (count($data) < 100) {
                if ($expectedTotal !== null && count($items) !== $expectedTotal) {
                    throw new RuntimeException('GitHub inventory was truncated.');
                }
                return $items;
            }
        }
        throw new RuntimeException('GitHub inventory exceeded the 100-page safety limit.');
    }

    /**
     * The latest scanner attempt must finish successfully; old success cannot
     * authorize a release while a newer attempt is pending or has failed.
     * @param list<array<string,mixed>> $runs
     * @return array<string,mixed>|null Null means no completed scan is ready yet.
     */
    public function completedScan(array $runs, string $path, string $sha, string $branch): ?array
    {
        $latest = null;
        $latestStarted = null;
        $pending = false;
        foreach ($runs as $run) {
            if (($run['path'] ?? null) !== $path || ($run['head_sha'] ?? null) !== $sha
                || ($run['head_branch'] ?? null) !== $branch) {
                throw new RuntimeException('GitHub returned a scanner run outside the requested identity.');
            }
            $attempt = $run['run_attempt'] ?? null;
            $this->timestamp($run['created_at'] ?? null);
            if (! is_int($run['id'] ?? null) || $run['id'] < 1
                || ! is_int($attempt) || $attempt < 1 || ! in_array($run['event'] ?? null, ['push', 'dynamic', 'workflow_dispatch', 'schedule'], true)) {
                throw new RuntimeException('Incomplete scanner run metadata.');
            }
            if (in_array($run['status'] ?? null, ['queued', 'in_progress', 'waiting', 'pending', 'requested'], true)) {
                // Queued reruns may not have their new attempt's start time
                // yet. No older success can authorize release while any exact-
                // revision scanner attempt is unresolved.
                $pending = true;
                continue;
            }
            if (($run['status'] ?? null) !== 'completed') {
                throw new RuntimeException('Missing or unsupported scanner run status.');
            }
            $started = $this->timestamp($run['run_started_at'] ?? null);
            // created_at and run ID identify the original run, not when an
            // older run was most recently rerun.
            if ($latest === null || [$started, $run['id'], $attempt]
                > [$latestStarted, $latest['id'], $latest['run_attempt']]) {
                $latest = $run;
                $latestStarted = $started;
            }
        }
        if ($pending || $latest === null) {
            return null;
        }
        if (($latest['conclusion'] ?? null) !== 'success') {
            throw new RuntimeException('The latest exact-revision scanner run did not succeed.');
        }
        return $latest;
    }

    /**
     * An exact-commit rerun cannot reuse analyses uploaded by an older attempt.
     * @param list<array<string,mixed>> $analyses
     * @param array<string,mixed> $scan
     */
    public function assertFreshAnalyses(array $analyses, array $scan): void
    {
        $started = $this->timestamp($scan['run_started_at'] ?? null);
        foreach ($analyses as $analysis) {
            $created = $this->timestamp($analysis['created_at'] ?? null);
            if ($created < $started) {
                throw new RuntimeException('Scanner analysis predates the successful scanner attempt.');
            }
        }
    }

    private function timestamp(mixed $value): int
    {
        if (! is_string($value)) {
            throw new RuntimeException('Scanner evidence timestamp is missing.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d\TH:i:s\Z') !== $value) {
            throw new RuntimeException('Scanner evidence timestamp is invalid.');
        }
        return $date->getTimestamp();
    }
}
