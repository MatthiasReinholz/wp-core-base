<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use DateTimeImmutable;
use RuntimeException;

final class Updater
{
    /** @var array<string, DependencyTrustRecord> */
    private array $lastRunTrustStates = [];
    private ?ManagedDependencyPullRequestPlanner $pullRequestPlanner = null;
    private ?ManagedDependencyReleaseResolver $releaseResolver = null;
    private ?ManagedDependencyInstaller $dependencyInstaller = null;

    public function __construct(
        private Config $config,
        private readonly DependencyScanner $dependencyScanner,
        private readonly GitHubReleaseClient $gitHubReleaseClient,
        private readonly ManagedSourceRegistry $managedSourceRegistry,
        private readonly SupportForumClient $supportForumClient,
        private readonly ReleaseClassifier $releaseClassifier,
        private readonly PrBodyRenderer $prBodyRenderer,
        private readonly GitHubAutomationClient $gitHubClient,
        private readonly GitRunnerInterface $gitRunner,
        private readonly RuntimeInspector $runtimeInspector,
        private readonly ManifestWriter $manifestWriter,
        private readonly ?AdminGovernanceExporter $adminGovernanceExporter = null,
    ) {
    }

    /**
     * @return list<string> Non-fatal dependency-level errors that were reported during sync.
     */
    public function sync(): array
    {
        $this->lastRunTrustStates = [];
        $this->gitRunner->assertCleanWorktree();
        $defaultBranch = $this->config->baseBranch() ?? $this->gitHubClient->getDefaultBranch();
        $baseRevision = $this->gitRunner->remoteRevision($defaultBranch);
        $this->gitHubClient->ensureLabels($this->pullRequestPlanner()->labelDefinitionsForRun(self::labelDefinitions()));
        $openPrs = $this->pullRequestPlanner()->indexManagedPullRequests($this->gitHubClient->listOpenPullRequests('automation:dependency-update'));
        $errors = [];

        foreach ($this->config->managedDependencies() as $dependency) {
            try {
                $this->syncDependency($dependency, $openPrs[$dependency['component_key']] ?? [], $defaultBranch, $baseRevision);
            } catch (\Throwable $throwable) {
                $errors[] = OutputRedactor::redact(sprintf('%s: %s', $dependency['component_key'], $throwable->getMessage()));
                fwrite(STDERR, sprintf("[warn] %s\n", end($errors)));
            }
        }

        if ($errors !== []) {
            fwrite(
                STDERR,
                "[warn] Dependency sync completed with non-fatal errors. Healthy dependencies were still processed.\n"
            );
        }

        return $errors;
    }

    /**
     * @return list<array{component_key:string,target_version:string,trust_state:string,trust_details:string}>
     */
    public function lastRunTrustStates(): array
    {
        return array_values(array_map(
            static fn (DependencyTrustRecord $record): array => $record->toArray(),
            $this->lastRunTrustStates
        ));
    }

    /**
     * @return array<string, array{color:string, description:string}>
     */
    public static function labelDefinitions(): array
    {
        return [
            'automation:dependency-update' => ['color' => '1d76db', 'description' => 'Managed dependency update PR'],
            'component:wordpress-core' => ['color' => '0366d6', 'description' => 'WordPress core update'],
            'kind:plugin' => ['color' => '0e8a16', 'description' => 'Plugin dependency'],
            'kind:theme' => ['color' => '5319e7', 'description' => 'Theme dependency'],
            'kind:mu-plugin-package' => ['color' => 'fbca04', 'description' => 'MU plugin package dependency'],
            'kind:mu-plugin-file' => ['color' => 'c5def5', 'description' => 'MU plugin file dependency'],
            'kind:runtime-file' => ['color' => 'bfd4f2', 'description' => 'Runtime file dependency'],
            'kind:runtime-directory' => ['color' => 'd4c5f9', 'description' => 'Runtime directory dependency'],
            'source:wordpress.org' => ['color' => '0e8a16', 'description' => 'Update sourced from WordPress.org'],
            'source:github-release' => ['color' => '24292f', 'description' => 'Update sourced from GitHub releases'],
            'source:premium' => ['color' => '7c3aed', 'description' => 'Update sourced from a premium provider integration'],
            'release:patch' => ['color' => '5319e7', 'description' => 'Patch release'],
            'release:minor' => ['color' => 'fbca04', 'description' => 'Minor release'],
            'release:major' => ['color' => 'd93f0b', 'description' => 'Major release'],
            'type:security-bugfix' => ['color' => 'b60205', 'description' => 'Contains security or bugfix work'],
            'type:feature' => ['color' => '0052cc', 'description' => 'Contains feature work'],
            'support:new-topics' => ['color' => 'c2e0c6', 'description' => 'Support topics were opened after the release'],
            'support:regression-signal' => ['color' => 'e99695', 'description' => 'Support topic titles suggest a regression or urgent issue'],
            'status:blocked' => ['color' => 'bfdadc', 'description' => 'Blocked behind an older open update PR for the same dependency'],
        ];
    }

