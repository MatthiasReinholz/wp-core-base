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
        private readonly FrameworkReleaseClient $frameworkReleaseClient,
        private readonly ReleaseClassifier $releaseClassifier,
        private readonly PrBodyRenderer $prBodyRenderer,
        private readonly ?GitHubClient $gitHubClient,
        private readonly GitCommandRunner $gitRunner,
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

    public function sync(bool $checkOnly = false): void
    {
        $releases = $this->frameworkReleaseClient->fetchStableReleases($this->framework);
        $latestRelease = $this->frameworkReleaseClient->releaseData($this->framework, $releases[0]);

        if ($checkOnly) {
            $this->printCheckOnlyResult($latestRelease);
            return;
        }

        if ($this->gitHubClient === null) {
            throw new RuntimeException('framework-sync requires GITHUB_REPOSITORY and GITHUB_TOKEN unless --check-only is used.');
        }

        $defaultBranch = $this->gitHubClient->getDefaultBranch();
        $this->gitHubClient->ensureLabels(self::labelDefinitions());
        $openPrs = $this->indexFrameworkPullRequests($this->gitHubClient->listOpenPullRequests());
        $plannedPrs = [];

        foreach ($openPrs as $pr) {
            $plannedPrs[] = $this->planExistingPullRequest($pr, $this->framework->version, (string) $latestRelease['version'], (string) $latestRelease['release_at']);
        }

        usort($plannedPrs, fn (array $left, array $right): int => version_compare($left['planned_target_version'], $right['planned_target_version']));

        foreach ($plannedPrs as $index => $plannedPr) {
            $blockedBy = array_values(array_unique(array_merge(
                $this->unresolvedBlockedBy((array) (($plannedPr['metadata']['blocked_by'] ?? []))),
                array_values(array_map(static fn (array $previous): int => (int) $previous['number'], array_slice($plannedPrs, 0, $index)))
            )));

            $this->refreshPullRequest($plannedPr, $latestRelease, $blockedBy, $defaultBranch);
        }

        $highestCoveredVersion = $this->framework->version;

        foreach ($plannedPrs as $plannedPr) {
            if (version_compare($plannedPr['planned_target_version'], $highestCoveredVersion, '>')) {
                $highestCoveredVersion = $plannedPr['planned_target_version'];
            }
        }

        if (version_compare((string) $latestRelease['version'], $highestCoveredVersion, '>')) {
            $scope = $this->releaseClassifier->classifyScope($highestCoveredVersion, (string) $latestRelease['version']);

            $this->createPullRequestForLatest(
                $latestRelease,
                $scope,
                array_values(array_map(static fn (array $pr): int => (int) $pr['number'], $plannedPrs)),
                $defaultBranch
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
            throw new RuntimeException(sprintf('Managed framework pull request #%d is missing metadata.', $pullRequest['number']));
        }

        $targetVersion = (string) ($metadata['target_version'] ?? '');
        $releaseAt = (string) ($metadata['release_at'] ?? '');
        $scope = (string) ($metadata['scope'] ?? 'none');

        if ($targetVersion === '' || $releaseAt === '') {
            throw new RuntimeException(sprintf('Managed framework pull request #%d has incomplete metadata.', $pullRequest['number']));
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
     * @param array<string, mixed> $plannedPr
     * @param array<string, mixed> $latestRelease
     * @param list<int> $blockedBy
     */
    private function refreshPullRequest(array $plannedPr, array $latestRelease, array $blockedBy, string $defaultBranch): void
    {
        $metadata = $plannedPr['metadata'];
        $targetVersion = (string) $plannedPr['planned_target_version'];
        $releaseAt = (string) $plannedPr['planned_release_at'];
        $scope = (string) $plannedPr['planned_scope'];
        $branch = (string) ($metadata['branch'] ?? $plannedPr['head']['ref'] ?? '');

        if ($branch === '') {
            throw new RuntimeException(sprintf('Managed framework pull request #%d is missing a branch name.', $plannedPr['number']));
        }

        $releaseData = $this->findReleaseDataForVersion($targetVersion);
        $skippedFiles = (array) ($metadata['skipped_managed_files'] ?? []);

        if ((bool) $plannedPr['requires_code_update']) {
            $result = $this->checkoutAndApplyFrameworkVersion($defaultBranch, $branch, $releaseData);
            $this->framework = FrameworkConfig::load($this->repoRoot);
            $skippedFiles = $result['skipped_files'];
            $this->gitRunner->commitAndPush(
                $branch,
                sprintf('Update wp-core-base from %s to %s', (string) $metadata['base_version'], $targetVersion),
                $result['changed_paths']
            );
        }

        $labels = $this->deriveFrameworkLabels($scope, $blockedBy);
        $metadata['component_key'] = self::COMPONENT_KEY;
        $metadata['slug'] = 'wp-core-base';
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
            sourceRepository: $this->framework->repository,
            releaseUrl: (string) $releaseData['release_url'],
            currentBaseline: (string) ($metadata['base_wordpress_core'] ?? $this->framework->baseline['wordpress_core']),
            targetBaseline: (string) $releaseData['target_wordpress_core'],
            notesSections: (array) $releaseData['notes_sections'],
            skippedManagedFiles: $skippedFiles,
            metadata: $metadata,
        );

        $this->gitHubClient->updatePullRequest((int) $plannedPr['number'], $title, $body);
        $this->gitHubClient->setLabels((int) $plannedPr['number'], $labels);
        $this->syncDraftState($plannedPr, $blockedBy);
    }

    /**
     * @param array<string, mixed> $latestRelease
     * @param list<int> $blockedBy
     */
    private function createPullRequestForLatest(array $latestRelease, string $scope, array $blockedBy, string $defaultBranch): void
    {
        $branch = $this->newBranchName((string) $latestRelease['version']);
        $baseFramework = $this->framework;
        $result = $this->checkoutAndApplyFrameworkVersion($defaultBranch, $branch, $latestRelease);
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
            return;
        }

        $labels = $this->deriveFrameworkLabels($scope, $blockedBy);
        $metadata = [
            'component_key' => self::COMPONENT_KEY,
            'slug' => 'wp-core-base',
            'branch' => $branch,
            'base_branch' => $defaultBranch,
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
            sourceRepository: $this->framework->repository,
            releaseUrl: (string) $latestRelease['release_url'],
            currentBaseline: $baseFramework->baseline['wordpress_core'],
            targetBaseline: $this->framework->baseline['wordpress_core'],
            notesSections: (array) $latestRelease['notes_sections'],
            skippedManagedFiles: $result['skipped_files'],
            metadata: $metadata,
        );

        $pullRequest = $this->gitHubClient->createPullRequest($title, $branch, $defaultBranch, $body, $blockedBy !== []);
        $this->gitHubClient->setLabels((int) $pullRequest['number'], $labels);
    }

    /**
     * @param array<string, mixed> $releaseData
     * @return array{changed_paths:list<string>, skipped_files:list<string>}
     */
    private function checkoutAndApplyFrameworkVersion(string $defaultBranch, string $branch, array $releaseData): array
    {
        $this->gitRunner->checkoutBranch($defaultBranch, $branch);
        $tempDir = sys_get_temp_dir() . '/wp-core-base-framework-' . bin2hex(random_bytes(6));
        $archivePath = $tempDir . '/framework.zip';
        $extractPath = $tempDir . '/extract';
        $resultPath = $tempDir . '/result.json';

        if (! mkdir($extractPath, 0775, true) && ! is_dir($extractPath)) {
            throw new RuntimeException(sprintf('Failed to create temp directory: %s', $extractPath));
        }

        try {
            $this->frameworkReleaseClient->downloadReleaseAsset($this->framework, $releaseData['release'], $archivePath);
            $zip = new ZipArchive();

            if ($zip->open($archivePath) !== true) {
                throw new RuntimeException(sprintf('Failed to open framework release archive: %s', $archivePath));
            }

            ZipExtractor::extractValidated($zip, $extractPath);
            $zip->close();

            $payloadRoot = $this->resolveExtractedPayloadRoot($extractPath);
            $bootstrapCli = $payloadRoot . '/tools/wporg-updater/bin/wporg-updater.php';

            if (! is_file($bootstrapCli)) {
                throw new RuntimeException(sprintf('Extracted framework release is missing the updater CLI: %s', $bootstrapCli));
            }

            $command = sprintf(
                'php %s framework-apply --repo-root=%s --payload-root=%s --distribution-path=%s --result-path=%s',
                escapeshellarg($bootstrapCli),
                escapeshellarg($this->repoRoot),
                escapeshellarg($payloadRoot),
                escapeshellarg($this->framework->distributionPath()),
                escapeshellarg($resultPath),
            );

            $descriptorSpec = [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $process = proc_open(['/bin/sh', '-lc', $command], $descriptorSpec, $pipes, $this->repoRoot);

            if (! is_resource($process)) {
                throw new RuntimeException('Failed to start framework bootstrap installer.');
            }

            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $status = proc_close($process);

            if ($status !== 0) {
                throw new RuntimeException(sprintf("Framework bootstrap installer failed.\n%s\n%s", trim((string) $stdout), trim((string) $stderr)));
            }

            $result = json_decode((string) file_get_contents($resultPath), true);

            if (! is_array($result)) {
                throw new RuntimeException('Framework bootstrap installer did not write a valid result payload.');
            }

            return [
                'changed_paths' => array_values(array_map('strval', (array) ($result['changed_paths'] ?? []))),
                'skipped_files' => array_values(array_map('strval', (array) ($result['skipped_files'] ?? []))),
            ];
        } finally {
            $this->runtimeInspector->clearPath($tempDir);
        }
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
        $tempDir = sys_get_temp_dir() . '/wp-core-base-framework-meta-' . bin2hex(random_bytes(6));
        $archivePath = $tempDir . '/framework.zip';
        $extractPath = $tempDir . '/extract';

        if (! mkdir($extractPath, 0775, true) && ! is_dir($extractPath)) {
            throw new RuntimeException(sprintf('Failed to create temp directory: %s', $extractPath));
        }

        try {
            $this->frameworkReleaseClient->downloadReleaseAsset($this->framework, $releaseData['release'], $archivePath);
            $zip = new ZipArchive();

            if ($zip->open($archivePath) !== true) {
                throw new RuntimeException(sprintf('Failed to open framework release archive: %s', $archivePath));
            }

            ZipExtractor::extractValidated($zip, $extractPath);
            $zip->close();

            return FrameworkConfig::load($this->resolveExtractedPayloadRoot($extractPath));
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

        $labels = array_values(array_unique($labels));
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

            if ($metadata === null || ($metadata['component_key'] ?? null) !== self::COMPONENT_KEY) {
                continue;
            }

            $indexed[] = $pullRequest;
        }

        return $indexed;
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

    /**
     * @param array<string, mixed> $latestRelease
     */
    private function printCheckOnlyResult(array $latestRelease): void
    {
        fwrite(STDOUT, sprintf("Installed wp-core-base: %s\n", $this->framework->version));
        fwrite(STDOUT, sprintf("Latest available release: %s\n", $latestRelease['version']));

        if (version_compare((string) $latestRelease['version'], $this->framework->version, '>')) {
            fwrite(STDOUT, "Framework update available.\n");
            return;
        }

        fwrite(STDOUT, "Framework is already up to date.\n");
    }
}
