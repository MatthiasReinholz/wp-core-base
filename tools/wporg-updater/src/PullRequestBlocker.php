<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class PullRequestBlocker
{
    public const STATUS_CLEAR = 'clear';
    public const STATUS_BLOCKED = 'blocked';
    public const STATUS_DEGRADED = 'degraded';

    public function __construct(private readonly GitHubPullRequestReader $gitHubClient)
    {
    }

    public function evaluateCurrentPullRequest(): int
    {
        return (int) $this->evaluateCurrentPullRequestStatus()['exit_code'];
    }

    /**
     * @return array{status:string, exit_code:int, blockers:list<string>, warnings:list<string>}
     */
    public function evaluateCurrentPullRequestStatus(): array
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
            return [
                'status' => self::STATUS_CLEAR,
                'exit_code' => 0,
                'blockers' => [],
                'warnings' => [],
            ];
        }

        $identity = $this->identityForMetadata($metadata);
        $targetVersion = (string) ($metadata['target_version'] ?? '');
        $currentNumber = (int) ($pullRequest['number'] ?? 0);

        if ($identity === '' || $targetVersion === '' || $currentNumber === 0) {
            throw new RuntimeException('Updater metadata is missing component identity, target_version, or pull request number.');
        }

        $olderOpenPrs = [];
        $unmergedPredecessors = [];
        $verificationWarnings = [];

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
                $state = (string) ($pullRequest['state'] ?? '');

                if ($state === 'open' && (! is_string($mergedAt) || $mergedAt === '')) {
                    $unmergedPredecessors[] = sprintf('#%d', $blockerNumber);
                }
            } catch (\Throwable $throwable) {
                $verificationWarnings[] = sprintf(
                    'Could not verify predecessor PR #%d and ignored it to avoid a false blocker result: %s',
                    $blockerNumber,
                    OutputRedactor::redact($throwable->getMessage())
                );
            }
        }

        try {
            $openPullRequests = $this->gitHubClient->listOpenPullRequests();
        } catch (\Throwable $throwable) {
            $verificationWarnings[] = sprintf(
                'Could not list open pull requests for blocker evaluation and blocked until verification succeeds: %s',
                OutputRedactor::redact($throwable->getMessage())
            );
            $this->emitWarnings($verificationWarnings);
            fwrite(STDERR, "Blocker evaluation is degraded by GitHub/API failures. Blocking until verification succeeds.\n");
            return [
                'status' => self::STATUS_DEGRADED,
                'exit_code' => 1,
                'blockers' => [],
                'warnings' => $verificationWarnings,
            ];
        }

        foreach ($openPullRequests as $candidate) {
            $candidateNumber = (int) ($candidate['number'] ?? 0);

            if ($candidateNumber === $currentNumber) {
                continue;
            }

            $candidateMetadata = PrBodyRenderer::extractMetadata((string) ($candidate['body'] ?? ''));

            if ($candidateMetadata === null || ! $this->metadataMatchesIdentity($candidateMetadata, $metadata)) {
                continue;
            }

            $candidateVersion = (string) ($candidateMetadata['target_version'] ?? '');

            if ($candidateVersion !== '' && version_compare($candidateVersion, $targetVersion, '<')) {
                $olderOpenPrs[] = sprintf('#%d (%s)', $candidateNumber, $candidateVersion);
            }
        }

        $blockers = array_values(array_unique(array_merge($unmergedPredecessors, $olderOpenPrs)));
        $this->emitWarnings($verificationWarnings);

        if ($blockers !== []) {
            fwrite(STDERR, "Blocked by predecessor update PRs: " . implode(', ', $blockers) . "\n");
            return [
                'status' => self::STATUS_BLOCKED,
                'exit_code' => 1,
                'blockers' => $blockers,
                'warnings' => $verificationWarnings,
            ];
        }

        fwrite(STDOUT, $verificationWarnings === []
            ? "No older open update PRs found. Passing.\n"
            : "No confirmed older open update PRs found, but verification is degraded. Blocking until verification succeeds.\n");
        return [
            'status' => $verificationWarnings === [] ? self::STATUS_CLEAR : self::STATUS_DEGRADED,
            'exit_code' => $verificationWarnings === [] ? 0 : 1,
            'blockers' => [],
            'warnings' => $verificationWarnings,
        ];
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function identityForMetadata(array $metadata): string
    {
        $componentKey = $metadata['component_key'] ?? null;

        if (is_string($componentKey) && $componentKey !== '') {
            return $componentKey;
        }

        $slug = $metadata['slug'] ?? null;

        return is_string($slug) ? $slug : '';
    }

    /**
     * @param array<string, mixed> $candidate
     * @param array<string, mixed> $reference
     */
    private function metadataMatchesIdentity(array $candidate, array $reference): bool
    {
        if ($this->identityForMetadata($candidate) === $this->identityForMetadata($reference)) {
            return true;
        }

        if (
            (string) ($candidate['kind'] ?? '') !== (string) ($reference['kind'] ?? '')
            || (string) ($candidate['source'] ?? '') !== (string) ($reference['source'] ?? '')
            || (string) ($candidate['slug'] ?? '') !== (string) ($reference['slug'] ?? '')
        ) {
            return false;
        }

        if ((string) ($candidate['dependency_path'] ?? '') !== '' && ($candidate['dependency_path'] ?? null) === ($reference['dependency_path'] ?? null)) {
            return true;
        }

        return (string) ($candidate['provider'] ?? '') !== ''
            && (string) ($candidate['provider'] ?? '') === (string) ($reference['provider'] ?? '');
    }

    /**
     * @param list<string> $warnings
     */
    private function emitWarnings(array $warnings): void
    {
        foreach ($warnings as $warning) {
            fwrite(STDERR, sprintf("[warn] %s\n", $warning));
        }
    }
}
