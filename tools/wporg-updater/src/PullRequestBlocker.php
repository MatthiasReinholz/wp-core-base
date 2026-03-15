<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class PullRequestBlocker
{
    public function __construct(private readonly GitHubClient $gitHubClient)
    {
    }

    public function evaluateCurrentPullRequest(): int
    {
        $eventPath = getenv('GITHUB_EVENT_PATH');

        if (! is_string($eventPath) || $eventPath === '' || ! is_file($eventPath)) {
            throw new RuntimeException('GITHUB_EVENT_PATH must point to a pull request event payload.');
        }

        $event = json_decode((string) file_get_contents($eventPath), true);

        if (! is_array($event) || ! is_array($event['pull_request'] ?? null)) {
            throw new RuntimeException('Event payload does not include pull_request data.');
        }

        $pullRequest = $event['pull_request'];
        $metadata = PrBodyRenderer::extractMetadata((string) ($pullRequest['body'] ?? ''));

        if ($metadata === null) {
            fwrite(STDOUT, "No updater metadata found on this pull request. Passing.\n");
            return 0;
        }

        $slug = (string) ($metadata['slug'] ?? '');
        $targetVersion = (string) ($metadata['target_version'] ?? '');
        $currentNumber = (int) ($pullRequest['number'] ?? 0);

        if ($slug === '' || $targetVersion === '' || $currentNumber === 0) {
            throw new RuntimeException('Updater metadata is missing slug, target_version, or pull request number.');
        }

        $olderOpenPrs = [];
        $unmergedPredecessors = [];

        foreach ((array) ($metadata['blocked_by'] ?? []) as $blocker) {
            if (! is_int($blocker) && ! ctype_digit((string) $blocker)) {
                continue;
            }

            $blockerNumber = (int) $blocker;

            if ($blockerNumber === $currentNumber) {
                continue;
            }

            try {
                $pullRequest = $this->gitHubClient->getPullRequest($blockerNumber);
                $mergedAt = $pullRequest['merged_at'] ?? null;

                if (! is_string($mergedAt) || $mergedAt === '') {
                    $unmergedPredecessors[] = sprintf('#%d', $blockerNumber);
                }
            } catch (\Throwable) {
                $unmergedPredecessors[] = sprintf('#%d', $blockerNumber);
            }
        }

        foreach ($this->gitHubClient->listOpenPullRequests() as $candidate) {
            $candidateNumber = (int) ($candidate['number'] ?? 0);

            if ($candidateNumber === $currentNumber) {
                continue;
            }

            $candidateMetadata = PrBodyRenderer::extractMetadata((string) ($candidate['body'] ?? ''));

            if ($candidateMetadata === null || ($candidateMetadata['slug'] ?? null) !== $slug) {
                continue;
            }

            $candidateVersion = (string) ($candidateMetadata['target_version'] ?? '');

            if ($candidateVersion !== '' && version_compare($candidateVersion, $targetVersion, '<')) {
                $olderOpenPrs[] = sprintf('#%d (%s)', $candidateNumber, $candidateVersion);
            }
        }

        $blockers = array_values(array_unique(array_merge($unmergedPredecessors, $olderOpenPrs)));

        if ($blockers !== []) {
            fwrite(STDERR, "Blocked by predecessor update PRs: " . implode(', ', $blockers) . "\n");
            return 1;
        }

        fwrite(STDOUT, "No older open update PRs found. Passing.\n");
        return 0;
    }
}
