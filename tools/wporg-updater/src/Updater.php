<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use DateTimeImmutable;
use RuntimeException;
use ZipArchive;

final class Updater
{
    public function __construct(
        private Config $config,
        private readonly DependencyScanner $dependencyScanner,
        private readonly WordPressOrgClient $wordPressOrgClient,
        private readonly GitHubReleaseClient $gitHubReleaseClient,
        private readonly ManagedSourceRegistry $managedSourceRegistry,
        private readonly SupportForumClient $supportForumClient,
        private readonly ReleaseClassifier $releaseClassifier,
        private readonly PrBodyRenderer $prBodyRenderer,
        private readonly GitHubAutomationClient $gitHubClient,
        private readonly GitRunnerInterface $gitRunner,
        private readonly RuntimeInspector $runtimeInspector,
        private readonly ManifestWriter $manifestWriter,
        private readonly HttpClient $httpClient,
        private readonly ?AdminGovernanceExporter $adminGovernanceExporter = null,
    ) {
    }

    /**
     * @return list<string> Non-fatal dependency-level errors that were reported during sync.
     */
    public function sync(): array
    {
        $this->gitRunner->assertCleanWorktree();
        $defaultBranch = $this->config->baseBranch() ?? $this->gitHubClient->getDefaultBranch();
        $baseRevision = $this->gitRunner->remoteRevision($defaultBranch);
        $this->gitHubClient->ensureLabels($this->labelDefinitionsForRun());
        $openPrs = $this->indexManagedPullRequests($this->gitHubClient->listOpenPullRequests());
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
        $catalog = $this->fetchReleaseCatalog($dependency);
        $latestVersion = (string) $catalog['latest_version'];
        $latestReleaseAt = (string) $catalog['latest_release_at'];
        $plannedPrs = [];

        foreach ($existingPrs as $pr) {
            try {
                $plannedPrs[] = $this->planExistingPullRequest($pr, $dependencyState['version'], $latestVersion, $latestReleaseAt, $baseRevision);
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
            $this->closeSupersededPullRequest(
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
            $blockedBy = array_values(array_unique(array_merge(
                $this->unresolvedBlockedBy((array) (($plannedPr['metadata']['blocked_by'] ?? []))),
                array_values(array_map(
                    static fn (array $previous): int => (int) $previous['number'],
                    $activePlannedPrs
                ))
            )));

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
     * @param array<string, mixed> $pullRequest
     * @return array<string, mixed>
     */
    private function planExistingPullRequest(array $pullRequest, string $baseVersion, string $latestVersion, string $latestReleaseAt, string $baseRevision): array
    {
        $metadata = PrBodyRenderer::extractMetadata((string) ($pullRequest['body'] ?? ''));

        if ($metadata === null) {
            throw new RuntimeException(sprintf('Managed pull request #%d is missing metadata.', $pullRequest['number']));
        }

        $targetVersion = (string) ($metadata['target_version'] ?? '');
        $releaseAt = (string) ($metadata['release_at'] ?? '');
        $scope = (string) ($metadata['scope'] ?? 'none');

        if ($targetVersion === '' || $releaseAt === '') {
            throw new RuntimeException(sprintf('Managed pull request #%d has incomplete metadata.', $pullRequest['number']));
        }

        $requiresBranchRefresh = $this->branchRefreshRequired($metadata, $baseRevision);

        if (
            $this->releaseClassifier->samePatchLine($targetVersion, $latestVersion) &&
            version_compare($latestVersion, $targetVersion, '>') &&
            $this->releaseClassifier->classifyScope($targetVersion, $latestVersion) === 'patch'
        ) {
            $targetVersion = $latestVersion;
            $releaseAt = $latestReleaseAt;
            $scope = 'patch';
            $requiresBranchRefresh = true;
        }

        $metadata['base_version'] = $baseVersion;

        $pullRequest['metadata'] = $metadata;
        $pullRequest['planned_target_version'] = $targetVersion;
        $pullRequest['planned_release_at'] = $releaseAt;
        $pullRequest['planned_scope'] = $scope;
        $pullRequest['requires_branch_refresh'] = $requiresBranchRefresh;

        return $pullRequest;
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

        $this->assertRefreshableAutomationPullRequest($plannedPr, $branch, $defaultBranch);

        if ($this->pullRequestAlreadySatisfied($dependencyState['version'], $targetVersion)) {
            $this->closeSupersededPullRequest(
                (int) $plannedPr['number'],
                sprintf(
                    'Base branch already contains `%s` at `%s`. This stale automation PR is no longer applicable and has been closed.',
                    $dependency['component_key'],
                    $targetVersion
                )
            );
            return false;
        }

        $releaseData = $this->releaseDataForVersion($dependency, $catalog, $targetVersion, $releaseAt);
        $forceSupportTopicResync = (string) ($metadata['target_version'] ?? '') !== $targetVersion
            || (string) ($metadata['release_at'] ?? '') !== $releaseAt;
        $branchGuard = (bool) $plannedPr['requires_branch_refresh'] ? $this->beginBranchRollbackGuard($branch) : null;

        try {
            if ((bool) $plannedPr['requires_branch_refresh']) {
                $updatedDependency = $dependency;
                $updatedDependency = $this->checkoutAndApplyDependencyVersion($defaultBranch, $branch, $dependency, $releaseData, true);
                $dependency = $updatedDependency;
                $changed = $this->gitRunner->commitAndPush(
                    $branch,
                    sprintf('Update %s to %s', $dependency['slug'], $targetVersion),
                    $this->commitPathsForDependency($dependency),
                    true
                );
                $dependency = $updatedDependency;

                if (! $changed) {
                    $this->closeSupersededPullRequest(
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

            $supportTopics = $this->supportTopicsForExistingPullRequest(
                dependency: $dependency,
                releaseAt: new DateTimeImmutable($releaseAt),
                pullRequest: $plannedPr,
                metadata: $metadata,
                forceFullWindow: $forceSupportTopicResync,
            );

            $releaseData = $this->normalizedReleaseData($releaseData, $targetVersion);
            $labels = $this->deriveDependencyLabels($dependency, $scope, (string) $releaseData['notes_text'], $supportTopics, $blockedBy);

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

            $title = $this->titleForPullRequest($dependencyState['name'], (string) $metadata['base_version'], $targetVersion);
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

            $this->gitHubClient->updatePullRequest((int) $plannedPr['number'], $title, $body);
            $this->gitHubClient->setLabels((int) $plannedPr['number'], $labels);
            $this->syncDraftState($plannedPr, $blockedBy);

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
        $existingPullRequest = $this->findOpenPullRequestForTarget($dependency['component_key'], $latestVersion);

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
        $releaseData = $this->normalizedReleaseData(
            $this->releaseDataForVersion($dependency, $catalog, $latestVersion, $latestReleaseAt),
            $latestVersion
        );
        $branchGuard = $this->beginBranchRollbackGuard($branch);

        try {
            $updatedDependency = $this->checkoutAndApplyDependencyVersion($defaultBranch, $branch, $dependency, $releaseData);
            $changed = $this->gitRunner->commitAndPush(
                $branch,
                sprintf('Update %s to %s', $dependency['slug'], $latestVersion),
                $this->commitPathsForDependency($dependency)
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

            $supportTopics = $this->supportTopicsForNewPullRequest($updatedDependency, new DateTimeImmutable($latestReleaseAt));
            $labels = $this->deriveDependencyLabels($updatedDependency, $scope, (string) $releaseData['notes_text'], $supportTopics, $blockedBy);
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
            ];

            $title = $this->titleForPullRequest($dependencyState['name'], $dependencyState['version'], $latestVersion);
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

            $pullRequest = $this->gitHubClient->createPullRequest($title, $branch, $defaultBranch, $body, $blockedBy !== []);
            $this->gitHubClient->setLabels((int) $pullRequest['number'], $labels);
            $branchGuard->complete();
        } catch (\Throwable $throwable) {
            $branchGuard->rollback($throwable);
        }
    }

    /**
     * @param array<string, mixed> $dependency
     * @param array<string, mixed> $releaseData
     * @return array<string, mixed>
     */
    private function checkoutAndApplyDependencyVersion(
        string $defaultBranch,
        string $branch,
        array $dependency,
        array $releaseData,
        bool $resetToBase = false,
    ): array {
        $this->gitRunner->checkoutBranch($defaultBranch, $branch, $resetToBase);
        $tempDir = sys_get_temp_dir() . '/wporg-update-' . bin2hex(random_bytes(6));

        if (! mkdir($tempDir, 0775, true) && ! is_dir($tempDir)) {
            throw new RuntimeException(sprintf('Failed to create temp directory: %s', $tempDir));
        }

        $archivePath = $tempDir . '/dependency.zip';
        $extractPath = $tempDir . '/extract';
        mkdir($extractPath, 0775, true);

        try {
            $this->downloadArchiveForRelease($dependency, $releaseData, $archivePath);

            $zip = new ZipArchive();

            if ($zip->open($archivePath) !== true) {
                throw new RuntimeException(sprintf('Failed to open dependency archive: %s', $archivePath));
            }

            ZipExtractor::extractValidated($zip, $extractPath);
            $zip->close();

            $sourcePath = $this->resolveExtractedDependencyPath(
                $extractPath,
                trim((string) ($releaseData['archive_subdir'] ?? ''), '/'),
                $this->expectedArchiveEntry($dependency),
                (string) $dependency['slug'],
                $this->config->isFileKind((string) $dependency['kind']),
            );

            [$sanitizePaths, $sanitizeFiles] = $this->config->managedSanitizeRules($dependency);
            $this->runtimeInspector->stripPath($sourcePath, $sanitizePaths, $sanitizeFiles);
            $this->runtimeInspector->assertPathIsClean(
                $sourcePath,
                (array) $dependency['policy']['allow_runtime_paths'],
                [],
                $sanitizePaths,
                $sanitizeFiles
            );
            $destinationPath = $this->config->repoRoot . '/' . trim((string) $dependency['path'], '/');
            $this->runtimeInspector->clearPath($destinationPath);
            $this->runtimeInspector->copyPath($sourcePath, $destinationPath);

            $expectedMainFile = $this->config->isFileKind((string) $dependency['kind'])
                ? $destinationPath
                : $destinationPath . '/' . trim((string) $dependency['main_file'], '/');

            if (! is_file($expectedMainFile)) {
                throw new RuntimeException(sprintf('Updated archive did not contain expected main file %s.', $expectedMainFile));
            }

            $checksum = $this->runtimeInspector->computeChecksum($destinationPath, [], $sanitizePaths, $sanitizeFiles);
            $this->config = $this->updateDependencyInManifest(
                $dependency['component_key'],
                (string) $releaseData['version'],
                $checksum
            );
            $this->refreshAdminGovernance($this->config);

            return $this->config->dependencyByKey($dependency['component_key']);
        } finally {
            $this->runtimeInspector->clearPath($tempDir);
        }
    }

    /**
     * @param array<string, mixed> $dependency
     * @param array<string, mixed> $releaseData
     */
    private function downloadArchiveForRelease(array $dependency, array $releaseData, string $archivePath): void
    {
        $this->managedSourceRegistry->for($dependency)->downloadReleaseToFile($dependency, $releaseData, $archivePath);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchReleaseCatalog(array $dependency): array
    {
        return $this->managedSourceRegistry->for($dependency)->fetchCatalog($dependency);
    }

    /**
     * @param array<string, mixed> $dependency
     * @param array<string, mixed> $catalog
     * @return array<string, mixed>
     */
    private function releaseDataForVersion(array $dependency, array $catalog, string $targetVersion, string $fallbackReleaseAt): array
    {
        return $this->managedSourceRegistry->for($dependency)->releaseDataForVersion(
            $dependency,
            $catalog,
            $targetVersion,
            $fallbackReleaseAt
        );
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
            supportTopics: $this->supportsForumSync($dependency) ? $supportTopics : [],
            metadata: $metadata,
        );
    }

    /**
     * @param array<string, mixed> $releaseData
     * @return array<string, mixed>
     */
    private function normalizedReleaseData(array $releaseData, string $targetVersion): array
    {
        $notesMarkup = trim((string) ($releaseData['notes_markup'] ?? ''));
        $notesText = trim((string) ($releaseData['notes_text'] ?? ''));
        $hadNotesMarkup = $notesMarkup !== '';

        if ($notesMarkup === '') {
            $notesMarkup = sprintf('_Release notes unavailable for version %s._', $targetVersion);
        }

        if ($notesText === '') {
            if ($hadNotesMarkup) {
                $notesText = trim(
                    preg_replace('/\s+/', ' ', preg_replace('/[`*_>#-]+/', ' ', strip_tags($notesMarkup)) ?? '') ?? ''
                );
            } else {
                $notesText = sprintf('Release notes unavailable for version %s.', $targetVersion);
            }
        }

        if ($notesText === '') {
            $notesText = sprintf('Release notes unavailable for version %s.', $targetVersion);
        }

        $releaseData['notes_markup'] = $notesMarkup;
        $releaseData['notes_text'] = $notesText;

        return $releaseData;
    }

    /**
     * @param array<string, mixed> $dependency
     * @param array<string, mixed> $pullRequest
     * @param array<string, mixed> $metadata
     * @return list<array{title:string, url:string, opened_at:string}>
     */
    private function supportTopicsForExistingPullRequest(
        array $dependency,
        DateTimeImmutable $releaseAt,
        array $pullRequest,
        array $metadata,
        bool $forceFullWindow = false,
    ): array {
        if (! $this->supportsForumSync($dependency)) {
            return [];
        }

        $existingTopics = PrBodyRenderer::extractSupportTopics((string) ($pullRequest['body'] ?? ''));
        $lastSyncAt = $forceFullWindow
            ? null
            : ($metadata['support_synced_at'] ?? $metadata['updated_at'] ?? $metadata['release_at'] ?? null);
        $incrementalWindow = is_string($lastSyncAt) && $lastSyncAt !== '' ? new DateTimeImmutable($lastSyncAt) : null;
        $newTopics = $this->supportForumClient->fetchTopicsOpenedAfter(
            (string) $dependency['slug'],
            $releaseAt,
            $incrementalWindow,
            null,
        );

        return $this->mergeSupportTopics($existingTopics, $newTopics);
    }

    /**
     * @param array<string, mixed> $dependency
     * @return list<array{title:string, url:string, opened_at:string}>
     */
    private function supportTopicsForNewPullRequest(array $dependency, DateTimeImmutable $releaseAt): array
    {
        if (! $this->supportsForumSync($dependency)) {
            return [];
        }

        return $this->supportForumClient->fetchTopicsOpenedAfter(
            (string) $dependency['slug'],
            $releaseAt,
            null,
            null,
        );
    }

    /**
     * @param array<string, mixed> $dependency
     * @param list<array{title:string, url:string, opened_at:string}> $supportTopics
     * @param list<int> $blockedBy
     * @return list<string>
     */
    private function deriveDependencyLabels(array $dependency, string $scope, string $notesText, array $supportTopics, array $blockedBy): array
    {
        $labels = $this->releaseClassifier->deriveLabels(
            'source:' . $dependency['source'],
            $scope,
            $notesText,
            $supportTopics
        );

        $labels[] = 'automation:dependency-update';
        $labels[] = 'kind:' . $dependency['kind'];
        $labels = LabelHelper::normalizeList(array_merge($labels, (array) ($dependency['extra_labels'] ?? [])));

        if ($blockedBy !== []) {
            $labels[] = 'status:blocked';
        }

        $labels = LabelHelper::normalizeList($labels);
        sort($labels);
        return $labels;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function branchRefreshRequired(array $metadata, string $baseRevision): bool
    {
        if ($baseRevision === '') {
            return false;
        }

        $recordedBaseRevision = $metadata['base_revision'] ?? null;

        return ! is_string($recordedBaseRevision)
            || $recordedBaseRevision === ''
            || ! hash_equals($recordedBaseRevision, $baseRevision);
    }

    private function pullRequestAlreadySatisfied(string $baseVersion, string $targetVersion): bool
    {
        return version_compare($targetVersion, $baseVersion, '<=');
    }

    /**
     * @param list<array<string, mixed>> $pullRequests
     * @return array<string, list<array<string, mixed>>>
     */
    private function indexManagedPullRequests(array $pullRequests): array
    {
        $indexed = [];

        foreach ($pullRequests as $pullRequest) {
            $metadata = PrBodyRenderer::extractMetadata((string) ($pullRequest['body'] ?? ''));

            if ($metadata === null) {
                continue;
            }

            $matches = [];

            foreach ($this->config->managedDependencies() as $dependency) {
                if ($this->metadataMatchesDependency($metadata, $dependency)) {
                    $matches[] = $dependency['component_key'];
                }
            }

            $matches = array_values(array_unique($matches));

            if ($matches === []) {
                continue;
            }

            if (count($matches) > 1) {
                fwrite(STDERR, sprintf(
                    "[warn] Ignoring automation PR #%d because its metadata matches multiple managed dependencies after premium-key migration.\n",
                    (int) ($pullRequest['number'] ?? 0)
                ));
                continue;
            }

            if (! $this->isManagedRepositoryPullRequest($pullRequest)) {
                fwrite(STDERR, sprintf(
                    "[warn] Ignoring automation PR #%d because it does not use a same-repository automation branch.\n",
                    (int) ($pullRequest['number'] ?? 0)
                ));
                continue;
            }

            $indexed[$matches[0]] ??= [];
            $indexed[$matches[0]][] = $pullRequest;
        }

        return $indexed;
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
     * @return array<string, array{color:string, description:string}>
     */
    private function labelDefinitionsForRun(): array
    {
        $definitions = self::labelDefinitions();

        foreach ($this->config->dependencies() as $dependency) {
            foreach ((array) ($dependency['extra_labels'] ?? []) as $label) {
                if (! is_string($label) || $label === '' || isset($definitions[$label])) {
                    continue;
                }

                $definitions[$label] = [
                    'color' => 'ededed',
                    'description' => sprintf('Managed label for %s', (string) $dependency['slug']),
                ];
            }
        }

        return LabelHelper::normalizeDefinitions($definitions);
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

    private function updateDependencyInManifest(string $componentKey, string $version, string $checksum): Config
    {
        $dependencies = $this->config->dependencies();

        foreach ($dependencies as $index => $dependency) {
            if ($dependency['component_key'] !== $componentKey) {
                continue;
            }

            $dependencies[$index]['version'] = $version;
            $dependencies[$index]['checksum'] = $checksum;
            $nextConfig = $this->config->withDependencies($dependencies);
            $this->manifestWriter->write($nextConfig);
            return $nextConfig;
        }

        throw new RuntimeException(sprintf('Unable to update manifest for %s.', $componentKey));
    }

    private function relativeManifestPath(): string
    {
        return ltrim(str_replace($this->config->repoRoot, '', $this->config->manifestPath), '/');
    }

    /**
     * @param array<string, mixed> $dependency
     * @return list<string>
     */
    private function commitPathsForDependency(array $dependency): array
    {
        $paths = [$dependency['path'], $this->relativeManifestPath()];

        if ($this->adminGovernanceExporter !== null) {
            $paths[] = FrameworkRuntimeFiles::governanceDataPath($this->config);
        }

        return array_values(array_unique($paths));
    }

    private function refreshAdminGovernance(Config $config): void
    {
        if ($this->adminGovernanceExporter === null) {
            return;
        }

        $this->adminGovernanceExporter->refresh($config);
    }

    private function closeSupersededPullRequest(int $number, string $reason): void
    {
        fwrite(STDOUT, sprintf("Closing PR #%d: %s\n", $number, $reason));
        $this->gitHubClient->closePullRequest($number, $reason);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findOpenPullRequestForTarget(string $componentKey, string $targetVersion): ?array
    {
        $matching = [];

        foreach ($this->gitHubClient->listOpenPullRequests() as $pullRequest) {
            $metadata = PrBodyRenderer::extractMetadata((string) ($pullRequest['body'] ?? ''));

            if ($metadata === null) {
                continue;
            }

            if (! $this->metadataMatchesComponentKey($metadata, $componentKey)) {
                continue;
            }

            if (! $this->isManagedRepositoryPullRequest($pullRequest)) {
                continue;
            }

            if (($metadata['target_version'] ?? null) !== $targetVersion) {
                continue;
            }

            $pullRequest['metadata'] = $metadata;
            $pullRequest['planned_target_version'] = (string) ($metadata['target_version'] ?? '');
            $pullRequest['planned_release_at'] = (string) ($metadata['release_at'] ?? '');
            $matching[] = $pullRequest;
        }

        return ManagedPullRequestCanonicalizer::selectCanonical($matching);
    }

    private function beginBranchRollbackGuard(string $branch): BranchRollbackGuard
    {
        $guard = new BranchRollbackGuard($this->config->repoRoot, $this->gitRunner);
        $guard->begin();
        $guard->trackBranch($branch);
        return $guard;
    }

    /**
     * @param array<string, mixed> $pullRequest
     */
    private function assertRefreshableAutomationPullRequest(array $pullRequest, string $branch, string $defaultBranch): void
    {
        $baseRef = (string) ($pullRequest['base']['ref'] ?? '');

        if ($branch === $defaultBranch || ($baseRef !== '' && $branch === $baseRef)) {
            throw new RuntimeException(sprintf(
                'Automation PR #%d resolved to protected branch %s and will not be refreshed.',
                (int) ($pullRequest['number'] ?? 0),
                $branch
            ));
        }

        if (! $this->isManagedRepositoryPullRequest($pullRequest)) {
            throw new RuntimeException(sprintf(
                'Automation PR #%d does not use a same-repository automation branch and will not be refreshed.',
                (int) ($pullRequest['number'] ?? 0)
            ));
        }
    }

    /**
     * @param array<string, mixed> $pullRequest
     */
    private function isManagedRepositoryPullRequest(array $pullRequest): bool
    {
        $head = is_array($pullRequest['head'] ?? null) ? $pullRequest['head'] : [];
        $base = is_array($pullRequest['base'] ?? null) ? $pullRequest['base'] : [];
        $headRef = (string) ($head['ref'] ?? '');
        $headRepo = is_array($head['repo'] ?? null) ? $head['repo'] : [];
        $baseRepo = is_array($base['repo'] ?? null) ? $base['repo'] : [];
        $headFullName = strtolower((string) ($headRepo['full_name'] ?? ''));
        $baseFullName = strtolower((string) ($baseRepo['full_name'] ?? ''));

        return $headRef !== '' && $headFullName !== '' && $headFullName === $baseFullName;
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $dependency
     */
    private function metadataMatchesDependency(array $metadata, array $dependency): bool
    {
        $componentKey = $metadata['component_key'] ?? null;

        if (is_string($componentKey) && $componentKey !== '' && PremiumSourceResolver::matchesComponentKey($dependency, $componentKey)) {
            return true;
        }

        if (
            (string) ($metadata['kind'] ?? '') !== (string) $dependency['kind']
            || (string) ($metadata['source'] ?? '') !== (string) $dependency['source']
            || (string) ($metadata['slug'] ?? '') !== (string) $dependency['slug']
        ) {
            return false;
        }

        if (! PremiumSourceResolver::isPremiumSource((string) $dependency['source'])) {
            return true;
        }

        $metadataPath = (string) ($metadata['dependency_path'] ?? '');

        if ($metadataPath !== '' && $metadataPath === (string) $dependency['path']) {
            return true;
        }

        $metadataProvider = $metadata['provider'] ?? null;

        return is_string($metadataProvider)
            && $metadataProvider !== ''
            && PremiumSourceResolver::providerForDependency($dependency) === $metadataProvider;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function metadataMatchesComponentKey(array $metadata, string $componentKey): bool
    {
        try {
            $dependency = $this->config->dependencyByKey($componentKey);
        } catch (\Throwable) {
            return false;
        }

        return $this->metadataMatchesDependency($metadata, $dependency);
    }

    private function supportsForumSync(array $dependency): bool
    {
        return $this->managedSourceRegistry->for($dependency)->supportsForumSync($dependency);
    }

    private function titleForPullRequest(string $dependencyName, string $baseVersion, string $targetVersion): string
    {
        return sprintf('Update %s from %s to %s', $dependencyName, $baseVersion, $targetVersion);
    }

    private function resolveExtractedDependencyPath(
        string $extractPath,
        string $archiveSubdir,
        string $expectedEntry,
        string $slug,
        bool $isFile,
    ): string {
        return ExtractedPayloadLocator::locateByExpectedEntry(
            $extractPath,
            $archiveSubdir,
            $expectedEntry,
            $slug,
            $isFile
        );
    }

    private function expectedArchiveEntry(array $dependency): string
    {
        if ($this->config->isFileKind((string) $dependency['kind'])) {
            $mainFile = $dependency['main_file'] ?? null;

            return is_string($mainFile) && $mainFile !== ''
                ? trim($mainFile, '/')
                : basename((string) $dependency['path']);
        }

        return trim((string) $dependency['main_file'], '/');
    }

    private function newBranchName(string $slug, string $kind, string $targetVersion): string
    {
        $fragment = preg_replace('/[^a-z0-9]+/i', '-', strtolower($kind . '-' . $slug . '-' . $targetVersion . '-' . gmdate('YmdHis')));
        return 'codex/wporg-' . trim((string) $fragment, '-');
    }

    /**
     * @param list<array{title:string, url:string, opened_at:string}> $existingTopics
     * @param list<array{title:string, url:string, opened_at:string}> $newTopics
     * @return list<array{title:string, url:string, opened_at:string}>
     */
    private function mergeSupportTopics(array $existingTopics, array $newTopics): array
    {
        $merged = [];

        foreach ($newTopics as $topic) {
            $merged[$topic['url']] = $topic;
        }

        foreach ($existingTopics as $topic) {
            if (! isset($merged[$topic['url']])) {
                $merged[$topic['url']] = $topic;
            }
        }

        $topicsWithDates = [];
        $topicsWithoutDates = [];

        foreach (array_values($merged) as $topic) {
            if (($topic['opened_at'] ?? '') === '') {
                $topicsWithoutDates[] = $topic;
                continue;
            }

            $topicsWithDates[] = $topic;
        }

        usort($topicsWithDates, static fn (array $left, array $right): int => strcmp($right['opened_at'], $left['opened_at']));

        return array_values(array_merge($topicsWithDates, $topicsWithoutDates));
    }

    /**
     * @param array<string, mixed> $pullRequest
     * @param list<int> $blockedBy
     */
    private function syncDraftState(array $pullRequest, array $blockedBy): void
    {
        $nodeId = (string) ($pullRequest['node_id'] ?? '');

        if ($nodeId === '') {
            return;
        }

        $isDraft = (bool) ($pullRequest['draft'] ?? false);

        if ($blockedBy !== [] && ! $isDraft) {
            $this->gitHubClient->convertToDraft($nodeId);
            return;
        }

        if ($blockedBy === [] && $isDraft) {
            $this->gitHubClient->markReadyForReview($nodeId);
        }
    }

    /**
     * @param list<mixed> $blockedBy
     * @return list<int>
     */
    private function unresolvedBlockedBy(array $blockedBy): array
    {
        $unresolved = [];

        foreach ($blockedBy as $number) {
            if (! is_int($number) && ! ctype_digit((string) $number)) {
                continue;
            }

            $pullRequestNumber = (int) $number;

            try {
                $pullRequest = $this->gitHubClient->getPullRequest($pullRequestNumber);
                $mergedAt = $pullRequest['merged_at'] ?? null;
                $state = (string) ($pullRequest['state'] ?? '');

                if ($state === 'open' && (! is_string($mergedAt) || $mergedAt === '')) {
                    $unresolved[] = $pullRequestNumber;
                }
            } catch (\Throwable) {
                $unresolved[] = $pullRequestNumber;
            }
        }

        return array_values(array_unique($unresolved));
    }
}
