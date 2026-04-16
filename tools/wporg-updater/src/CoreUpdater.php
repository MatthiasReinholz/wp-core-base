<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class CoreUpdater
{
    private ?CoreArchiveApplier $archiveApplier = null;

    public function __construct(
        private readonly Config $config,
        private readonly CoreScanner $coreScanner,
        private readonly WordPressCoreClient $coreClient,
        private readonly ReleaseClassifier $releaseClassifier,
        private readonly PrBodyRenderer $prBodyRenderer,
        private readonly GitHubAutomationClient $gitHubClient,
        private readonly GitRunnerInterface $gitRunner,
        private readonly ArchiveDownloader $archiveDownloader = new HttpClient(),
    ) {
    }

    public function sync(): void
    {
        if (! $this->config->coreManaged()) {
            return;
        }

        $this->gitRunner->assertCleanWorktree();
        $defaultBranch = $this->config->baseBranch() ?? $this->gitHubClient->getDefaultBranch();
        $baseRevision = $this->gitRunner->remoteRevision($defaultBranch);
        $this->gitHubClient->ensureLabels(Updater::labelDefinitions());
        $current = $this->coreScanner->inspect($this->config->repoRoot);
        $release = $this->coreClient->fetchLatestStableRelease();
        $existingPrs = $this->existingCorePrs();
        $plannedPrs = [];

        foreach ($existingPrs as $pr) {
            try {
                $plannedPrs[] = $this->planExistingPullRequest($pr, $current['version'], (string) $release['version'], (string) $release['release_at'], $baseRevision);
            } catch (\Throwable $throwable) {
                fwrite(STDERR, sprintf(
                    "[warn] Ignoring malformed core automation PR #%d: %s\n",
                    (int) ($pr['number'] ?? 0),
                    OutputRedactor::redact($throwable->getMessage())
                ));
            }
        }

        [$plannedPrs, $duplicatePrs] = $this->partitionPullRequestsByTargetVersion($plannedPrs);

        foreach ($duplicatePrs as $duplicatePr) {
            $this->closeSupersededPullRequest(
                (int) $duplicatePr['number'],
                sprintf(
                    'Another automation PR already covers WordPress core at `%s`. This duplicate PR is being closed to keep one live PR per target version.',
                    (string) $duplicatePr['planned_target_version']
                )
            );
        }

        usort($plannedPrs, fn (array $left, array $right): int => version_compare($left['planned_target_version'], $right['planned_target_version']));
        $activePlannedPrs = [];

        foreach ($plannedPrs as $plannedPr) {
            $blockedBy = ManagedPullRequestQueue::blockedByForPlannedPullRequest(
                $this->gitHubClient,
                (array) (($plannedPr['metadata']['blocked_by'] ?? [])),
                $activePlannedPrs
            );

            if ($this->refreshPullRequest(
                currentVersion: $current['version'],
                release: $release,
                plannedPr: $plannedPr,
                blockedBy: $blockedBy,
                defaultBranch: $defaultBranch,
                baseRevision: $baseRevision,
            )) {
                $activePlannedPrs[] = $plannedPr;
            }
        }

        $highestCoveredVersion = $current['version'];

        foreach ($activePlannedPrs as $plannedPr) {
            if (version_compare($plannedPr['planned_target_version'], $highestCoveredVersion, '>')) {
                $highestCoveredVersion = $plannedPr['planned_target_version'];
            }
        }

        if (version_compare((string) $release['version'], $highestCoveredVersion, '>')) {
            $scope = $this->releaseClassifier->classifyScope($highestCoveredVersion, (string) $release['version']);
            $this->createPullRequestForLatest(
                currentVersion: $current['version'],
                release: $release,
                scope: $scope,
                blockedBy: array_values(array_map(static fn (array $pr): int => (int) $pr['number'], $activePlannedPrs)),
                defaultBranch: $defaultBranch,
                baseRevision: $baseRevision,
            );
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function existingCorePrs(): array
    {
        $prs = [];

        foreach ($this->gitHubClient->listOpenPullRequests('automation:dependency-update') as $pullRequest) {
            $metadata = PrBodyRenderer::extractMetadata((string) ($pullRequest['body'] ?? ''));

            if (($metadata['kind'] ?? null) === 'core' && AutomationPullRequestGuard::isSameRepositoryAutomationPullRequest($pullRequest)) {
                $pullRequest['metadata'] = $metadata;
                $prs[] = $pullRequest;
            }
        }

        return $prs;
    }

    /**
     * @param array<string, mixed> $pullRequest
     * @return array<string, mixed>
     */
    private function planExistingPullRequest(array $pullRequest, string $baseVersion, string $latestVersion, string $latestReleaseAt, string $baseRevision): array
    {
        $metadata = $pullRequest['metadata'];
        $targetVersion = (string) ($metadata['target_version'] ?? '');
        $releaseAt = (string) ($metadata['release_at'] ?? '');
        $scope = (string) ($metadata['scope'] ?? 'none');

        if ($targetVersion === '' || $releaseAt === '') {
            throw new RuntimeException(sprintf('Managed core pull request #%d has incomplete metadata.', $pullRequest['number']));
        }

        $requiresCodeUpdate = AutomationPullRequestGuard::branchRefreshRequired($metadata, $baseRevision);

        if (
            $this->releaseClassifier->samePatchLine($targetVersion, $latestVersion) &&
            version_compare($latestVersion, $targetVersion, '>') &&
            $this->releaseClassifier->classifyScope($targetVersion, $latestVersion) === 'patch'
        ) {
            $targetVersion = $latestVersion;
            $releaseAt = $latestReleaseAt;
            $scope = 'patch';
            $requiresCodeUpdate = true;
        }

        $metadata['base_version'] = $metadata['base_version'] ?? $baseVersion;

        $pullRequest['metadata'] = $metadata;
        $pullRequest['planned_target_version'] = $targetVersion;
        $pullRequest['planned_release_at'] = $releaseAt;
        $pullRequest['planned_scope'] = $scope;
        $pullRequest['requires_code_update'] = $requiresCodeUpdate;

        return $pullRequest;
    }

    /**
     * @param array<string, mixed> $release
     * @param array<string, mixed> $plannedPr
     * @param list<int> $blockedBy
     */
    private function refreshPullRequest(
        string $currentVersion,
        array $release,
        array $plannedPr,
        array $blockedBy,
        string $defaultBranch,
        string $baseRevision,
    ): bool {
        $metadata = $plannedPr['metadata'];
        $targetVersion = (string) $plannedPr['planned_target_version'];
        $releaseAt = (string) $plannedPr['planned_release_at'];
        $scope = (string) $plannedPr['planned_scope'];
        $branch = (string) ($metadata['branch'] ?? $plannedPr['head']['ref'] ?? '');

        if ($branch === '') {
            throw new RuntimeException(sprintf('Managed core pull request #%d is missing a branch name.', $plannedPr['number']));
        }

        AutomationPullRequestGuard::assertRefreshable($plannedPr, $branch, $defaultBranch, 'Core automation PR');

        $branchGuard = (bool) $plannedPr['requires_code_update'] ? $this->beginBranchRollbackGuard($branch) : null;

        if ($this->pullRequestAlreadySatisfied($currentVersion, $targetVersion)) {
            $this->closeSupersededPullRequest(
                (int) $plannedPr['number'],
                sprintf(
                    'Base branch already contains WordPress core `%s`. This stale automation PR is no longer applicable and has been closed.',
                    $targetVersion
                )
            );
            return false;
        }

        try {
            if ((bool) $plannedPr['requires_code_update']) {
                $paths = [];
                $paths = $this->archiveApplier()->checkoutAndApplyCoreVersion(
                    gitRunner: $this->gitRunner,
                    defaultBranch: $defaultBranch,
                    branch: $branch,
                    downloadUrl: (string) $release['download_url'],
                    targetVersion: $targetVersion
                );
                $changed = $this->gitRunner->commitAndPush($branch, sprintf('Update WordPress core to %s', $targetVersion), $paths);

                if (! $changed) {
                    $this->closeSupersededPullRequest(
                        (int) $plannedPr['number'],
                        sprintf(
                            'Refreshing this WordPress core PR from the latest base branch produced no remaining file changes for `%s`. The PR has been closed as a no-op.',
                            $targetVersion
                        )
                    );

                    if ($branchGuard !== null) {
                        $branchGuard->complete();
                    }

                    return false;
                }
            }

            $releaseForTarget = $this->coreClient->releaseForVersion($targetVersion, $release);
            $labels = $this->releaseClassifier->deriveLabels('source:wordpress.org', $scope, (string) $releaseForTarget['release_text'], []);
            $labels[] = 'automation:dependency-update';
            $labels[] = 'component:wordpress-core';

            if ($blockedBy !== []) {
                $labels[] = 'status:blocked';
            }

            $labels = LabelHelper::normalizeList($labels);
            sort($labels);

            $metadata['kind'] = 'core';
            $metadata['slug'] = 'wordpress-core';
            $metadata['base_branch'] = $defaultBranch;
            $metadata['base_revision'] = $baseRevision;
            $metadata['target_version'] = $targetVersion;
            $metadata['release_at'] = $releaseAt;
            $metadata['scope'] = $scope;
            $metadata['blocked_by'] = $blockedBy;
            $metadata['updated_at'] = gmdate(DATE_ATOM);
            $metadata['trust_state'] = DependencyTrustState::VERIFIED;
            $metadata['trust_details'] = 'WordPress core files were verified against official checksums before apply.';

            $body = $this->prBodyRenderer->renderCoreUpdate(
                currentVersion: (string) $metadata['base_version'],
                targetVersion: $targetVersion,
                releaseScope: $scope,
                releaseAt: $releaseAt,
                labels: $labels,
                releaseUrl: (string) $releaseForTarget['release_url'],
                downloadUrl: (string) $releaseForTarget['download_url'],
                releaseHtml: (string) $releaseForTarget['release_html'],
                metadata: $metadata,
            );

            $this->gitHubClient->updatePullRequest((int) $plannedPr['number'], $this->titleForPullRequest((string) $metadata['base_version'], $targetVersion), $body);
            $this->gitHubClient->setLabels((int) $plannedPr['number'], $labels);
            ManagedPullRequestQueue::syncDraftState($this->gitHubClient, $plannedPr, $blockedBy);

            if ($branchGuard !== null) {
                $branchGuard->complete();
            }

            return true;
        } catch (\Throwable $throwable) {
            if ($branchGuard !== null) {
                $branchGuard->rollback($throwable);
            }

            throw $throwable;
        }
    }

    /**
     * @param array<string, mixed> $release
     * @param list<int> $blockedBy
     */
    private function createPullRequestForLatest(
        string $currentVersion,
        array $release,
        string $scope,
        array $blockedBy,
        string $defaultBranch,
        string $baseRevision,
    ): void {
        $existingPullRequest = $this->findOpenPullRequestForTarget((string) $release['version']);

        if ($existingPullRequest !== null) {
            fwrite(STDOUT, sprintf(
                "Skipping WordPress core PR creation because PR #%d already covers %s.\n",
                (int) $existingPullRequest['number'],
                (string) $release['version']
            ));
            return;
        }

        $branch = $this->newBranchName((string) $release['version']);
        $branchGuard = $this->beginBranchRollbackGuard($branch);

        try {
            $paths = $this->archiveApplier()->checkoutAndApplyCoreVersion(
                gitRunner: $this->gitRunner,
                defaultBranch: $defaultBranch,
                branch: $branch,
                downloadUrl: (string) $release['download_url'],
                targetVersion: (string) $release['version']
            );
            $changed = $this->gitRunner->commitAndPush($branch, sprintf('Update WordPress core to %s', $release['version']), $paths);

            if (! $changed) {
                fwrite(STDOUT, sprintf("Skipping WordPress core PR creation for %s because no file changes were produced.\n", $release['version']));
                $branchGuard->complete();
                return;
            }

            $labels = $this->releaseClassifier->deriveLabels('source:wordpress.org', $scope, (string) $release['release_text'], []);
            $labels[] = 'automation:dependency-update';
            $labels[] = 'component:wordpress-core';

            if ($blockedBy !== []) {
                $labels[] = 'status:blocked';
            }

            $labels = LabelHelper::normalizeList($labels);
            sort($labels);

            $metadata = [
                'kind' => 'core',
                'slug' => 'wordpress-core',
                'branch' => $branch,
                'base_branch' => $defaultBranch,
                'base_revision' => $baseRevision,
                'base_version' => $currentVersion,
                'target_version' => (string) $release['version'],
                'scope' => $scope,
                'release_at' => (string) $release['release_at'],
                'blocked_by' => $blockedBy,
                'updated_at' => gmdate(DATE_ATOM),
                'trust_state' => DependencyTrustState::VERIFIED,
                'trust_details' => 'WordPress core files were verified against official checksums before apply.',
            ];

            $body = $this->prBodyRenderer->renderCoreUpdate(
                currentVersion: $currentVersion,
                targetVersion: (string) $release['version'],
                releaseScope: $scope,
                releaseAt: (string) $release['release_at'],
                labels: $labels,
                releaseUrl: (string) $release['release_url'],
                downloadUrl: (string) $release['download_url'],
                releaseHtml: (string) $release['release_html'],
                metadata: $metadata,
            );

            $pullRequest = $this->gitHubClient->createPullRequest(
                $this->titleForPullRequest($currentVersion, (string) $release['version']),
                $branch,
                $defaultBranch,
                $body,
                $blockedBy !== []
            );

            $this->gitHubClient->setLabels((int) $pullRequest['number'], $labels);
            $branchGuard->complete();
        } catch (\Throwable $throwable) {
            $branchGuard->rollback($throwable);
        }
    }

    private function pullRequestAlreadySatisfied(string $baseVersion, string $targetVersion): bool
    {
        return version_compare($targetVersion, $baseVersion, '<=');
    }

    /**
     * @param list<array<string, mixed>> $plannedPrs
     * @return array{0:list<array<string, mixed>>,1:list<array<string, mixed>>}
     */
    private function partitionPullRequestsByTargetVersion(array $plannedPrs): array
    {
        return ManagedPullRequestCanonicalizer::partitionByTargetVersion($plannedPrs);
    }

    private function closeSupersededPullRequest(int $number, string $reason): void
    {
        fwrite(STDOUT, sprintf("Closing PR #%d: %s\n", $number, $reason));
        $this->gitHubClient->closePullRequest($number, $reason);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findOpenPullRequestForTarget(string $targetVersion): ?array
    {
        $matching = [];

        foreach ($this->existingCorePrs() as $pullRequest) {
            $metadata = $pullRequest['metadata'] ?? [];

            if (($metadata['target_version'] ?? null) === $targetVersion) {
                $pullRequest['planned_target_version'] = (string) ($metadata['target_version'] ?? '');
                $pullRequest['planned_release_at'] = (string) ($metadata['release_at'] ?? '');
                $matching[] = $pullRequest;
            }
        }

        return ManagedPullRequestCanonicalizer::selectCanonical($matching);
    }

    private function titleForPullRequest(string $baseVersion, string $targetVersion): string
    {
        return sprintf('Update WordPress core from %s to %s', $baseVersion, $targetVersion);
    }

    private function newBranchName(string $version): string
    {
        $fragment = preg_replace('/[^a-z0-9]+/i', '-', strtolower('wordpress-core-' . $version . '-' . gmdate('YmdHis')));
        return 'codex/' . trim((string) $fragment, '-');
    }

    private function beginBranchRollbackGuard(string $branch): BranchRollbackGuard
    {
        $guard = new BranchRollbackGuard($this->config->repoRoot, $this->gitRunner);
        $guard->begin();
        $guard->trackBranch($branch);
        return $guard;
    }

    private function archiveApplier(): CoreArchiveApplier
    {
        return $this->archiveApplier ??= new CoreArchiveApplier(
            $this->config,
            $this->coreClient,
            $this->archiveDownloader,
            new RuntimeInspector($this->config->runtime),
        );
    }
}
