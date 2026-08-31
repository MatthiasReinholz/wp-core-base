<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class ManagedPullRequestBranchCleaner
{
    /** @var array<string, string> */
    private const LABEL_BRANCH_PREFIXES = [
        'automation:dependency-update' => 'codex/wporg-',
        'automation:framework-update' => 'codex/framework-',
    ];

    private const CORE_BRANCH_PREFIX = 'codex/wordpress-core-';

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
        $metadata = PrBodyRenderer::extractMetadata((string) ($pullRequest['body'] ?? ''));

        if ($metadata === null) {
            throw new RuntimeException(sprintf('Pull request #%d has no valid wp-core-base metadata.', $number));
        }

        if (! AutomationPullRequestGuard::isSameRepositoryAutomationPullRequest($pullRequest)) {
            throw new RuntimeException(sprintf('Pull request #%d does not use a same-repository branch.', $number));
        }

        $head = is_array($pullRequest['head'] ?? null) ? $pullRequest['head'] : [];
        $base = is_array($pullRequest['base'] ?? null) ? $pullRequest['base'] : [];
        $headRef = (string) ($head['ref'] ?? '');
        $metadataBranch = (string) ($metadata['branch'] ?? '');

        if ($headRef === '' || $metadataBranch === '' || ! hash_equals($headRef, $metadataBranch)) {
            throw new RuntimeException(sprintf('Pull request #%d metadata does not match its head branch.', $number));
        }

        $defaultBranch = $this->automationClient->getDefaultBranch();
        $baseRef = (string) ($base['ref'] ?? '');

        if ($headRef === $defaultBranch || ($baseRef !== '' && $headRef === $baseRef)) {
            throw new RuntimeException(sprintf('Pull request #%d resolves to protected branch %s.', $number, $headRef));
        }

        $expectedPrefix = $this->expectedBranchPrefix($pullRequest, $metadata);

        if (! str_starts_with($headRef, $expectedPrefix) || strlen($headRef) <= strlen($expectedPrefix)) {
            throw new RuntimeException(sprintf(
                'Pull request #%d branch %s is outside the managed namespace %s.',
                $number,
                $headRef,
                $expectedPrefix
            ));
        }

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

    /**
     * @param array<string, mixed> $pullRequest
     * @param array<string, mixed> $metadata
     */
    private function expectedBranchPrefix(array $pullRequest, array $metadata): string
    {
        $labels = [];

        foreach ((array) ($pullRequest['labels'] ?? []) as $label) {
            $name = is_array($label) ? (string) ($label['name'] ?? '') : (string) $label;

            if ($name !== '') {
                $labels[$name] = true;
            }
        }

        $recognizedLabels = array_values(array_intersect(array_keys(self::LABEL_BRANCH_PREFIXES), array_keys($labels)));

        if (count($recognizedLabels) !== 1) {
            throw new RuntimeException('Pull request must have exactly one recognized wp-core-base automation label.');
        }

        if ($recognizedLabels[0] === 'automation:framework-update') {
            if (($metadata['component_key'] ?? null) !== 'framework:wp-core-base') {
                throw new RuntimeException('Framework automation metadata has an unexpected component key.');
            }

            return self::LABEL_BRANCH_PREFIXES['automation:framework-update'];
        }

        if ($recognizedLabels[0] === 'automation:dependency-update') {
            if (($metadata['kind'] ?? null) === 'core' && ($metadata['slug'] ?? null) === 'wordpress-core') {
                return self::CORE_BRANCH_PREFIX;
            }

            $componentKey = (string) ($metadata['component_key'] ?? '');

            if ($componentKey === '' || ! str_contains($componentKey, ':')) {
                throw new RuntimeException('Dependency automation metadata has no valid component key.');
            }

            return self::LABEL_BRANCH_PREFIXES['automation:dependency-update'];
        }

        throw new RuntimeException('Pull request does not have a recognized wp-core-base automation label.');
    }
}
