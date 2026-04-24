<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use DateTimeImmutable;
use RuntimeException;
use ZipArchive;

final class FrameworkSyncer
{
    private const COMPONENT_KEY = 'framework:wp-core-base';

    public function __construct(
        private FrameworkConfig $framework,
        private readonly string $repoRoot,
        private readonly ?Config $config,
        private readonly FrameworkReleaseSource $frameworkReleaseClient,
        private readonly ReleaseClassifier $releaseClassifier,
        private readonly PrBodyRenderer $prBodyRenderer,
        private readonly ?AutomationClient $automationClient,
        private readonly GitRunnerInterface $gitRunner,
        private readonly RuntimeInspector $runtimeInspector,
    ) {
    }

    /**
     * @return array<string, array{color:string, description:string}>
     */
    public static function labelDefinitions(): array
    {
        return [
            'automation:framework-update' => ['color' => '1d76db', 'description' => 'wp-core-base framework update PR'],
            'component:framework' => ['color' => '24292f', 'description' => 'wp-core-base framework component'],
            'release:patch' => ['color' => '5319e7', 'description' => 'Patch release'],
            'release:minor' => ['color' => 'fbca04', 'description' => 'Minor release'],
            'release:major' => ['color' => 'd93f0b', 'description' => 'Major release'],
            'status:blocked' => ['color' => 'bfdadc', 'description' => 'Blocked behind an older open update PR for the same component'],
        ];
    }

    /**
     * @return array{
     *   installed_version:string,
     *   latest_version:string,
     *   release_scope:string,
     *   release_at:string,
     *   release_url:string,
     *   current_wordpress_core:string,
     *   target_wordpress_core:string,
     *   update_available:bool,
     *   changed_paths:list<string>,
     *   refreshed_files:list<string>,
     *   removed_files:list<string>,
     *   skipped_files:list<string>,
     *   would_fail_on_skipped_managed_files:bool
     * }
     */
    public function checkOnlyReport(bool $failOnSkippedManagedFiles = false): array
    {
        $releases = $this->frameworkReleaseClient->fetchStableReleases($this->framework);
        $latestRelease = $this->frameworkReleaseClient->releaseData($this->framework, $releases[0]);
        $latestVersion = (string) $latestRelease['version'];
        $updateAvailable = version_compare($latestVersion, $this->framework->version, '>');
        $report = [
            'installed_version' => $this->framework->version,
            'latest_version' => $latestVersion,
            'release_scope' => $updateAvailable
                ? $this->releaseClassifier->classifyScope($this->framework->version, $latestVersion)
                : 'none',
            'release_at' => (string) ($latestRelease['release_at'] ?? ''),
            'release_url' => (string) ($latestRelease['release_url'] ?? ''),
            'current_wordpress_core' => $this->framework->baseline['wordpress_core'],
            'target_wordpress_core' => $this->framework->baseline['wordpress_core'],
            'update_available' => $updateAvailable,
            'changed_paths' => [],
            'refreshed_files' => [],
            'removed_files' => [],
            'skipped_files' => [],
            'would_fail_on_skipped_managed_files' => false,
        ];

        if (! $updateAvailable) {
            return $report;
        }

        $inspection = $this->withFrameworkPayload($latestRelease, function (string $payloadRoot): array {
            $payloadFramework = FrameworkConfig::load($payloadRoot);
            $installPlan = (new FrameworkInstaller($this->repoRoot, $this->runtimeInspector))->plan(
                $payloadRoot,
                $this->framework->distributionPath()
            );

            return [
                'target_wordpress_core' => $payloadFramework->baseline['wordpress_core'],
                'install_plan' => $installPlan,
            ];
        });

        $installPlan = $inspection['install_plan'];
        $report['target_wordpress_core'] = (string) $inspection['target_wordpress_core'];
        $report['changed_paths'] = $installPlan['changed_paths'];
        $report['refreshed_files'] = $installPlan['refreshed_files'];
        $report['removed_files'] = $installPlan['removed_files'];
        $report['skipped_files'] = $installPlan['skipped_files'];
        $report['would_fail_on_skipped_managed_files'] = $failOnSkippedManagedFiles && $report['skipped_files'] !== [];

        return $report;
    }

