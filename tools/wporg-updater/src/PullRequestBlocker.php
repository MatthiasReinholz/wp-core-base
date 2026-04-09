<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class PullRequestBlocker
{
    public const STATUS_CLEAR = 'clear';
    public const STATUS_BLOCKED = 'blocked';
    public const STATUS_DEGRADED = 'degraded';
    public const STATE_UNBLOCKED = 'unblocked';
    public const STATE_INTENTIONALLY_BLOCKED = 'intentionally-blocked';
    public const STATE_WAITING_RETRY = 'waiting-for-retry';

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

        $eventContents = file_get_contents($eventPath);

        if (! is_string($eventContents)) {
            throw new RuntimeException('Unable to read pull request event payload from GITHUB_EVENT_PATH.');
        }

        $event = json_decode($eventContents, true);

        if (! is_array($event) || ! is_array($event['pull_request'] ?? null)) {
            throw new RuntimeException('Event payload does not include pull_request data.');
        }

        $pullRequest = (array) $event['pull_request'];
        $metadata = PrBodyRenderer::extractMetadata((string) ($pullRequest['body'] ?? ''));

        if ($metadata === null) {
            fwrite(STDOUT, "No updater metadata found on this pull request. Passing.\n");
            return $this->clearResult((int) ($pullRequest['number'] ?? 0), [], self::STATE_UNBLOCKED)->toArray();
        }

        $result = $this->evaluatePullRequestStatus($pullRequest);
        $this->emitResultLine($result->toArray());
        return $result->toArray();
    }

    /**
     * @return array{status:string, exit_code:int, blockers:list<string>, warnings:list<string>, state:string, pull_request_number:int}
     */
    public function evaluatePullRequestNumberStatus(int $pullRequestNumber): array
    {
        if ($pullRequestNumber <= 0) {
            throw new RuntimeException('Pull request number must be a positive integer.');
        }

        $pullRequest = $this->gitHubClient->getPullRequest($pullRequestNumber);
        $result = $this->evaluatePullRequestStatus($pullRequest);
        $this->emitResultLine($result->toArray());
        return $result->toArray();
    }

    /**
     * @return array{status:string, exit_code:int, results:list<array{status:string, exit_code:int, blockers:list<string>, warnings:list<string>, state:string, pull_request_number:int}>, warnings:list<string>}
     */
    public function evaluateOpenAutomationPullRequestsStatus(): array
    {
        try {
            $openPullRequests = $this->gitHubClient->listOpenPullRequests();
        } catch (\Throwable $throwable) {
            $warning = sprintf(
                'Could not list open pull requests for blocker reconciliation scan: %s',
                OutputRedactor::redact($throwable->getMessage())
            );
            $this->emitWarnings([$warning]);
            return (new PullRequestBlockerReconcileResult(
                status: self::STATUS_DEGRADED,
                exitCode: 1,
                results: [],
                warnings: [$warning],
            ))->toArray();
        }

        $results = [];
        $warnings = [];

        foreach ($openPullRequests as $pullRequest) {
            $metadata = PrBodyRenderer::extractMetadata((string) ($pullRequest['body'] ?? ''));

            if ($metadata === null) {
                continue;
            }

            $result = $this->evaluatePullRequestStatus($pullRequest, $openPullRequests);
            $results[] = $result;
            $warnings = array_merge($warnings, $result->warnings);
        }

        $degraded = array_values(array_filter($results, static fn (PullRequestBlockerResult $result): bool => $result->status === self::STATUS_DEGRADED));

        if ($degraded !== []) {
            fwrite(STDERR, sprintf(
                "Blocker reconciliation detected %d degraded updater PR evaluations; manual retry is required.\n",
                count($degraded)
            ));
            return (new PullRequestBlockerReconcileResult(
                status: self::STATUS_DEGRADED,
                exitCode: 1,
                results: $results,
                warnings: array_values(array_unique($warnings)),
            ))->toArray();
        }

        fwrite(STDOUT, sprintf(
            "Blocker reconciliation evaluated %d open updater PRs; no degraded states remain.\n",
            count($results)
        ));

        return (new PullRequestBlockerReconcileResult(
            status: self::STATUS_CLEAR,
            exitCode: 0,
            results: $results,
            warnings: array_values(array_unique($warnings)),
        ))->toArray();
    }

    /**
     * @param array<string, mixed> $pullRequest
     * @param list<array<string, mixed>>|null $openPullRequestsSnapshot
     * @return PullRequestBlockerResult
     */
    private function evaluatePullRequestStatus(array $pullRequest, ?array $openPullRequestsSnapshot = null): PullRequestBlockerResult
    {
        $metadata = PrBodyRenderer::extractMetadata((string) ($pullRequest['body'] ?? ''));

        if ($metadata === null) {
            return $this->clearResult((int) ($pullRequest['number'] ?? 0), [], self::STATE_UNBLOCKED);
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

        $openPullRequests = $openPullRequestsSnapshot;

        if ($openPullRequests === null) {
            try {
                $openPullRequests = $this->gitHubClient->listOpenPullRequests();
            } catch (\Throwable $throwable) {
                $verificationWarnings[] = sprintf(
                    'Could not list open pull requests for blocker evaluation and blocked until verification succeeds: %s',
                    OutputRedactor::redact($throwable->getMessage())
                );
                $this->emitWarnings($verificationWarnings);
                fwrite(STDERR, "Blocker evaluation is degraded by GitHub/API failures. Blocking until verification succeeds.\n");
                return new PullRequestBlockerResult(
                    status: self::STATUS_DEGRADED,
                    exitCode: 1,
                    blockers: [],
                    warnings: $verificationWarnings,
                    state: self::STATE_WAITING_RETRY,
                    pullRequestNumber: $currentNumber,
                );
            }
        }

        if (! is_array($openPullRequests)) {
            $verificationWarnings[] = sprintf(
                'Could not list open pull requests for blocker evaluation and blocked until verification succeeds: %s',
                'snapshot was invalid'
            );
            $this->emitWarnings($verificationWarnings);
            fwrite(STDERR, "Blocker evaluation is degraded by GitHub/API failures. Blocking until verification succeeds.\n");
            return new PullRequestBlockerResult(
                status: self::STATUS_DEGRADED,
                exitCode: 1,
                blockers: [],
                warnings: $verificationWarnings,
                state: self::STATE_WAITING_RETRY,
                pullRequestNumber: $currentNumber,
            );
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
            return new PullRequestBlockerResult(
                status: self::STATUS_BLOCKED,
                exitCode: 1,
                blockers: $blockers,
                warnings: $verificationWarnings,
                state: self::STATE_INTENTIONALLY_BLOCKED,
                pullRequestNumber: $currentNumber,
            );
        }

        fwrite(STDOUT, $verificationWarnings === []
            ? "No older open update PRs found. Passing.\n"
            : "No confirmed older open update PRs found, but verification is degraded. Blocking until verification succeeds.\n");
        return new PullRequestBlockerResult(
            status: $verificationWarnings === [] ? self::STATUS_CLEAR : self::STATUS_DEGRADED,
            exitCode: $verificationWarnings === [] ? 0 : 1,
            blockers: [],
            warnings: $verificationWarnings,
            state: $verificationWarnings === [] ? self::STATE_UNBLOCKED : self::STATE_WAITING_RETRY,
            pullRequestNumber: $currentNumber,
        );
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

    /**
     * @param list<string> $warnings
     * @return PullRequestBlockerResult
     */
    private function clearResult(int $pullRequestNumber, array $warnings, string $state): PullRequestBlockerResult
    {
        return new PullRequestBlockerResult(
            status: self::STATUS_CLEAR,
            exitCode: 0,
            blockers: [],
            warnings: $warnings,
            state: $state,
            pullRequestNumber: $pullRequestNumber,
        );
    }

    /**
     * @param array{status:string, exit_code:int, blockers:list<string>, warnings:list<string>, state:string, pull_request_number:int} $result
     */
    private function emitResultLine(array $result): void
    {
        fwrite(STDOUT, sprintf(
            "BLOCKER_STATE status=%s state=%s pr=%d blockers=%d warnings=%d\n",
            $result['status'],
            $result['state'],
            $result['pull_request_number'],
            count($result['blockers']),
            count($result['warnings'])
        ));
    }
}
