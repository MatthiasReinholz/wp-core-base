<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class ManagedPullRequestBranchCleaner
{
    public function __construct(
        private readonly AutomationClient $automationClient,
        private readonly GitRunnerInterface $gitRunner,
    ) {
    }

    /**
     * @return array{branch:string, deleted:bool, reason:string, reused_by_pull_request:?int}
     */
    public function cleanupClosedPullRequest(int $number): array
    {
        if ($number <= 0) {
            throw new RuntimeException('Managed pull request cleanup requires a positive PR number.');
        }

        $pullRequest = $this->automationClient->getPullRequest($number);
        $state = strtolower((string) ($pullRequest['state'] ?? ''));

        if (! in_array($state, ['closed', 'merged'], true)) {
            throw new RuntimeException(sprintf('Pull request #%d is not closed; refusing branch cleanup.', $number));
        }

        return $this->cleanupPullRequest($pullRequest);
    }

    /**
     * Close a framework-managed PR, then clean its branch. Cleanup errors are
     * propagated after the close so automation reports the orphan visibly.
     *
     * @param array<string, mixed> $pullRequest
     */
    public function closeAndCleanup(array $pullRequest, string $reason): void
    {
        $number = (int) ($pullRequest['number'] ?? 0);

        if ($number <= 0) {
            throw new RuntimeException('Cannot close a managed pull request without a positive PR number.');
        }

        $this->automationClient->closePullRequest($number, $reason);

        $result = $this->cleanupPullRequest($pullRequest);
        if ($result['reason'] === 'reused-by-open-pr') {
            fwrite(STDOUT, OutputRedactor::redact(sprintf(
                "Preserved managed branch %s because open pull request #%d uses it.\n",
                $result['branch'],
                $result['reused_by_pull_request']
            )));
        }
    }

    /**
     * @param array<string, mixed> $pullRequest
     * @return array{branch:string, deleted:bool, reason:string, reused_by_pull_request:?int}
     */
    private function cleanupPullRequest(array $pullRequest): array
    {
        $number = (int) ($pullRequest['number'] ?? 0);
        $head = is_array($pullRequest['head'] ?? null) ? $pullRequest['head'] : [];
        $headRef = ManagedPullRequestBranchIdentity::validate($pullRequest, $this->automationClient->getDefaultBranch());

        $remoteRevision = $this->gitRunner->remoteBranchRevision($headRef);

        if ($remoteRevision === null) {
            return ['branch' => $headRef, 'deleted' => false, 'reason' => 'branch-absent', 'reused_by_pull_request' => null];
        }

        $reusedBy = $this->branchReusedByOpenPullRequest($pullRequest, $headRef);
        if ($reusedBy !== null) {
            return ['branch' => $headRef, 'deleted' => false, 'reason' => 'reused-by-open-pr', 'reused_by_pull_request' => $reusedBy];
        }

        $headRevision = (string) ($head['sha'] ?? '');

        if ($headRevision === '') {
            throw new RuntimeException(sprintf('Pull request #%d did not provide a head revision.', $number));
        }

        if (! hash_equals($headRevision, $remoteRevision)) {
            throw new RuntimeException(sprintf('Pull request #%d branch head changed after the PR snapshot was read.', $number));
        }

        $this->gitRunner->deleteRemoteBranch($headRef, $remoteRevision);

        return ['branch' => $headRef, 'deleted' => true, 'reason' => 'deleted', 'reused_by_pull_request' => null];
    }

    /** @param array<string, mixed> $pullRequest */
    private function branchReusedByOpenPullRequest(array $pullRequest, string $headRef): ?int
    {
        // Labels and base branches do not establish exclusive ownership of a head.
        $openPullRequests = $this->automationClient->listOpenPullRequests();
        $repository = strtolower((string) $pullRequest['head']['repo']['full_name']);

        foreach ($openPullRequests as $candidate) {
            if (! is_int($candidate['number'] ?? null) || $candidate['number'] < 1
                || ! in_array($candidate['state'] ?? 'open', ['open', 'opened', 'closed', 'merged'], true)) {
                throw new RuntimeException('Open pull request inventory has incomplete identity metadata; refusing branch cleanup.');
            }
            // Older host adapters can omit state because this API returns open PRs.
            if (! in_array($candidate['state'] ?? 'open', ['open', 'opened'], true)) {
                continue;
            }

            $candidateHead = $candidate['head'] ?? null;
            if (! is_array($candidateHead) || ! is_string($candidateHead['ref'] ?? null) || $candidateHead['ref'] === '') {
                throw new RuntimeException('Open pull request inventory has an unknown head branch; refusing branch cleanup.');
            }
            if ($candidateHead['ref'] !== $headRef) {
                continue;
            }
            $headRepository = $candidateHead['repo']['full_name'] ?? null;
            if (! is_string($headRepository) || $headRepository === '') {
                throw new RuntimeException('Open pull request inventory has an unknown head repository; refusing branch cleanup.');
            }
            if (strtolower($headRepository) === $repository) {
                return $candidate['number'];
            }
        }

        // This observation cannot make opening another PR atomic with Git deletion.
        // The deletion below still uses the exact observed branch revision lease.
        return null;
    }
}
