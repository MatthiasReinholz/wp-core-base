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
        private readonly AutomationClient $automationClient,
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
        $defaultBranch = $this->config->baseBranch() ?? $this->automationClient->getDefaultBranch();
        $baseRevision = $this->gitRunner->remoteRevision($defaultBranch);
        $this->automationClient->ensureLabels(Updater::labelDefinitions());
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
                $this->automationClient,
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

        foreach ($this->automationClient->listOpenPullRequests('automation:dependency-update') as $pullRequest) {
            $metadata = PrBodyRenderer::extractMetadata((string) ($pullRequest['body'] ?? ''));

            if (($metadata['kind'] ?? null) === 'core' && $this->isManagedRepositoryPullRequest($pullRequest)) {
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

        $requiresCodeUpdate = $this->branchRefreshRequired($metadata, $baseRevision);

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

        $this->assertRefreshableAutomationPullRequest($plannedPr, $branch, $defaultBranch);

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
                $paths = $this->checkoutAndApplyCoreVersion(
                    $defaultBranch,
                    $branch,
                    (string) $release['download_url'],
                    $targetVersion
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

            $this->automationClient->updatePullRequest((int) $plannedPr['number'], $this->titleForPullRequest((string) $metadata['base_version'], $targetVersion), $body);
            $this->automationClient->setPullRequestLabels((int) $plannedPr['number'], $labels);
            ManagedPullRequestQueue::syncDraftState($this->automationClient, $plannedPr, $blockedBy);

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
            $paths = $this->checkoutAndApplyCoreVersion(
                $defaultBranch,
                $branch,
                (string) $release['download_url'],
                (string) $release['version']
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

            $pullRequest = $this->automationClient->createPullRequest(
                $this->titleForPullRequest($currentVersion, (string) $release['version']),
                $branch,
                $defaultBranch,
                $body,
                $blockedBy !== []
            );

            $this->automationClient->setPullRequestLabels((int) $pullRequest['number'], $labels);
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
        $this->automationClient->closePullRequest($number, $reason);
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

    /**
     * @param array<string, mixed> $pullRequest
     */
    private function assertRefreshableAutomationPullRequest(array $pullRequest, string $branch, string $defaultBranch): void
    {
        $baseRef = (string) ($pullRequest['base']['ref'] ?? '');

        if ($branch === $defaultBranch || ($baseRef !== '' && $branch === $baseRef)) {
            throw new RuntimeException(sprintf(
                'Core automation PR #%d resolved to protected branch %s and will not be refreshed.',
                (int) ($pullRequest['number'] ?? 0),
                $branch
            ));
        }

        if (! $this->isManagedRepositoryPullRequest($pullRequest)) {
            throw new RuntimeException(sprintf(
                'Core automation PR #%d does not use a same-repository automation branch and will not be refreshed.',
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
     * @return list<string>
     */
    private function checkoutAndApplyCoreVersion(string $defaultBranch, string $branch, string $downloadUrl, string $targetVersion): array
    {
        $this->gitRunner->checkoutBranch($defaultBranch, $branch);
        $tempDir = sys_get_temp_dir() . '/wp-core-update-' . bin2hex(random_bytes(6));

        if (! mkdir($tempDir, 0777, true) && ! is_dir($tempDir)) {
            throw new RuntimeException(sprintf('Failed to create temp directory: %s', $tempDir));
        }

        $archivePath = $tempDir . '/core.zip';
        $extractPath = $tempDir . '/extract';
        if (! mkdir($extractPath, 0777, true) && ! is_dir($extractPath)) {
            throw new RuntimeException(sprintf('Failed to create extraction directory: %s', $extractPath));
        }

        try {
            $this->archiveDownloader->downloadToFile($downloadUrl, $archivePath);

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

            $this->coreClient->assertOfficialChecksums($targetVersion, $sourceRoot);
            $this->sanitizeExtractedTree($sourceRoot);

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
        $destinationWpContent = $this->config->repoRoot . '/' . $this->config->paths['content_root'];

        if (! is_dir($destinationWpContent)) {
            if (! mkdir($destinationWpContent, 0777, true) && ! is_dir($destinationWpContent)) {
                throw new RuntimeException(sprintf('Failed to create WordPress content directory: %s', $destinationWpContent));
            }
        }

        $paths = [];

        foreach (array_values(array_filter(scandir($sourceWpContent) ?: [], static fn (string $entry): bool => ! in_array($entry, ['.', '..'], true))) as $entry) {
            $source = $sourceWpContent . '/' . $entry;
            $destination = $destinationWpContent . '/' . $entry;

            if (is_dir($source) && in_array($entry, ['plugins', 'themes'], true)) {
                $paths = array_merge($paths, $this->syncBundledDirectory($source, $destination, $this->config->paths['content_root'] . '/' . $entry));
                continue;
            }

            $this->removePath($destination);
            $this->copyPath($source, $destination);
            $paths[] = $this->config->paths['content_root'] . '/' . $entry;
        }

        return $paths;
    }

    /**
     * @return list<string>
     */
    private function syncBundledDirectory(string $sourceDirectory, string $destinationDirectory, string $pathPrefix): array
    {
        if (! is_dir($destinationDirectory)) {
            if (! mkdir($destinationDirectory, 0777, true) && ! is_dir($destinationDirectory)) {
                throw new RuntimeException(sprintf('Failed to create bundled destination directory: %s', $destinationDirectory));
            }
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
            if (! unlink($path)) {
                throw new RuntimeException(sprintf('Failed to remove path: %s', $path));
            }
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                if (! rmdir($item->getPathname())) {
                    throw new RuntimeException(sprintf('Failed to remove directory: %s', $item->getPathname()));
                }
            } else {
                if (! unlink($item->getPathname())) {
                    throw new RuntimeException(sprintf('Failed to remove file: %s', $item->getPathname()));
                }
            }
        }

        if (! rmdir($path)) {
            throw new RuntimeException(sprintf('Failed to remove directory: %s', $path));
        }
    }

    private function copyPath(string $source, string $destination): void
    {
        if (is_file($source)) {
            $targetDir = dirname($destination);

            if (! is_dir($targetDir) && ! mkdir($targetDir, 0777, true) && ! is_dir($targetDir)) {
                throw new RuntimeException(sprintf('Failed to create copy destination directory: %s', $targetDir));
            }

            if (! copy($source, $destination)) {
                throw new RuntimeException(sprintf('Failed to copy file %s to %s.', $source, $destination));
            }
            return;
        }

        if (! mkdir($destination, 0777, true) && ! is_dir($destination)) {
            throw new RuntimeException(sprintf('Failed to create directory: %s', $destination));
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $target = $destination . '/' . $iterator->getSubPathName();

            if ($item->isDir()) {
                if (! is_dir($target)) {
                    if (! mkdir($target, 0777, true) && ! is_dir($target)) {
                        throw new RuntimeException(sprintf('Failed to create directory: %s', $target));
                    }
                }
            } else {
                if (! copy($item->getPathname(), $target)) {
                    throw new RuntimeException(sprintf('Failed to copy file %s to %s.', $item->getPathname(), $target));
                }
            }
        }
    }

    private function sanitizeExtractedTree(string $root): void
    {
        $forbiddenPaths = $this->config->runtime['forbidden_paths'];
        $forbiddenFiles = $this->config->runtime['forbidden_files'];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $basename = basename($item->getPathname());

            if ($item->isDir()) {
                foreach ($forbiddenPaths as $pattern) {
                    if (fnmatch($pattern, $basename)) {
                        $this->removePath($item->getPathname());
                        break;
                    }
                }

                continue;
            }

            foreach ($forbiddenFiles as $pattern) {
                if (fnmatch($pattern, $basename)) {
                    if (! unlink($item->getPathname())) {
                        throw new RuntimeException(sprintf('Failed to remove forbidden file during sanitize: %s', $item->getPathname()));
                    }
                    break;
                }
            }
        }
    }

    private function beginBranchRollbackGuard(string $branch): BranchRollbackGuard
    {
        $guard = new BranchRollbackGuard($this->config->repoRoot, $this->gitRunner);
        $guard->begin();
        $guard->trackBranch($branch);
        return $guard;
    }
}
