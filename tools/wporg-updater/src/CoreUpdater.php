<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;
use ZipArchive;

final class CoreUpdater
{
    public function __construct(
        private readonly Config $config,
        private readonly CoreScanner $coreScanner,
        private readonly WordPressCoreClient $coreClient,
        private readonly ReleaseClassifier $releaseClassifier,
        private readonly PrBodyRenderer $prBodyRenderer,
        private readonly GitHubClient $gitHubClient,
        private readonly GitCommandRunner $gitRunner,
    ) {
    }

    public function sync(): void
    {
        if (! $this->config->coreConfig()['enabled']) {
            return;
        }

        $defaultBranch = $this->config->baseBranch ?? $this->gitHubClient->getDefaultBranch();
        $this->gitHubClient->ensureLabels(Updater::labelDefinitions());
        $current = $this->coreScanner->inspect($this->config->repoRoot);
        $release = $this->coreClient->fetchLatestStableRelease();
        $existingPrs = $this->existingCorePrs();
        $plannedPrs = [];

        foreach ($existingPrs as $pr) {
            $plannedPrs[] = $this->planExistingPullRequest($pr, $current['version'], (string) $release['version'], (string) $release['release_at']);
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
                currentVersion: $current['version'],
                release: $release,
                plannedPr: $plannedPr,
                blockedBy: $blockedBy,
                defaultBranch: $defaultBranch,
            );
        }

        $highestCoveredVersion = $current['version'];

