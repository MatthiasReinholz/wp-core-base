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
     * @return array{branch:string, deleted:bool}
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

        $this->cleanupPullRequest($pullRequest);
    }

    /**
     * @param array<string, mixed> $pullRequest
     * @return array{branch:string, deleted:bool}
     */
    private function cleanupPullRequest(array $pullRequest): array
    {
        $number = (int) ($pullRequest['number'] ?? 0);
        $head = is_array($pullRequest['head'] ?? null) ? $pullRequest['head'] : [];
        $headRef = ManagedPullRequestBranchIdentity::validate($pullRequest, $this->automationClient->getDefaultBranch());

        $remoteRevision = $this->gitRunner->remoteBranchRevision($headRef);

        if ($remoteRevision === null) {
            return ['branch' => $headRef, 'deleted' => false];
        }

        $headRevision = (string) ($head['sha'] ?? '');

        if ($headRevision === '') {
            throw new RuntimeException(sprintf('Pull request #%d did not provide a head revision.', $number));
        }

        if (! hash_equals($headRevision, $remoteRevision)) {
            throw new RuntimeException(sprintf('Pull request #%d branch head changed after the PR snapshot was read.', $number));
        }

        $this->gitRunner->deleteRemoteBranch($headRef, $remoteRevision);

        return ['branch' => $headRef, 'deleted' => true];
    }
}
