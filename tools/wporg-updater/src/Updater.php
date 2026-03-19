<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use DateTimeImmutable;
use RuntimeException;
use ZipArchive;

final class Updater
{
    public function __construct(
        private readonly Config $config,
        private readonly PluginScanner $pluginScanner,
        private readonly WordPressOrgClient $wordPressOrgClient,
        private readonly GitHubReleaseClient $gitHubReleaseClient,
        private readonly SupportForumClient $supportForumClient,
        private readonly ReleaseClassifier $releaseClassifier,
        private readonly PrBodyRenderer $prBodyRenderer,
        private readonly GitHubClient $gitHubClient,
        private readonly GitCommandRunner $gitRunner,
    ) {
    }

    public function sync(): void
    {
        $defaultBranch = $this->config->baseBranch ?? $this->gitHubClient->getDefaultBranch();
        $this->gitHubClient->ensureLabels($this->labelDefinitionsForRun());
        $openPrs = $this->indexManagedPullRequests($this->gitHubClient->listOpenPullRequests());
        $errors = [];

        foreach ($this->config->enabledPlugins() as $pluginConfig) {
            try {
                $this->syncPlugin($pluginConfig, $openPrs[$this->pluginKey($pluginConfig)] ?? [], $defaultBranch);
            } catch (\Throwable $throwable) {
                $errors[] = sprintf('%s: %s', $pluginConfig['slug'], $throwable->getMessage());
                fwrite(STDERR, sprintf("[error] %s\n", end($errors)));
            }
        }

        if ($errors !== []) {
            throw new RuntimeException("Plugin update sync completed with errors:\n- " . implode("\n- ", $errors));
        }
    }

    /**
     * @param array<string, mixed> $pluginConfig
     * @param list<array<string, mixed>> $existingPrs
     */
    private function syncPlugin(array $pluginConfig, array $existingPrs, string $defaultBranch): void
    {
        $pluginState = $this->pluginScanner->inspect($this->config->repoRoot, $pluginConfig);
        $catalog = $this->fetchReleaseCatalog($pluginConfig);
        $latestVersion = (string) $catalog['latest_version'];
        $latestReleaseAt = (string) $catalog['latest_release_at'];

        $plannedPrs = [];

        foreach ($existingPrs as $pr) {
            $plannedPrs[] = $this->planExistingPullRequest($pr, $pluginState['version'], $latestVersion, $latestReleaseAt);
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
                pluginConfig: $pluginConfig,
                pluginState: $pluginState,
                catalog: $catalog,
                plannedPr: $plannedPr,
                blockedBy: $blockedBy,
                defaultBranch: $defaultBranch,
            );
        }

        $highestCoveredVersion = $pluginState['version'];

        foreach ($plannedPrs as $plannedPr) {
            if (version_compare($plannedPr['planned_target_version'], $highestCoveredVersion, '>')) {
                $highestCoveredVersion = $plannedPr['planned_target_version'];
            }
        }