    /**
     * @param array<string, mixed> $dependency
     * @param list<array<string, mixed>> $existingPrs
     */
    private function syncDependency(array $dependency, array $existingPrs, string $defaultBranch, string $baseRevision): void
    {
        $dependencyState = $this->dependencyScanner->inspect($this->config->repoRoot, $dependency);
        $this->assertManagedDependencyChecksum($dependency);
        $catalog = $this->releaseResolver()->fetchReleaseCatalog($dependency);
        $latestVersion = (string) $catalog['latest_version'];
        $latestReleaseAt = (string) $catalog['latest_release_at'];
        $plannedPrs = [];

        foreach ($existingPrs as $pr) {
            try {
                $plannedPrs[] = $this->pullRequestPlanner()->planExistingPullRequest(
                    $pr,
                    $dependencyState['version'],
                    $latestVersion,
                    $latestReleaseAt,
                    $baseRevision
                );
            } catch (\Throwable $throwable) {
                fwrite(STDERR, sprintf(
                    "[warn] Ignoring malformed automation PR #%d for %s: %s\n",
                    (int) ($pr['number'] ?? 0),
                    $dependency['component_key'],
                    OutputRedactor::redact($throwable->getMessage())
                ));
            }
        }

        [$plannedPrs, $duplicatePrs] = $this->partitionPullRequestsByTargetVersion($plannedPrs);

        foreach ($duplicatePrs as $duplicatePr) {
            $this->pullRequestPlanner()->closeSupersededPullRequest(
                (int) $duplicatePr['number'],
                sprintf(
                    'Another automation PR already covers `%s` at `%s`. This duplicate PR is being closed to keep one live PR per dependency/version pair.',
                    $dependency['component_key'],
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
                dependency: $dependency,
                dependencyState: $dependencyState,
                catalog: $catalog,
                plannedPr: $plannedPr,
                blockedBy: $blockedBy,
                defaultBranch: $defaultBranch,
                baseRevision: $baseRevision,
            )) {
                $activePlannedPrs[] = $plannedPr;
            }
        }

        $highestCoveredVersion = $dependencyState['version'];

        foreach ($activePlannedPrs as $plannedPr) {
            if (version_compare($plannedPr['planned_target_version'], $highestCoveredVersion, '>')) {
                $highestCoveredVersion = $plannedPr['planned_target_version'];
            }
        }

        if (version_compare($latestVersion, $highestCoveredVersion, '>')) {
            $scope = $this->releaseClassifier->classifyScope($highestCoveredVersion, $latestVersion);

            $this->createPullRequestForLatest(
                dependency: $dependency,
                dependencyState: $dependencyState,
                catalog: $catalog,
                latestVersion: $latestVersion,
                latestReleaseAt: $latestReleaseAt,
                scope: $scope,
                blockedBy: array_values(array_map(static fn (array $pr): int => (int) $pr['number'], $activePlannedPrs)),
                defaultBranch: $defaultBranch,
                baseRevision: $baseRevision,
            );
        }
    }

    /**
     * @param array<string, mixed> $dependency
     * @param array<string, mixed> $catalog
     * @param array<string, mixed> $plannedPr
     * @param list<int> $blockedBy
     * @param array{name:string, version:string, path:string, absolute_path:string, main_file:string, kind:string} $dependencyState
     */
    private function refreshPullRequest(
        array $dependency,
        array $dependencyState,
        array $catalog,
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
            throw new RuntimeException(sprintf('Managed pull request #%d is missing a branch name.', $plannedPr['number']));
        }

        AutomationPullRequestGuard::assertRefreshable($plannedPr, $branch, $defaultBranch, 'Automation PR');