        foreach ($plannedPrs as $plannedPr) {
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
                blockedBy: array_values(array_map(static fn (array $pr): int => (int) $pr['number'], $plannedPrs)),
                defaultBranch: $defaultBranch,
            );
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function existingCorePrs(): array
    {
        $prs = [];

        foreach ($this->gitHubClient->listOpenPullRequests() as $pullRequest) {
            $metadata = PrBodyRenderer::extractMetadata((string) ($pullRequest['body'] ?? ''));

            if (($metadata['kind'] ?? null) === 'core') {
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
    private function planExistingPullRequest(array $pullRequest, string $baseVersion, string $latestVersion, string $latestReleaseAt): array
    {
        $metadata = $pullRequest['metadata'];
        $targetVersion = (string) ($metadata['target_version'] ?? '');
        $releaseAt = (string) ($metadata['release_at'] ?? '');
        $scope = (string) ($metadata['scope'] ?? 'none');

        if ($targetVersion === '' || $releaseAt === '') {
            throw new RuntimeException(sprintf('Managed core pull request #%d has incomplete metadata.', $pullRequest['number']));
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
    ): void {
        $metadata = $plannedPr['metadata'];
        $targetVersion = (string) $plannedPr['planned_target_version'];
        $releaseAt = (string) $plannedPr['planned_release_at'];
        $scope = (string) $plannedPr['planned_scope'];
        $branch = (string) ($metadata['branch'] ?? $plannedPr['head']['ref'] ?? '');

        if ($branch === '') {
            throw new RuntimeException(sprintf('Managed core pull request #%d is missing a branch name.', $plannedPr['number']));
        }

        if ((bool) $plannedPr['requires_code_update']) {
            $paths = $this->checkoutAndApplyCoreVersion($defaultBranch, $branch, (string) $release['download_url']);
            $this->gitRunner->commitAndPush($branch, sprintf('Update WordPress core to %s', $targetVersion), $paths);
        }

        $releaseForTarget = $this->coreClient->releaseForVersion($targetVersion, $release);
        $labels = $this->releaseClassifier->deriveLabels($scope, (string) $releaseForTarget['release_text'], []);
        $labels[] = 'component:wordpress-core';

        if ($blockedBy !== []) {
            $labels[] = 'status:blocked';
        }

        $labels = array_values(array_unique($labels));
        sort($labels);

        $metadata['kind'] = 'core';
        $metadata['slug'] = 'wordpress-core';
        $metadata['target_version'] = $targetVersion;
        $metadata['release_at'] = $releaseAt;
        $metadata['scope'] = $scope;
        $metadata['blocked_by'] = $blockedBy;
        $metadata['updated_at'] = gmdate(DATE_ATOM);

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
        $this->syncDraftState($plannedPr, $blockedBy);
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
    ): void {
        $branch = $this->newBranchName((string) $release['version']);
        $paths = $this->checkoutAndApplyCoreVersion($defaultBranch, $branch, (string) $release['download_url']);
        $changed = $this->gitRunner->commitAndPush($branch, sprintf('Update WordPress core to %s', $release['version']), $paths);

        if (! $changed) {
            fwrite(STDOUT, sprintf("Skipping WordPress core PR creation for %s because no file changes were produced.\n", $release['version']));
            return;
        }

        $labels = $this->releaseClassifier->deriveLabels($scope, (string) $release['release_text'], []);
        $labels[] = 'component:wordpress-core';

        if ($blockedBy !== []) {
            $labels[] = 'status:blocked';
        }

        $labels = array_values(array_unique($labels));
        sort($labels);

        $metadata = [
            'kind' => 'core',
            'slug' => 'wordpress-core',
            'branch' => $branch,
            'base_branch' => $defaultBranch,
            'base_version' => $currentVersion,
            'target_version' => (string) $release['version'],
            'scope' => $scope,
            'release_at' => (string) $release['release_at'],
            'blocked_by' => $blockedBy,
            'updated_at' => gmdate(DATE_ATOM),
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

                if (! is_string($pullRequest['merged_at'] ?? null) || $pullRequest['merged_at'] === '') {
                    $unresolved[] = $pullRequestNumber;
                }
            } catch (\Throwable) {
                $unresolved[] = $pullRequestNumber;
            }
        }

        return array_values(array_unique($unresolved));
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
     * @return list<string>
     */
    private function checkoutAndApplyCoreVersion(string $defaultBranch, string $branch, string $downloadUrl): array
    {
        $this->gitRunner->checkoutBranch($defaultBranch, $branch);
        $tempDir = sys_get_temp_dir() . '/wp-core-update-' . bin2hex(random_bytes(6));

        if (! mkdir($tempDir, 0777, true) && ! is_dir($tempDir)) {
            throw new RuntimeException(sprintf('Failed to create temp directory: %s', $tempDir));
        }

        $archivePath = $tempDir . '/core.zip';
        $extractPath = $tempDir . '/extract';
        mkdir($extractPath, 0777, true);

        try {
            (new HttpClient())->downloadToFile($downloadUrl, $archivePath);

            $zip = new ZipArchive();

            if ($zip->open($archivePath) !== true) {
                throw new RuntimeException(sprintf('Failed to open core archive: %s', $archivePath));
            }

            ZipExtractor::extractValidated($zip, $extractPath);
            $zip->close();

            $sourceRoot = $extractPath . '/wordpress';

            if (! is_dir($sourceRoot)) {
                throw new RuntimeException('Expected extracted WordPress core archive to contain a wordpress/ root directory.');
            }

            $paths = [];

            foreach (array_values(array_filter(scandir($sourceRoot) ?: [], static fn (string $entry): bool => ! in_array($entry, ['.', '..'], true))) as $entry) {
                $source = $sourceRoot . '/' . $entry;

                if ($entry === 'wp-content') {
                    $paths = array_merge($paths, $this->syncCoreWpContent($source));
                    continue;
                }

                $destination = $this->config->repoRoot . '/' . $entry;
                $this->removePath($destination);
                $this->copyPath($source, $destination);
                $paths[] = $entry;
            }

            return array_values(array_unique($paths));
        } finally {
            $this->removePath($tempDir);
        }
    }

    /**
     * @return list<string>
     */
    private function syncCoreWpContent(string $sourceWpContent): array
    {
        $destinationWpContent = $this->config->repoRoot . '/wp-content';

        if (! is_dir($destinationWpContent)) {
            mkdir($destinationWpContent, 0777, true);
        }

        $paths = [];

        foreach (array_values(array_filter(scandir($sourceWpContent) ?: [], static fn (string $entry): bool => ! in_array($entry, ['.', '..'], true))) as $entry) {
            $source = $sourceWpContent . '/' . $entry;
            $destination = $destinationWpContent . '/' . $entry;

            if (is_dir($source) && in_array($entry, ['plugins', 'themes'], true)) {
                $paths = array_merge($paths, $this->syncBundledDirectory($source, $destination, 'wp-content/' . $entry));
                continue;
            }

            $this->removePath($destination);
            $this->copyPath($source, $destination);
            $paths[] = 'wp-content/' . $entry;
        }

        return $paths;
    }

    /**
     * @return list<string>
     */
    private function syncBundledDirectory(string $sourceDirectory, string $destinationDirectory, string $pathPrefix): array
    {
        if (! is_dir($destinationDirectory)) {
            mkdir($destinationDirectory, 0777, true);
        }

        $paths = [];

        foreach (array_values(array_filter(scandir($sourceDirectory) ?: [], static fn (string $entry): bool => ! in_array($entry, ['.', '..'], true))) as $entry) {
            $source = $sourceDirectory . '/' . $entry;
            $destination = $destinationDirectory . '/' . $entry;
            $this->removePath($destination);
            $this->copyPath($source, $destination);
            $paths[] = $pathPrefix . '/' . $entry;
        }

        return $paths;
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

    private function removePath(string $path): void
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

    private function copyPath(string $source, string $destination): void
    {
        if (is_file($source)) {
            copy($source, $destination);
            return;
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
}