        if (version_compare($latestVersion, $highestCoveredVersion, '>')) {
            $scope = $this->releaseClassifier->classifyScope($highestCoveredVersion, $latestVersion);

            $this->createPullRequestForLatest(
                pluginConfig: $pluginConfig,
                pluginState: $pluginState,
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
     * @param array<string, mixed> $pluginConfig
     * @param array{name:string, version:string, path:string, absolute_path:string, main_file:string} $pluginState
     * @param array<string, mixed> $catalog
     * @param array<string, mixed> $plannedPr
     * @param list<int> $blockedBy
     */
    private function refreshPullRequest(
        array $pluginConfig,
        array $pluginState,
        array $catalog,
        array $plannedPr,
        array $blockedBy,
        string $defaultBranch,
    ): void {
        $metadata = $plannedPr['metadata'];
        $targetVersion = $plannedPr['planned_target_version'];
        $releaseAt = (string) $plannedPr['planned_release_at'];
        $scope = (string) $plannedPr['planned_scope'];
        $branch = (string) ($metadata['branch'] ?? $plannedPr['head']['ref'] ?? '');

        if ($branch === '') {
            throw new RuntimeException(sprintf('Managed pull request #%d is missing a branch name.', $plannedPr['number']));
        }

        $releaseData = $this->releaseDataForVersion($pluginConfig, $catalog, $targetVersion, $releaseAt);

        if ((bool) $plannedPr['requires_code_update']) {
            $this->checkoutAndApplyPluginVersion($defaultBranch, $branch, $pluginConfig, $releaseData);
            $this->gitRunner->commitAndPush(
                $branch,
                sprintf('Update %s to %s', $pluginConfig['slug'], $targetVersion),
                [(string) $pluginConfig['path']]
            );
        }

        $notesText = (string) $releaseData['notes_text'];
        $supportTopics = $this->postReleaseTopicsForExistingPullRequest(
            pluginConfig: $pluginConfig,
            releaseAt: new DateTimeImmutable($releaseAt),
            pullRequest: $plannedPr,
            metadata: $metadata,
        );

        $labels = $this->releaseClassifier->deriveLabels($this->sourceLabel($pluginConfig), $scope, $notesText, $supportTopics);
        $labels = array_values(array_unique(array_merge($labels, (array) ($pluginConfig['extra_labels'] ?? []))));

        if ($blockedBy !== []) {
            $labels[] = 'status:blocked';
        }

        $labels = array_values(array_unique($labels));
        sort($labels);

        $metadata['target_version'] = $targetVersion;
        $metadata['release_at'] = $releaseAt;
        $metadata['scope'] = $scope;
        $metadata['kind'] = 'plugin';
        $metadata['source'] = $this->pluginSource($pluginConfig);
        $metadata['component_key'] = $this->pluginKey($pluginConfig);
        $metadata['blocked_by'] = $blockedBy;
        $metadata['support_synced_at'] = gmdate(DATE_ATOM);
        $metadata['updated_at'] = gmdate(DATE_ATOM);

        $title = $this->titleForPullRequest($pluginState['name'], (string) $metadata['base_version'], $targetVersion);
        $body = $this->renderPluginPullRequest(
            pluginConfig: $pluginConfig,
            pluginState: $pluginState,
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
     * @param array<string, mixed> $pluginConfig
     * @param array{name:string, version:string, path:string, absolute_path:string, main_file:string} $pluginState
     * @param array<string, mixed> $catalog
     * @param list<int> $blockedBy
     */
    private function createPullRequestForLatest(
        array $pluginConfig,
        array $pluginState,
        array $catalog,
        string $latestVersion,
        string $latestReleaseAt,
        string $scope,
        array $blockedBy,
        string $defaultBranch,
    ): void {
        $branch = $this->newBranchName((string) $pluginConfig['slug'], $latestVersion);
        $releaseData = $this->releaseDataForVersion($pluginConfig, $catalog, $latestVersion, $latestReleaseAt);

        $this->checkoutAndApplyPluginVersion($defaultBranch, $branch, $pluginConfig, $releaseData);
        $changed = $this->gitRunner->commitAndPush(
            $branch,
            sprintf('Update %s to %s', $pluginConfig['slug'], $latestVersion),
            [(string) $pluginConfig['path']]
        );

        if (! $changed) {
            fwrite(STDOUT, sprintf(
                "Skipping PR creation for %s because updating to %s produced no file changes.\n",
                $pluginConfig['slug'],
                $latestVersion
            ));
            return;
        }

        $notesText = (string) $releaseData['notes_text'];
        $supportTopics = $this->postReleaseTopicsForNewPullRequest(
            $pluginConfig,
            new DateTimeImmutable($latestReleaseAt),
        );

        $labels = $this->releaseClassifier->deriveLabels($this->sourceLabel($pluginConfig), $scope, $notesText, $supportTopics);
        $labels = array_values(array_unique(array_merge($labels, (array) ($pluginConfig['extra_labels'] ?? []))));

        if ($blockedBy !== []) {
            $labels[] = 'status:blocked';
        }

        $labels = array_values(array_unique($labels));
        sort($labels);

        $metadata = [
            'kind' => 'plugin',
            'source' => $this->pluginSource($pluginConfig),
            'slug' => (string) $pluginConfig['slug'],
            'component_key' => $this->pluginKey($pluginConfig),
            'plugin_path' => $pluginState['path'],
            'branch' => $branch,
            'base_branch' => $defaultBranch,
            'base_version' => $pluginState['version'],
            'target_version' => $latestVersion,
            'scope' => $scope,
            'release_at' => $latestReleaseAt,
            'blocked_by' => $blockedBy,
            'support_synced_at' => gmdate(DATE_ATOM),
            'updated_at' => gmdate(DATE_ATOM),
        ];

        $title = $this->titleForPullRequest($pluginState['name'], $pluginState['version'], $latestVersion);
        $body = $this->renderPluginPullRequest(
            pluginConfig: $pluginConfig,
            pluginState: $pluginState,
            currentVersion: $pluginState['version'],
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
     * @param array<string, mixed> $pluginConfig
     * @param array<string, mixed> $pluginInfo
     */
    private function checkoutAndApplyPluginVersion(
        string $defaultBranch,
        string $branch,
        array $pluginConfig,
        array $releaseData,
    ): void {
        $this->gitRunner->checkoutBranch($defaultBranch, $branch);
        $tempDir = sys_get_temp_dir() . '/wporg-update-' . bin2hex(random_bytes(6));

        if (! mkdir($tempDir, 0777, true) && ! is_dir($tempDir)) {
            throw new RuntimeException(sprintf('Failed to create temp directory: %s', $tempDir));
        }

        $archivePath = $tempDir . '/plugin.zip';
        $extractPath = $tempDir . '/extract';
        mkdir($extractPath, 0777, true);

        try {
            (new HttpClient())->downloadToFile((string) $releaseData['download_url'], $archivePath);

            $zip = new ZipArchive();

            if ($zip->open($archivePath) !== true) {
                throw new RuntimeException(sprintf('Failed to open plugin archive: %s', $archivePath));
            }

            ZipExtractor::extractValidated($zip, $extractPath);
            $zip->close();

            $sourcePath = $this->resolvePluginSourcePath(
                $extractPath,
                trim((string) ($releaseData['archive_subdir'] ?? ''), '/'),
                trim((string) $pluginConfig['main_file'], '/'),
                (string) $pluginConfig['slug'],
            );
            $destinationPath = $this->config->repoRoot . '/' . trim((string) $pluginConfig['path'], '/');

            $this->removeDirectory($destinationPath);
            $this->copyDirectory($sourcePath, $destinationPath);

            $expectedMainFile = $destinationPath . '/' . trim((string) $pluginConfig['main_file'], '/');

            if (! is_file($expectedMainFile)) {
                throw new RuntimeException(sprintf('Updated plugin archive did not contain expected main file %s.', $expectedMainFile));
            }
        } finally {
            $this->removeDirectory($tempDir);
        }
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

            $componentKey = $this->componentKeyFromMetadata($metadata);

            if ($componentKey === null) {
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
    public static function labelDefinitions(): array
    {
        return [
            'automation:plugin-update' => ['color' => '1d76db', 'description' => 'Managed plugin update PR'],
            'component:wordpress-core' => ['color' => '0366d6', 'description' => 'WordPress core update'],
            'source:wordpress.org' => ['color' => '0e8a16', 'description' => 'Update sourced from wordpress.org'],
            'source:github' => ['color' => '24292f', 'description' => 'Update sourced from GitHub releases'],
            'release:patch' => ['color' => '5319e7', 'description' => 'Patch release'],
            'release:minor' => ['color' => 'fbca04', 'description' => 'Minor release'],
            'release:major' => ['color' => 'd93f0b', 'description' => 'Major release'],
            'type:security-bugfix' => ['color' => 'b60205', 'description' => 'Contains security or bugfix work'],
            'type:feature' => ['color' => '0052cc', 'description' => 'Contains feature work'],
            'support:new-topics' => ['color' => 'c2e0c6', 'description' => 'Support topics were opened after the release'],
            'support:regression-signal' => ['color' => 'e99695', 'description' => 'Support topic titles suggest a regression or urgent issue'],
            'status:blocked' => ['color' => 'bfdadc', 'description' => 'Blocked behind an older open update PR for the same plugin'],
        ];
    }

    /**
     * @return array<string, array{color:string, description:string}>
     */
    private function labelDefinitionsForRun(): array
    {
        $definitions = self::labelDefinitions();

        foreach ($this->config->enabledPlugins() as $pluginConfig) {
            foreach ((array) ($pluginConfig['extra_labels'] ?? []) as $label) {
                if (! is_string($label) || $label === '' || isset($definitions[$label])) {
                    continue;
                }

                $definitions[$label] = [
                    'color' => 'ededed',
                    'description' => sprintf('Managed label for %s', (string) $pluginConfig['slug']),
                ];
            }
        }

        return $definitions;
    }

    private function safeChangelogHtml(array $pluginInfo, string $targetVersion): string
    {
        try {
            $changelog = (string) (($pluginInfo['sections']['changelog'] ?? '') ?: '');

            if ($changelog === '') {
                throw new RuntimeException('No changelog section returned by wordpress.org.');
            }

            return $this->wordPressOrgClient->extractChangelogSection($changelog, $targetVersion);
        } catch (\Throwable $throwable) {
            return sprintf('<p><em>Changelog unavailable for version %s: %s</em></p>', $targetVersion, htmlspecialchars($throwable->getMessage(), ENT_QUOTES));
        }
    }

    /**
     * @param array<string, mixed> $pluginConfig
     * @return array<string, mixed>
     */
    private function fetchReleaseCatalog(array $pluginConfig): array
    {
        if ($this->pluginSource($pluginConfig) === 'github') {
            $releases = $this->gitHubReleaseClient->fetchStableReleases($pluginConfig);
            $releasesByVersion = [];

            foreach ($releases as $release) {
                $version = (string) ($release['normalized_version'] ?? '');

                if ($version !== '') {
                    $releasesByVersion[$version] = $release;
                }
            }

            $latest = $releases[0];

            return [
                'source' => 'github',
                'repository' => (string) $pluginConfig['github_repository'],
                'latest_version' => (string) $latest['normalized_version'],
                'latest_release_at' => $this->gitHubReleaseClient->latestReleaseAt($latest),
                'releases_by_version' => $releasesByVersion,
            ];
        }

        $pluginInfo = $this->wordPressOrgClient->fetchPluginInfo((string) $pluginConfig['slug']);

        return [
            'source' => 'wordpress.org',
            'plugin_info' => $pluginInfo,
            'latest_version' => $this->wordPressOrgClient->latestVersion($pluginInfo),
            'latest_release_at' => $this->wordPressOrgClient->latestReleaseAt($pluginInfo),
        ];
    }

    /**
     * @param array<string, mixed> $pluginConfig
     * @param array<string, mixed> $catalog
     * @return array<string, mixed>
     */
    private function releaseDataForVersion(array $pluginConfig, array $catalog, string $targetVersion, string $fallbackReleaseAt): array
    {
        if ($this->pluginSource($pluginConfig) === 'github') {
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
                'source' => 'github',
                'repository' => $repository,
                'release_url' => $this->gitHubReleaseClient->releaseUrl($release, $repository),
                'issues_url' => $this->gitHubReleaseClient->issuesUrl($repository),
                'download_url' => $this->gitHubReleaseClient->downloadUrl($release, $pluginConfig),
                'archive_subdir' => $this->gitHubReleaseClient->archiveSubdir($pluginConfig),
                'notes_markup' => $notesMarkup,
                'notes_text' => $this->gitHubReleaseClient->markdownToText($notesMarkup),
                'release_at' => $this->gitHubReleaseClient->latestReleaseAt($release),
            ];
        }

        $pluginInfo = $catalog['plugin_info'] ?? null;

        if (! is_array($pluginInfo)) {
            throw new RuntimeException(sprintf(
                'Missing wordpress.org catalog metadata for %s.',
                (string) $pluginConfig['slug']
            ));
        }

        $notesMarkup = $this->safeChangelogHtml($pluginInfo, $targetVersion);

        return [
            'source' => 'wordpress.org',
            'plugin_url' => $this->wordPressOrgClient->pluginUrl((string) $pluginConfig['slug']),
            'support_url' => $this->wordPressOrgClient->supportUrl((string) $pluginConfig['slug']),
            'download_url' => $this->wordPressOrgClient->downloadUrlForVersion($pluginInfo, $targetVersion),
            'archive_subdir' => '',
            'notes_markup' => $notesMarkup,
            'notes_text' => $this->wordPressOrgClient->htmlToText($notesMarkup),
            'release_at' => $fallbackReleaseAt,
        ];
    }

    /**
     * @param array<string, mixed> $pluginConfig
     * @param array{name:string, version:string, path:string, absolute_path:string, main_file:string} $pluginState
     * @param list<string> $labels
     * @param list<array{title:string, url:string, opened_at:string}> $supportTopics
     * @param array<string, mixed> $releaseData
     * @param array<string, mixed> $metadata
     */
    private function renderPluginPullRequest(
        array $pluginConfig,
        array $pluginState,
        string $currentVersion,
        string $targetVersion,
        string $scope,
        string $releaseAt,
        array $labels,
        array $releaseData,
        array $supportTopics,
        array $metadata,
    ): string {
        if ($this->pluginSource($pluginConfig) === 'github') {
            return $this->prBodyRenderer->renderGitHubPluginUpdate(
                pluginName: $pluginState['name'],
                pluginSlug: (string) $pluginConfig['slug'],
                pluginPath: $pluginState['path'],
                currentVersion: $currentVersion,
                targetVersion: $targetVersion,
                releaseScope: $scope,
                releaseAt: $releaseAt,
                labels: $labels,
                repository: (string) $releaseData['repository'],
                releaseUrl: (string) $releaseData['release_url'],
                issuesUrl: (string) $releaseData['issues_url'],
                downloadUrl: (string) $releaseData['download_url'],
                releaseNotesMarkdown: (string) $releaseData['notes_markup'],
                metadata: $metadata,
            );
        }

        return $this->prBodyRenderer->render(
            pluginName: $pluginState['name'],
            pluginSlug: (string) $pluginConfig['slug'],
            pluginPath: $pluginState['path'],
            currentVersion: $currentVersion,
            targetVersion: $targetVersion,
            releaseScope: $scope,
            releaseAt: $releaseAt,
            labels: $labels,
            pluginUrl: (string) $releaseData['plugin_url'],
            supportUrl: (string) $releaseData['support_url'],
            changelogHtml: (string) $releaseData['notes_markup'],
            supportTopics: $supportTopics,
            metadata: $metadata,
        );
    }

    /**
     * @param array<string, mixed> $pluginConfig
     * @param array<string, mixed> $pullRequest
     * @param array<string, mixed> $metadata
     * @return list<array{title:string, url:string, opened_at:string}>
     */
    private function postReleaseTopicsForExistingPullRequest(
        array $pluginConfig,
        DateTimeImmutable $releaseAt,
        array $pullRequest,
        array $metadata,
    ): array {
        if ($this->pluginSource($pluginConfig) !== 'wordpress.org') {
            return [];
        }

        $existingTopics = PrBodyRenderer::extractSupportTopics((string) ($pullRequest['body'] ?? ''));
        $lastSyncAt = $metadata['support_synced_at'] ?? $metadata['updated_at'] ?? $metadata['release_at'] ?? null;
        $incrementalWindow = is_string($lastSyncAt) && $lastSyncAt !== '' ? new DateTimeImmutable($lastSyncAt) : null;
        $newTopics = $this->supportForumClient->fetchTopicsOpenedAfter(
            (string) $pluginConfig['slug'],
            $releaseAt,
            $incrementalWindow,
            $this->pluginSupportMaxPages($pluginConfig),
        );

        return $this->mergeSupportTopics($existingTopics, $newTopics);
    }

    /**
     * @param array<string, mixed> $pluginConfig
     * @return list<array{title:string, url:string, opened_at:string}>
     */
    private function postReleaseTopicsForNewPullRequest(array $pluginConfig, DateTimeImmutable $releaseAt): array
    {
        if ($this->pluginSource($pluginConfig) !== 'wordpress.org') {
            return [];
        }

        return $this->supportForumClient->fetchTopicsOpenedAfter(
            (string) $pluginConfig['slug'],
            $releaseAt,
            null,
            $this->pluginSupportMaxPages($pluginConfig),
        );
    }

    /**
     * @param array<string, mixed> $pluginConfig
     */
    private function pluginSupportMaxPages(array $pluginConfig): ?int
    {
        $maxPages = $pluginConfig['support_max_pages'] ?? null;

        return is_int($maxPages) && $maxPages > 0 ? $maxPages : null;
    }

    /**
     * @param array<string, mixed> $pluginConfig
     */
    private function pluginSource(array $pluginConfig): string
    {
        $source = $pluginConfig['source'] ?? 'wordpress.org';
        return is_string($source) && $source !== '' ? $source : 'wordpress.org';
    }

    /**
     * @param array<string, mixed> $pluginConfig
     */
    private function pluginKey(array $pluginConfig): string
    {
        return $this->pluginSource($pluginConfig) . ':' . (string) $pluginConfig['slug'];
    }

    /**
     * @param array<string, mixed> $pluginConfig
     */
    private function sourceLabel(array $pluginConfig): string
    {
        return $this->pluginSource($pluginConfig) === 'github'
            ? 'source:github'
            : 'source:wordpress.org';
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function componentKeyFromMetadata(array $metadata): ?string
    {
        $componentKey = $metadata['component_key'] ?? null;

        if (is_string($componentKey) && $componentKey !== '') {
            return $componentKey;
        }

        $slug = $metadata['slug'] ?? null;

        if (! is_string($slug) || $slug === '') {
            return null;
        }

        $source = $metadata['source'] ?? 'wordpress.org';
        return (is_string($source) && $source !== '' ? $source : 'wordpress.org') . ':' . $slug;
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

        usort($topicsWithDates, static function (array $left, array $right): int {
            return strcmp($right['opened_at'], $left['opened_at']);
        });

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

    private function titleForPullRequest(string $pluginName, string $baseVersion, string $targetVersion): string
    {
        return sprintf('Update %s from %s to %s', $pluginName, $baseVersion, $targetVersion);
    }

    private function resolvePluginSourcePath(
        string $extractPath,
        string $archiveSubdir,
        string $mainFile,
        string $slug,
    ): string
    {
        $entries = array_values(array_filter(scandir($extractPath) ?: [], static function (string $entry): bool {
            return $entry !== '.' && $entry !== '..';
        }));

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

            if (is_file($candidatePath . '/' . $mainFile)) {
                $matches[] = $candidatePath;
            }
        }

        $matches = array_values(array_unique($matches));

        if (count($matches) === 1) {
            return $matches[0];
        }

        if ($matches === []) {
            throw new RuntimeException(sprintf(
                'Could not locate the extracted plugin root for %s. Expected to find %s inside the archive.',
                $slug,
                $mainFile
            ));
        }

        throw new RuntimeException(sprintf(
            'Extracted archive for %s matched multiple candidate plugin roots.',
            $slug
        ));
    }

    private function removeDirectory(string $path): void
    {
        if (! file_exists($path)) {
            return;
        }

        if (is_file($path) || is_link($path)) {
            unlink($path);
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($path);
    }

    private function copyDirectory(string $source, string $destination): void
    {
        if (! is_dir($source)) {
            throw new RuntimeException(sprintf('Source directory does not exist: %s', $source));
        }

        mkdir($destination, 0777, true);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $target = $destination . '/' . $iterator->getSubPathName();

            if ($item->isDir()) {
                if (! is_dir($target)) {
                    mkdir($target, 0777, true);
                }
            } else {
                copy($item->getPathname(), $target);
            }
        }
    }

    private function newBranchName(string $slug, string $targetVersion): string
    {
        $fragment = preg_replace('/[^a-z0-9]+/i', '-', strtolower($slug . '-' . $targetVersion . '-' . gmdate('YmdHis')));
        return 'codex/wporg-' . trim((string) $fragment, '-');
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