        if ($this->pullRequestAlreadySatisfied($dependencyState['version'], $targetVersion)) {
            $this->pullRequestPlanner()->closeSupersededPullRequest(
                (int) $plannedPr['number'],
                sprintf(
                    'Base branch already contains `%s` at `%s`. This stale automation PR is no longer applicable and has been closed.',
                    $dependency['component_key'],
                    $targetVersion
                )
            );
            return false;
        }

        $releaseData = $this->releaseResolver()->releaseDataForVersion($dependency, $catalog, $targetVersion, $releaseAt);
        $forceSupportTopicResync = (string) ($metadata['target_version'] ?? '') !== $targetVersion
            || (string) ($metadata['release_at'] ?? '') !== $releaseAt;
        $branchGuard = (bool) $plannedPr['requires_branch_refresh'] ? $this->beginBranchRollbackGuard($branch) : null;

        try {
            if ((bool) $plannedPr['requires_branch_refresh']) {
                $installResult = $this->dependencyInstaller()->checkoutAndApplyDependencyVersion(
                    $this->config,
                    $defaultBranch,
                    $branch,
                    $dependency,
                    $releaseData,
                    true
                );
                $this->config = $installResult['config'];
                $updatedDependency = $installResult['dependency'];
                $dependency = $updatedDependency;
                $changed = $this->gitRunner->commitAndPush(
                    $branch,
                    sprintf('Update %s to %s', $dependency['slug'], $targetVersion),
                    $this->dependencyInstaller()->commitPathsForDependency($this->config, $dependency),
                    true
                );
                $dependency = $updatedDependency;

                if (! $changed) {
                    $this->pullRequestPlanner()->closeSupersededPullRequest(
                        (int) $plannedPr['number'],
                        sprintf(
                            'Refreshing this automation PR from the latest base branch produced no remaining file changes for `%s` at `%s`. The PR has been closed as a no-op.',
                            $dependency['component_key'],
                            $targetVersion
                        )
                    );

                    if ($branchGuard !== null) {
                        $branchGuard->complete();
                    }

                    return false;
                }
            }

            $supportTopics = $this->pullRequestPlanner()->supportTopicsForExistingPullRequest(
                dependency: $dependency,
                releaseAt: new DateTimeImmutable($releaseAt),
                pullRequest: $plannedPr,
                metadata: $metadata,
                forceFullWindow: $forceSupportTopicResync,
            );

            $labels = $this->pullRequestPlanner()->deriveDependencyLabels(
                $dependency,
                $scope,
                (string) $releaseData['notes_text'],
                $supportTopics,
                $blockedBy
            );

            $metadata['base_branch'] = $defaultBranch;
            $metadata['base_version'] = $dependencyState['version'];
            $metadata['base_revision'] = $baseRevision;
            $metadata['target_version'] = $targetVersion;
            $metadata['release_at'] = $releaseAt;
            $metadata['scope'] = $scope;
            $metadata['kind'] = $dependency['kind'];
            $metadata['source'] = $dependency['source'];
            $metadata['provider'] = PremiumSourceResolver::providerForDependency($dependency);
            $metadata['component_key'] = $dependency['component_key'];
            $metadata['blocked_by'] = $blockedBy;
            $metadata['support_synced_at'] = gmdate(DATE_ATOM);
            $metadata['updated_at'] = gmdate(DATE_ATOM);
            $metadata['trust_state'] = (string) ($releaseData['trust_state'] ?? DependencyTrustState::METADATA_ONLY);
            $metadata['trust_details'] = (string) ($releaseData['trust_details'] ?? 'Archive authenticity was not independently verified.');

            $title = $this->pullRequestPlanner()->titleForPullRequest($dependencyState['name'], (string) $metadata['base_version'], $targetVersion);
            $body = $this->renderDependencyPullRequest(
                dependency: $dependency,
                dependencyState: $dependencyState,
                currentVersion: (string) $metadata['base_version'],
                targetVersion: $targetVersion,
                scope: $scope,
                releaseAt: $releaseAt,
                labels: $labels,
                releaseData: $releaseData,
                supportTopics: $supportTopics,
                metadata: $metadata,
            );
            $this->recordTrustState($dependency, $targetVersion, $metadata);

            $this->gitHubClient->updatePullRequest((int) $plannedPr['number'], $title, $body);
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
     * @param array<string, mixed> $dependency
     * @param array<string, mixed> $catalog
     * @param array{name:string, version:string, path:string, absolute_path:string, main_file:string, kind:string} $dependencyState
     * @param list<int> $blockedBy
     */
    private function createPullRequestForLatest(
        array $dependency,
        array $dependencyState,
        array $catalog,
        string $latestVersion,
        string $latestReleaseAt,
        string $scope,
        array $blockedBy,
        string $defaultBranch,
        string $baseRevision,
    ): void {
        $existingPullRequest = $this->pullRequestPlanner()->findOpenPullRequestForTarget($dependency['component_key'], $latestVersion);

        if ($existingPullRequest !== null) {
            fwrite(STDOUT, sprintf(
                "Skipping PR creation for %s because PR #%d already covers %s.\n",
                $dependency['slug'],
                (int) $existingPullRequest['number'],
                $latestVersion
            ));
            return;
        }

        $branch = $this->newBranchName($dependency['slug'], $dependency['kind'], $latestVersion);
        $releaseData = $this->releaseResolver()->releaseDataForVersion($dependency, $catalog, $latestVersion, $latestReleaseAt);
        $branchGuard = $this->beginBranchRollbackGuard($branch);

        try {
            $installResult = $this->dependencyInstaller()->checkoutAndApplyDependencyVersion(
                $this->config,
                $defaultBranch,
                $branch,
                $dependency,
                $releaseData
            );
            $this->config = $installResult['config'];
            $updatedDependency = $installResult['dependency'];
            $changed = $this->gitRunner->commitAndPush(
                $branch,
                sprintf('Update %s to %s', $dependency['slug'], $latestVersion),
                $this->dependencyInstaller()->commitPathsForDependency($this->config, $updatedDependency)
            );

            if (! $changed) {
                fwrite(STDOUT, sprintf(
                    "Skipping PR creation for %s because updating to %s produced no file changes.\n",
                    $dependency['slug'],
                    $latestVersion
                ));
                $branchGuard->complete();
                return;
            }

            $supportTopics = $this->pullRequestPlanner()->supportTopicsForNewPullRequest($updatedDependency, new DateTimeImmutable($latestReleaseAt));
            $labels = $this->pullRequestPlanner()->deriveDependencyLabels(
                $updatedDependency,
                $scope,
                (string) $releaseData['notes_text'],
                $supportTopics,
                $blockedBy
            );
            $metadata = [
                'kind' => $updatedDependency['kind'],
                'source' => $updatedDependency['source'],
                'provider' => PremiumSourceResolver::providerForDependency($updatedDependency),
                'slug' => (string) $updatedDependency['slug'],
                'component_key' => $updatedDependency['component_key'],
                'dependency_path' => $dependencyState['path'],
                'branch' => $branch,
                'base_branch' => $defaultBranch,
                'base_version' => $dependencyState['version'],
                'base_revision' => $baseRevision,
                'target_version' => $latestVersion,
                'scope' => $scope,
                'release_at' => $latestReleaseAt,
                'blocked_by' => $blockedBy,
                'support_synced_at' => gmdate(DATE_ATOM),
                'updated_at' => gmdate(DATE_ATOM),
                'trust_state' => (string) ($releaseData['trust_state'] ?? DependencyTrustState::METADATA_ONLY),
                'trust_details' => (string) ($releaseData['trust_details'] ?? 'Archive authenticity was not independently verified.'),
            ];

            $title = $this->pullRequestPlanner()->titleForPullRequest($dependencyState['name'], $dependencyState['version'], $latestVersion);
            $body = $this->renderDependencyPullRequest(
                dependency: $updatedDependency,
                dependencyState: $dependencyState,
                currentVersion: $dependencyState['version'],
                targetVersion: $latestVersion,
                scope: $scope,
                releaseAt: $latestReleaseAt,
                labels: $labels,
                releaseData: $releaseData,
                supportTopics: $supportTopics,
                metadata: $metadata,
            );
            $this->recordTrustState($updatedDependency, $latestVersion, $metadata);

            $pullRequest = $this->gitHubClient->createPullRequest($title, $branch, $defaultBranch, $body, $blockedBy !== []);
            $this->gitHubClient->setLabels((int) $pullRequest['number'], $labels);
            $branchGuard->complete();
        } catch (\Throwable $throwable) {
            $branchGuard->rollback($throwable);
        }
    }

    /**
     * @param array<string, mixed> $dependency
     * @param list<string> $labels
     * @param list<array{title:string, url:string, opened_at:string}> $supportTopics
     * @param array<string, mixed> $releaseData
     * @param array<string, mixed> $metadata
     * @param array{name:string, version:string, path:string, absolute_path:string, main_file:string, kind:string} $dependencyState
     */
    private function renderDependencyPullRequest(
        array $dependency,
        array $dependencyState,
        string $currentVersion,
        string $targetVersion,
        string $scope,
        string $releaseAt,
        array $labels,
        array $releaseData,
        array $supportTopics,
        array $metadata,
    ): string {
        return $this->prBodyRenderer->renderDependencyUpdate(
            dependencyName: $dependencyState['name'],
            dependencySlug: (string) $dependency['slug'],
            dependencyKind: (string) $dependency['kind'],
            dependencyPath: $dependencyState['path'],
            currentVersion: $currentVersion,
            targetVersion: $targetVersion,
            releaseScope: $scope,
            releaseAt: $releaseAt,
            labels: $labels,
            sourceDetails: (array) ($releaseData['source_details'] ?? []),
            releaseNotesHeading: 'Release Notes',
            releaseNotesBody: (string) $releaseData['notes_markup'],
            supportTopics: $this->managedSourceRegistry->for($dependency)->supportsForumSync($dependency) ? $supportTopics : [],
            metadata: $metadata,
        );
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

    /**
     * @param array<string, mixed> $dependency
     */
    private function assertManagedDependencyChecksum(array $dependency): void
    {
        $dependencyPath = $this->config->repoRoot . '/' . $dependency['path'];
        [$sanitizePaths, $sanitizeFiles] = $this->config->managedSanitizeRules($dependency);
        $this->runtimeInspector->assertPathIsClean(
            $dependencyPath,
            (array) $dependency['policy']['allow_runtime_paths'],
            [],
            $sanitizePaths,
            $sanitizeFiles
        );
        $checksum = $this->runtimeInspector->computeChecksum($dependencyPath, [], $sanitizePaths, $sanitizeFiles);

        if ($checksum !== $dependency['checksum']) {
            throw new RuntimeException(sprintf(
                'Managed dependency checksum mismatch for %s. Expected %s but found %s.',
                $dependency['slug'],
                $dependency['checksum'],
                $checksum
            ));
        }
    }

    private function beginBranchRollbackGuard(string $branch): BranchRollbackGuard
    {
        $guard = new BranchRollbackGuard($this->config->repoRoot, $this->gitRunner);
        $guard->begin();
        $guard->trackBranch($branch);
        return $guard;
    }

    private function newBranchName(string $slug, string $kind, string $targetVersion): string
    {
        $fragment = preg_replace('/[^a-z0-9]+/i', '-', strtolower($kind . '-' . $slug . '-' . $targetVersion . '-' . gmdate('YmdHis')));
        return 'codex/wporg-' . trim((string) $fragment, '-');
    }

    /**
     * @param array<string, mixed> $dependency
     * @param array<string, mixed> $metadata
     */
    private function recordTrustState(array $dependency, string $targetVersion, array $metadata): void
    {
        $componentKey = (string) ($dependency['component_key'] ?? '');

        if ($componentKey === '') {
            return;
        }

        $this->lastRunTrustStates[$componentKey] = DependencyTrustRecord::fromMetadata(
            $componentKey,
            $targetVersion,
            $metadata
        );
    }

    private function pullRequestPlanner(): ManagedDependencyPullRequestPlanner
    {
        return $this->pullRequestPlanner ??= new ManagedDependencyPullRequestPlanner(
            $this->config,
            $this->managedSourceRegistry,
            $this->supportForumClient,
            $this->releaseClassifier,
            $this->gitHubClient,
        );
    }

    private function releaseResolver(): ManagedDependencyReleaseResolver
    {
        return $this->releaseResolver ??= new ManagedDependencyReleaseResolver(
            $this->config,
            $this->gitHubReleaseClient,
            $this->managedSourceRegistry,
        );
    }

    private function dependencyInstaller(): ManagedDependencyInstaller
    {
        return $this->dependencyInstaller ??= new ManagedDependencyInstaller(
            $this->gitRunner,
            $this->runtimeInspector,
            $this->manifestWriter,
            $this->managedSourceRegistry,
            $this->adminGovernanceExporter,
        );
    }

}
