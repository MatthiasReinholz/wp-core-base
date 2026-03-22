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
        private readonly SupportForumClient $supportForumClient,
        private readonly ReleaseClassifier $releaseClassifier,
        private readonly PrBodyRenderer $prBodyRenderer,
        private readonly GitHubClient $gitHubClient,
        private readonly GitCommandRunner $gitRunner,
        private readonly RuntimeInspector $runtimeInspector,
        private readonly ManifestWriter $manifestWriter,
        private readonly HttpClient $httpClient,
    ) {
    }

    public function sync(): void
    {
        $defaultBranch = $this->config->baseBranch() ?? $this->gitHubClient->getDefaultBranch();
        $this->gitHubClient->ensureLabels($this->labelDefinitionsForRun());
        $openPrs = $this->indexManagedPullRequests($this->gitHubClient->listOpenPullRequests());
        $errors = [];

        foreach ($this->config->managedDependencies() as $dependency) {
            try {
                $this->syncDependency($dependency, $openPrs[$dependency['component_key']] ?? [], $defaultBranch);
            } catch (\Throwable $throwable) {
                $errors[] = sprintf('%s: %s', $dependency['component_key'], $throwable->getMessage());
                fwrite(STDERR, sprintf("[error] %s\n", end($errors)));
            }
        }

        if ($errors !== []) {
            throw new RuntimeException("Dependency update sync completed with errors:\n- " . implode("\n- ", $errors));
        }
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
    private function syncDependency(array $dependency, array $existingPrs, string $defaultBranch): void
    {
        $dependencyState = $this->dependencyScanner->inspect($this->config->repoRoot, $dependency);
        $this->assertManagedDependencyChecksum($dependency);
        $catalog = $this->fetchReleaseCatalog($dependency);
        $latestVersion = (string) $catalog['latest_version'];
        $latestReleaseAt = (string) $catalog['latest_release_at'];
        $plannedPrs = [];

        foreach ($existingPrs as $pr) {
            $plannedPrs[] = $this->planExistingPullRequest($pr, $dependencyState['version'], $latestVersion, $latestReleaseAt);
        }

        usort($plannedPrs, fn (array $left, array $right): int => version_compare($left['planned_target_version'], $right['planned_target_version']));

        foreach ($plannedPrs as $index => $plannedPr) {
            $blockedBy = array_values(array_unique(array_merge(
                $this->unresolvedBlockedBy((array) (($plannedPr['metadata']['blocked_by'] ?? []))),
                array_values(array_map(
                    static fn (array $previous): int => (int) $previous['number'],
                    array_slice($plannedPrs, 0, $index)
                ))
            )));

            $this->refreshPullRequest(
                dependency: $dependency,
                dependencyState: $dependencyState,
                catalog: $catalog,
                plannedPr: $plannedPr,
                blockedBy: $blockedBy,
                defaultBranch: $defaultBranch,
            );
        }

        $highestCoveredVersion = $dependencyState['version'];

        foreach ($plannedPrs as $plannedPr) {
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
                blockedBy: array_values(array_map(static fn (array $pr): int => (int) $pr['number'], $plannedPrs)),
                defaultBranch: $defaultBranch,
            );
        }
    }

    /**
     * @param array<string, mixed> $pullRequest
     * @return array<string, mixed>
     */
    private function planExistingPullRequest(array $pullRequest, string $baseVersion, string $latestVersion, string $latestReleaseAt): array
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

        $requiresCodeUpdate = false;

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
    ): void {
        $metadata = $plannedPr['metadata'];
        $targetVersion = (string) $plannedPr['planned_target_version'];
        $releaseAt = (string) $plannedPr['planned_release_at'];
        $scope = (string) $plannedPr['planned_scope'];
        $branch = (string) ($metadata['branch'] ?? $plannedPr['head']['ref'] ?? '');

        if ($branch === '') {
            throw new RuntimeException(sprintf('Managed pull request #%d is missing a branch name.', $plannedPr['number']));
        }

        $releaseData = $this->releaseDataForVersion($dependency, $catalog, $targetVersion, $releaseAt);

        if ((bool) $plannedPr['requires_code_update']) {
            $updatedDependency = $this->checkoutAndApplyDependencyVersion($defaultBranch, $branch, $dependency, $releaseData);
            $dependency = $updatedDependency;
            $this->gitRunner->commitAndPush(
                $branch,
                sprintf('Update %s to %s', $dependency['slug'], $targetVersion),
                [$dependency['path'], $this->relativeManifestPath()]
            );
        }

        $supportTopics = $this->supportTopicsForExistingPullRequest(
            dependency: $dependency,
            releaseAt: new DateTimeImmutable($releaseAt),
            pullRequest: $plannedPr,
            metadata: $metadata,
        );

        $labels = $this->deriveDependencyLabels($dependency, $scope, (string) $releaseData['notes_text'], $supportTopics, $blockedBy);

        $metadata['target_version'] = $targetVersion;
        $metadata['release_at'] = $releaseAt;
        $metadata['scope'] = $scope;
        $metadata['kind'] = $dependency['kind'];
        $metadata['source'] = $dependency['source'];
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
    ): void {
        $branch = $this->newBranchName($dependency['slug'], $dependency['kind'], $latestVersion);
        $releaseData = $this->releaseDataForVersion($dependency, $catalog, $latestVersion, $latestReleaseAt);
        $updatedDependency = $this->checkoutAndApplyDependencyVersion($defaultBranch, $branch, $dependency, $releaseData);
        $changed = $this->gitRunner->commitAndPush(
            $branch,
            sprintf('Update %s to %s', $dependency['slug'], $latestVersion),
            [$dependency['path'], $this->relativeManifestPath()]
        );

        if (! $changed) {
            fwrite(STDOUT, sprintf(
                "Skipping PR creation for %s because updating to %s produced no file changes.\n",
                $dependency['slug'],
                $latestVersion
            ));
            return;
        }

        $supportTopics = $this->supportTopicsForNewPullRequest($updatedDependency, new DateTimeImmutable($latestReleaseAt));
        $labels = $this->deriveDependencyLabels($updatedDependency, $scope, (string) $releaseData['notes_text'], $supportTopics, $blockedBy);
        $metadata = [
            'kind' => $updatedDependency['kind'],
            'source' => $updatedDependency['source'],
            'slug' => (string) $updatedDependency['slug'],
            'component_key' => $updatedDependency['component_key'],
            'dependency_path' => $dependencyState['path'],
            'branch' => $branch,
            'base_branch' => $defaultBranch,
            'base_version' => $dependencyState['version'],
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
    ): array {
        $this->gitRunner->checkoutBranch($defaultBranch, $branch);
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

            [$sanitizePaths, $sanitizeFiles] = $this->translatedManagedSanitizeRulesForDependency($dependency);
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
        if ($dependency['source'] === 'github-release') {
            $this->gitHubReleaseClient->downloadReleaseToFile($releaseData['release'], $dependency, $archivePath);
            return;
        }

        $this->httpClient->downloadToFile((string) $releaseData['download_url'], $archivePath);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchReleaseCatalog(array $dependency): array
    {
        if ($dependency['source'] === 'github-release') {
            $releases = $this->gitHubReleaseClient->fetchStableReleases($dependency);
            $releasesByVersion = [];

            foreach ($releases as $release) {
                $version = (string) ($release['normalized_version'] ?? '');

                if ($version !== '') {
                    $releasesByVersion[$version] = $release;
                }
            }

            $latest = $releases[0];

            return [
                'source' => 'github-release',
                'repository' => $this->gitHubReleaseClient->repository($dependency),
                'latest_version' => (string) $latest['normalized_version'],
                'latest_release_at' => $this->gitHubReleaseClient->latestReleaseAt($latest),
                'releases_by_version' => $releasesByVersion,
            ];
        }

        $info = $this->wordPressOrgClient->fetchComponentInfo((string) $dependency['kind'], (string) $dependency['slug']);

        return [
            'source' => 'wordpress.org',
            'info' => $info,
            'latest_version' => $this->wordPressOrgClient->latestVersion((string) $dependency['kind'], $info),
            'latest_release_at' => $this->wordPressOrgClient->latestReleaseAt((string) $dependency['kind'], $info),
        ];
    }

    /**
     * @param array<string, mixed> $dependency
     * @param array<string, mixed> $catalog
     * @return array<string, mixed>
     */
    private function releaseDataForVersion(array $dependency, array $catalog, string $targetVersion, string $fallbackReleaseAt): array
    {
        if ($dependency['source'] === 'github-release') {
            $repository = (string) $catalog['repository'];
            $release = $catalog['releases_by_version'][$targetVersion] ?? null;

            if (! is_array($release)) {
                throw new RuntimeException(sprintf(
                    'Could not find GitHub release metadata for %s version %s.',
                    $repository,
                    $targetVersion
                ));
            }

            $notesMarkup = $this->gitHubReleaseClient->releaseNotesMarkdown($release, $targetVersion);

            return [
                'source' => 'github-release',
                'version' => $targetVersion,
                'repository' => $repository,
                'release_url' => $this->gitHubReleaseClient->releaseUrl($release, $repository),
                'issues_url' => $this->gitHubReleaseClient->issuesUrl($repository),
                'archive_subdir' => $this->gitHubReleaseClient->archiveSubdir($dependency),
                'notes_markup' => $notesMarkup,
                'notes_text' => $this->gitHubReleaseClient->markdownToText($notesMarkup),
                'release_at' => $this->gitHubReleaseClient->latestReleaseAt($release),
                'release' => $release,
            ];
        }

        $info = $catalog['info'] ?? null;

        if (! is_array($info)) {
            throw new RuntimeException(sprintf('Missing WordPress.org catalog metadata for %s.', (string) $dependency['slug']));
        }

        $notesMarkup = $this->wordPressOrgClient->extractReleaseNotes((string) $dependency['kind'], $info, $targetVersion);

        return [
            'source' => 'wordpress.org',
            'version' => $targetVersion,
            'component_url' => $this->wordPressOrgClient->componentUrl((string) $dependency['kind'], (string) $dependency['slug']),
            'support_url' => $dependency['kind'] === 'plugin' ? $this->wordPressOrgClient->supportUrl((string) $dependency['slug']) : '',
            'download_url' => $this->wordPressOrgClient->downloadUrlForVersion((string) $dependency['kind'], $info, $targetVersion),
            'archive_subdir' => trim((string) $dependency['archive_subdir'], '/'),
            'notes_markup' => $notesMarkup,
            'notes_text' => $this->wordPressOrgClient->htmlToText($notesMarkup),
            'release_at' => $fallbackReleaseAt,
        ];
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
        if ($dependency['source'] === 'github-release') {
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
                sourceDetails: [
                    ['label' => 'Source repository', 'value' => sprintf('[`%s`](https://github.com/%s)', $releaseData['repository'], $releaseData['repository'])],
                    ['label' => 'GitHub release', 'value' => sprintf('[Open](%s)', $releaseData['release_url'])],
                    ['label' => 'Issue tracker', 'value' => sprintf('[Open](%s)', $releaseData['issues_url'])],
                ],
                releaseNotesHeading: 'Release Notes',
                releaseNotesBody: (string) $releaseData['notes_markup'],
                supportTopics: [],
                metadata: $metadata,
            );
        }

        $sourceDetails = [
            ['label' => 'WordPress.org page', 'value' => sprintf('[Open](%s)', $releaseData['component_url'])],
        ];

        if ($dependency['kind'] === 'plugin') {
            $sourceDetails[] = ['label' => 'WordPress.org support forum', 'value' => sprintf('[Open](%s)', $releaseData['support_url'])];
        }

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
            sourceDetails: $sourceDetails,
            releaseNotesHeading: 'Release Notes',
            releaseNotesBody: (string) $releaseData['notes_markup'],
            supportTopics: $supportTopics,
            metadata: $metadata,
        );
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
    ): array {
        if (! $this->supportsForumSync($dependency)) {
            return [];
        }

        $existingTopics = PrBodyRenderer::extractSupportTopics((string) ($pullRequest['body'] ?? ''));
        $lastSyncAt = $metadata['support_synced_at'] ?? $metadata['updated_at'] ?? $metadata['release_at'] ?? null;
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
        $labels = array_values(array_unique(array_merge($labels, (array) ($dependency['extra_labels'] ?? []))));

        if ($blockedBy !== []) {
            $labels[] = 'status:blocked';
        }

        $labels = array_values(array_unique($labels));
        sort($labels);
        return $labels;
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

            $componentKey = $metadata['component_key'] ?? null;

            if (! is_string($componentKey) || $componentKey === '') {
                continue;
            }

            $indexed[$componentKey] ??= [];
            $indexed[$componentKey][] = $pullRequest;
        }

        return $indexed;
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

        return $definitions;
    }

    /**
     * @param array<string, mixed> $dependency
     */
    private function assertManagedDependencyChecksum(array $dependency): void
    {
        $dependencyPath = $this->config->repoRoot . '/' . $dependency['path'];
        [$sanitizePaths, $sanitizeFiles] = $this->translatedManagedSanitizeRulesForDependency($dependency);
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

    /**
     * @param array<string, mixed> $dependency
     * @return array{0:list<string>,1:list<string>}
     */
    private function translatedManagedSanitizeRulesForDependency(array $dependency): array
    {
        $rootPath = (string) $dependency['path'];
        $sanitizePaths = [];

        foreach ((array) $this->config->runtime['managed_sanitize_paths'] as $sanitizePath) {
            if ($sanitizePath === $rootPath) {
                $sanitizePaths[] = '';
                continue;
            }

            if (str_starts_with($sanitizePath, $rootPath . '/')) {
                $sanitizePaths[] = substr($sanitizePath, strlen($rootPath) + 1);
            }
        }

        return [
            array_values(array_unique(array_merge($sanitizePaths, $this->config->dependencySanitizePaths($dependency)))),
            array_values(array_unique(array_merge($this->config->managedSanitizeFiles(), $this->config->dependencySanitizeFiles($dependency)))),
        ];
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

    private function supportsForumSync(array $dependency): bool
    {
        return $dependency['source'] === 'wordpress.org' && $dependency['kind'] === 'plugin';
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
        $entries = array_values(array_filter(scandir($extractPath) ?: [], static fn (string $entry): bool => $entry !== '.' && $entry !== '..'));
        $candidateBases = [$extractPath];

        foreach ($entries as $entry) {
            $candidate = $extractPath . '/' . $entry;

            if (is_dir($candidate)) {
                $candidateBases[] = $candidate;
            }
        }

        $matches = [];

        foreach ($candidateBases as $candidateBase) {
            $candidatePath = $candidateBase;

            if ($archiveSubdir !== '') {
                $candidatePath .= '/' . $archiveSubdir;
            }

            if ($isFile) {
                $candidateFile = $candidatePath . '/' . $expectedEntry;

                if (is_file($candidateFile)) {
                    $matches[] = $candidateFile;
                }

                continue;
            }

            if (is_file($candidatePath . '/' . $expectedEntry)) {
                $matches[] = $candidatePath;
            }
        }

        $matches = array_values(array_unique($matches));

        if (count($matches) === 1) {
            return $matches[0];
        }

        if ($matches === []) {
            throw new RuntimeException(sprintf(
                'Could not locate the extracted dependency payload for %s. Expected to find %s inside the archive.',
                $slug,
                $expectedEntry
            ));
        }

        throw new RuntimeException(sprintf(
            'Extracted archive for %s matched multiple candidate dependency payloads.',
            $slug
        ));
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

                if (! is_string($mergedAt) || $mergedAt === '') {
                    $unresolved[] = $pullRequestNumber;
                }
            } catch (\Throwable) {
                $unresolved[] = $pullRequestNumber;
            }
        }

        return array_values(array_unique($unresolved));
    }
}