    public function sync(bool $failOnSkippedManagedFiles = false): void
    {
        $releases = $this->frameworkReleaseClient->fetchStableReleases($this->framework);
        $latestRelease = $this->frameworkReleaseClient->releaseData($this->framework, $releases[0]);

        if ($this->automationClient === null) {
            throw new RuntimeException('framework-sync requires the configured automation environment unless --check-only is used.');
        }

        $this->gitRunner->assertCleanWorktree();
        $defaultBranch = $this->config?->baseBranch() ?? $this->automationClient->getDefaultBranch();
        $baseRevision = $this->gitRunner->remoteRevision($defaultBranch);
        $this->automationClient->ensureLabels(self::labelDefinitions());
        $openPrs = $this->indexFrameworkPullRequests($this->automationClient->listOpenPullRequests('automation:framework-update'));
        $plannedPrs = [];

        foreach ($openPrs as $pr) {
            try {
                $plannedPrs[] = $this->planExistingPullRequest($pr, $this->framework->version, (string) $latestRelease['version'], (string) $latestRelease['release_at'], $baseRevision);
            } catch (\Throwable $throwable) {
                fwrite(STDERR, sprintf(
                    "[warn] Ignoring malformed framework automation PR #%d: %s\n",
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
                    'Another automation PR already covers `wp-core-base` at `%s`. This duplicate PR is being closed to keep one live PR per target version.',
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

            if ($this->refreshPullRequest($plannedPr, $latestRelease, $blockedBy, $defaultBranch, $baseRevision, $failOnSkippedManagedFiles)) {
                $activePlannedPrs[] = $plannedPr;
            }
        }

        $highestCoveredVersion = $this->framework->version;

        foreach ($activePlannedPrs as $plannedPr) {
            if (version_compare($plannedPr['planned_target_version'], $highestCoveredVersion, '>')) {
                $highestCoveredVersion = $plannedPr['planned_target_version'];
            }
        }

        if (version_compare((string) $latestRelease['version'], $highestCoveredVersion, '>')) {
            $scope = $this->releaseClassifier->classifyScope($highestCoveredVersion, (string) $latestRelease['version']);

            $this->createPullRequestForLatest(
                $latestRelease,
                $scope,
                array_map(static fn (array $pr): int => (int) $pr['number'], $activePlannedPrs),
                $defaultBranch,
                $baseRevision,
                $failOnSkippedManagedFiles
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
            throw new RuntimeException(sprintf('Managed framework pull request #%d is missing metadata.', $pullRequest['number']));
        }

        $targetVersion = (string) ($metadata['target_version'] ?? '');
        $releaseAt = (string) ($metadata['release_at'] ?? '');
        $scope = (string) ($metadata['scope'] ?? 'none');

        if ($targetVersion === '' || $releaseAt === '') {
            throw new RuntimeException(sprintf('Managed framework pull request #%d has incomplete metadata.', $pullRequest['number']));
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
     * @param array<string, mixed> $plannedPr
     * @param array<string, mixed> $latestRelease
     * @param list<int> $blockedBy
     */
    private function refreshPullRequest(array $plannedPr, array $latestRelease, array $blockedBy, string $defaultBranch, string $baseRevision, bool $failOnSkippedManagedFiles): bool
    {
        $metadata = $plannedPr['metadata'];
        $targetVersion = (string) $plannedPr['planned_target_version'];
        $releaseAt = (string) $plannedPr['planned_release_at'];
        $scope = (string) $plannedPr['planned_scope'];
        $branch = (string) ($metadata['branch'] ?? $plannedPr['head']['ref'] ?? '');

        if ($branch === '') {
            throw new RuntimeException(sprintf('Managed framework pull request #%d is missing a branch name.', $plannedPr['number']));
        }

        $this->assertRefreshableAutomationPullRequest($plannedPr, $branch, $defaultBranch);

        $branchGuard = (bool) $plannedPr['requires_code_update'] ? $this->beginBranchRollbackGuard($branch) : null;

        if ($this->pullRequestAlreadySatisfied($this->framework->version, $targetVersion)) {
            $this->closeSupersededPullRequest(
                (int) $plannedPr['number'],
                sprintf(
                    'Base branch already contains `wp-core-base` `%s`. This stale automation PR is no longer applicable and has been closed.',
                    $targetVersion
                )
            );
            return false;
        }

        $releaseData = $this->findReleaseDataForVersion($targetVersion);
        $skippedFiles = (array) ($metadata['skipped_managed_files'] ?? []);

        try {
            if ((bool) $plannedPr['requires_code_update']) {
                $result = [];
                $result = $this->checkoutAndApplyFrameworkVersion($defaultBranch, $branch, $releaseData, $failOnSkippedManagedFiles);

                $changed = $this->gitRunner->commitAndPush(
                    $branch,
                    sprintf('Update wp-core-base from %s to %s', (string) $metadata['base_version'], $targetVersion),
                    $result['changed_paths']
                );
                $this->framework = FrameworkConfig::load($this->repoRoot);
                $skippedFiles = $result['skipped_files'];

                if (! $changed) {
                    $this->closeSupersededPullRequest(
                        (int) $plannedPr['number'],
                        sprintf(
                            'Refreshing this `wp-core-base` PR from the latest base branch produced no remaining file changes for `%s`. The PR has been closed as a no-op.',
                            $targetVersion
                        )
                    );

                    if ($branchGuard !== null) {
                        $branchGuard->complete();
                    }

                    return false;
                }
            }

            if ($failOnSkippedManagedFiles && $skippedFiles !== []) {
                throw new RuntimeException($this->strictSkippedManagedFilesMessage($targetVersion, $skippedFiles));
            }

            $labels = $this->deriveFrameworkLabels($scope, $blockedBy);
            $metadata['component_key'] = self::COMPONENT_KEY;
            $metadata['slug'] = 'wp-core-base';
            $metadata['base_branch'] = $defaultBranch;
            $metadata['base_revision'] = $baseRevision;
            $metadata['base_version'] = $metadata['base_version'] ?? $this->framework->version;
            $metadata['target_version'] = $targetVersion;
            $metadata['scope'] = $scope;
            $metadata['release_at'] = $releaseAt;
            $metadata['release_url'] = $releaseData['release_url'];
            $metadata['blocked_by'] = $blockedBy;
            $metadata['branch'] = $branch;
            $metadata['skipped_managed_files'] = $skippedFiles;
            $metadata['updated_at'] = gmdate(DATE_ATOM);

            $title = $this->titleForPullRequest((string) $metadata['base_version'], $targetVersion);
            $body = $this->prBodyRenderer->renderFrameworkUpdate(
                currentVersion: (string) $metadata['base_version'],
                targetVersion: $targetVersion,
                releaseScope: $scope,
                releaseAt: $releaseAt,
                labels: $labels,
                sourceReferenceLabel: $this->framework->releaseSourceReferenceLabel(),
                sourceReference: $this->framework->releaseSourceReference(),
                sourceReferenceUrl: $this->framework->releaseSourceReferenceUrl(),
                releaseUrl: (string) $releaseData['release_url'],
                currentBaseline: (string) ($metadata['base_wordpress_core'] ?? $this->framework->baseline['wordpress_core']),
                targetBaseline: (string) $releaseData['target_wordpress_core'],
                notesSections: (array) $releaseData['notes_sections'],
                skippedManagedFiles: $skippedFiles,
                metadata: $metadata,
            );

            $this->automationClient->updatePullRequest((int) $plannedPr['number'], $title, $body);
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
     * @param array<string, mixed> $latestRelease
     * @param list<int> $blockedBy
     */
    private function createPullRequestForLatest(array $latestRelease, string $scope, array $blockedBy, string $defaultBranch, string $baseRevision, bool $failOnSkippedManagedFiles): void
    {
        $existingPullRequest = $this->findOpenPullRequestForTarget((string) $latestRelease['version']);

        if ($existingPullRequest !== null) {
            fwrite(STDOUT, sprintf(
                "Skipping framework PR creation because PR #%d already covers %s.\n",
                (int) $existingPullRequest['number'],
                (string) $latestRelease['version']
            ));
            return;
        }

        $branch = $this->newBranchName((string) $latestRelease['version']);
        $baseFramework = $this->framework;
        $branchGuard = $this->beginBranchRollbackGuard($branch);

        try {
            $result = $this->checkoutAndApplyFrameworkVersion($defaultBranch, $branch, $latestRelease, $failOnSkippedManagedFiles);
            $this->framework = FrameworkConfig::load($this->repoRoot);
            $changed = $this->gitRunner->commitAndPush(
                $branch,
                sprintf('Update wp-core-base from %s to %s', $baseFramework->version, (string) $latestRelease['version']),
                $result['changed_paths']
            );

            if (! $changed) {
                fwrite(STDOUT, sprintf(
                    "Skipping framework PR creation because updating wp-core-base to %s produced no file changes.\n",
                    $latestRelease['version']
                ));
                $branchGuard->complete();
                return;
            }

            $labels = $this->deriveFrameworkLabels($scope, $blockedBy);
            $metadata = [
                'component_key' => self::COMPONENT_KEY,
                'slug' => 'wp-core-base',
                'branch' => $branch,
                'base_branch' => $defaultBranch,
                'base_revision' => $baseRevision,
                'base_version' => $baseFramework->version,
                'target_version' => (string) $latestRelease['version'],
                'scope' => $scope,
                'release_at' => (string) $latestRelease['release_at'],
                'release_url' => (string) $latestRelease['release_url'],
                'base_wordpress_core' => $baseFramework->baseline['wordpress_core'],
                'target_wordpress_core' => $this->framework->baseline['wordpress_core'],
                'blocked_by' => $blockedBy,
                'skipped_managed_files' => $result['skipped_files'],
                'updated_at' => gmdate(DATE_ATOM),
            ];

            $title = $this->titleForPullRequest($baseFramework->version, (string) $latestRelease['version']);
            $body = $this->prBodyRenderer->renderFrameworkUpdate(
                currentVersion: $baseFramework->version,
                targetVersion: (string) $latestRelease['version'],
                releaseScope: $scope,
                releaseAt: (string) $latestRelease['release_at'],
                labels: $labels,
                sourceReferenceLabel: $this->framework->releaseSourceReferenceLabel(),
                sourceReference: $this->framework->releaseSourceReference(),
                sourceReferenceUrl: $this->framework->releaseSourceReferenceUrl(),
                releaseUrl: (string) $latestRelease['release_url'],
                currentBaseline: $baseFramework->baseline['wordpress_core'],
                targetBaseline: $this->framework->baseline['wordpress_core'],
                notesSections: (array) $latestRelease['notes_sections'],
                skippedManagedFiles: $result['skipped_files'],
                metadata: $metadata,
            );

            $pullRequest = $this->automationClient->createPullRequest($title, $branch, $defaultBranch, $body, $blockedBy !== []);
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
     * @param array<string, mixed> $releaseData
     * @return array{changed_paths:list<string>, skipped_files:list<string>}
     */
    private function checkoutAndApplyFrameworkVersion(string $defaultBranch, string $branch, array $releaseData, bool $failOnSkippedManagedFiles): array
    {
        $this->gitRunner->checkoutBranch($defaultBranch, $branch);
        $installerResult = $this->withFrameworkPayload($releaseData, function (string $payloadRoot): array {
            return (new FrameworkInstaller($this->repoRoot, $this->runtimeInspector))->apply(
                $payloadRoot,
                $this->framework->distributionPath()
            );
        });

        $changedPaths = $installerResult['changed_paths'];
        $skippedFiles = $installerResult['skipped_files'];

        if ($failOnSkippedManagedFiles && $skippedFiles !== []) {
            throw new RuntimeException($this->strictSkippedManagedFilesMessage((string) ($releaseData['version'] ?? ''), $skippedFiles));
        }

        return [
            'changed_paths' => $changedPaths,
            'skipped_files' => $skippedFiles,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function findReleaseDataForVersion(string $version): array
    {
        foreach ($this->frameworkReleaseClient->fetchStableReleases($this->framework) as $release) {
            $releaseData = $this->frameworkReleaseClient->releaseData($this->framework, $release);

            if ($releaseData['version'] === $version) {
                $payloadFramework = $this->frameworkMetadataForReleaseAsset($releaseData);
                $releaseData['target_wordpress_core'] = $payloadFramework->baseline['wordpress_core'];
                return $releaseData;
            }
        }

        throw new RuntimeException(sprintf('Unable to find framework release metadata for version %s.', $version));
    }

    /**
     * @param array<string, mixed> $releaseData
     */
    private function frameworkMetadataForReleaseAsset(array $releaseData): FrameworkConfig
    {
        return $this->withFrameworkPayload($releaseData, function (string $payloadRoot): FrameworkConfig {
            return FrameworkConfig::load($payloadRoot);
        });
    }

    /**
     * @template T
     * @param array<string, mixed> $releaseData
     * @param callable(string):T $callback
     * @return T
     */
    private function withFrameworkPayload(array $releaseData, callable $callback): mixed
    {
        $tempDir = sys_get_temp_dir() . '/wp-core-base-framework-meta-' . bin2hex(random_bytes(6));
        $archivePath = $tempDir . '/framework.zip';
        $extractPath = $tempDir . '/extract';

        if (! mkdir($extractPath, 0775, true) && ! is_dir($extractPath)) {
            throw new RuntimeException(sprintf('Failed to create temp directory: %s', $extractPath));
        }

        try {
            $this->frameworkReleaseClient->downloadVerifiedReleaseAsset($this->framework, $releaseData['release'], $archivePath);
            $zip = new ZipArchive();

            if ($zip->open($archivePath) !== true) {
                throw new RuntimeException(sprintf('Failed to open framework release archive: %s', $archivePath));
            }

            ZipExtractor::extractValidated($zip, $extractPath);
            $zip->close();
            return $callback($this->resolveExtractedPayloadRoot($extractPath));
        } finally {
            $this->runtimeInspector->clearPath($tempDir);
        }
    }

    private function resolveExtractedPayloadRoot(string $extractPath): string
    {
        $entries = array_values(array_filter(scandir($extractPath) ?: [], static fn (string $entry): bool => $entry !== '.' && $entry !== '..'));

        if ($entries === []) {
            throw new RuntimeException('Framework release archive extracted without any files.');
        }

        if (count($entries) === 1) {
            $candidate = $extractPath . '/' . $entries[0];

            if (is_dir($candidate)) {
                return $candidate;
            }
        }

        return $extractPath;
    }

    /**
     * @param list<int> $blockedBy
     * @return list<string>
     */
    private function deriveFrameworkLabels(string $scope, array $blockedBy): array
    {
        $labels = ['automation:framework-update', 'component:framework'];

        if ($scope !== 'none') {
            $labels[] = 'release:' . $scope;
        }

        if ($blockedBy !== []) {
            $labels[] = 'status:blocked';
        }

        $labels = LabelHelper::normalizeList($labels);
        sort($labels);

        return $labels;
    }

    /**
     * @param list<array<string, mixed>> $pullRequests
     * @return list<array<string, mixed>>
     */
    private function indexFrameworkPullRequests(array $pullRequests): array
    {
        $indexed = [];

        foreach ($pullRequests as $pullRequest) {
            $metadata = PrBodyRenderer::extractMetadata((string) ($pullRequest['body'] ?? ''));

            if ($metadata === null || ($metadata['component_key'] ?? null) !== self::COMPONENT_KEY || ! $this->isManagedRepositoryPullRequest($pullRequest)) {
                continue;
            }

            $indexed[] = $pullRequest;
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

    private function titleForPullRequest(string $baseVersion, string $targetVersion): string
    {
        return sprintf('Update wp-core-base from %s to %s', $baseVersion, $targetVersion);
    }

    private function newBranchName(string $version): string
    {
        $fragment = preg_replace('/[^a-z0-9]+/i', '-', strtolower('framework-' . $version . '-' . gmdate('YmdHis')));
        return 'codex/' . trim((string) $fragment, '-');
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

        foreach ($this->indexFrameworkPullRequests($this->automationClient->listOpenPullRequests('automation:framework-update')) as $pullRequest) {
            $metadata = PrBodyRenderer::extractMetadata((string) ($pullRequest['body'] ?? ''));

            if (($metadata['target_version'] ?? null) === $targetVersion) {
                $pullRequest['metadata'] = $metadata;
                $pullRequest['planned_target_version'] = (string) $metadata['target_version'];
                $pullRequest['planned_release_at'] = (string) ($metadata['release_at'] ?? '');
                $matching[] = $pullRequest;
            }
        }

        return ManagedPullRequestCanonicalizer::selectCanonical($matching);
    }

    /**
     * @param array{
     *   installed_version:string,
     *   latest_version:string,
     *   release_scope:string,
     *   release_at:string,
     *   release_url:string,
     *   current_wordpress_core:string,
     *   target_wordpress_core:string,
     *   update_available:bool,
     *   changed_paths:list<string>,
     *   refreshed_files:list<string>,
     *   removed_files:list<string>,
     *   skipped_files:list<string>,
     *   would_fail_on_skipped_managed_files:bool
     * } $report
     */
    public function printCheckOnlyResult(array $report): void
    {
        fwrite(STDOUT, sprintf("Installed wp-core-base: %s\n", $report['installed_version']));
        fwrite(STDOUT, sprintf("Latest available release: %s\n", $report['latest_version']));

        if (! $report['update_available']) {
            fwrite(STDOUT, "Framework is already up to date.\n");
            return;
        }

        fwrite(STDOUT, "Framework update available.\n");
        fwrite(STDOUT, sprintf("Release scope: %s\n", $report['release_scope']));

        if ($report['release_at'] !== '') {
            fwrite(STDOUT, sprintf("Release published at: %s\n", $report['release_at']));
        }

        if ($report['release_url'] !== '') {
            fwrite(STDOUT, sprintf("Release URL: %s\n", $report['release_url']));
        }

        fwrite(STDOUT, sprintf("Current WordPress core baseline: %s\n", $report['current_wordpress_core']));
        fwrite(STDOUT, sprintf("Target WordPress core baseline: %s\n", $report['target_wordpress_core']));
        $this->printCheckOnlyFileSection('Planned refreshed managed files', $report['refreshed_files']);
        $this->printCheckOnlyFileSection('Planned removed managed files', $report['removed_files']);
        $this->printCheckOnlyFileSection('Planned skipped managed files', $report['skipped_files']);

        if ($report['would_fail_on_skipped_managed_files']) {
            fwrite(STDOUT, "Strict mode would fail because one or more framework-managed files would be skipped.\n");
        }
    }

    private function beginBranchRollbackGuard(string $branch): BranchRollbackGuard
    {
        $guard = new BranchRollbackGuard($this->repoRoot, $this->gitRunner);
        $guard->begin();
        $guard->trackBranch($branch);
        return $guard;
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
     * @param array<string, mixed> $pullRequest
     */
    private function assertRefreshableAutomationPullRequest(array $pullRequest, string $branch, string $defaultBranch): void
    {
        $baseRef = (string) ($pullRequest['base']['ref'] ?? '');

        if ($branch === $defaultBranch || ($baseRef !== '' && $branch === $baseRef)) {
            throw new RuntimeException(sprintf(
                'Framework automation PR #%d resolved to protected branch %s and will not be refreshed.',
                (int) ($pullRequest['number'] ?? 0),
                $branch
            ));
        }

        if (! $this->isManagedRepositoryPullRequest($pullRequest)) {
            throw new RuntimeException(sprintf(
                'Framework automation PR #%d does not use a same-repository automation branch and will not be refreshed.',
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
     * @param list<string> $paths
     */
    private function printCheckOnlyFileSection(string $heading, array $paths): void
    {
        fwrite(STDOUT, sprintf("%s: %d\n", $heading, count($paths)));

        foreach ($paths as $path) {
            fwrite(STDOUT, sprintf("- %s\n", $path));
        }
    }

    /**
     * @param list<string> $skippedFiles
     */
    private function strictSkippedManagedFilesMessage(string $targetVersion, array $skippedFiles): string
    {
        $targetLabel = trim($targetVersion) === '' ? 'the requested framework release' : sprintf('wp-core-base %s', $targetVersion);

        return sprintf(
            'Strict framework-sync aborted because %s would skip customized framework-managed files: %s. Reconcile those files manually or rerun without --fail-on-skipped-managed-files.',
            $targetLabel,
            implode(', ', $skippedFiles)
        );
    }
}
