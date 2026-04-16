<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use DateTimeImmutable;
use RuntimeException;

final class ManagedDependencyPullRequestPlanner
{
    public function __construct(
        private readonly Config $config,
        private readonly ManagedSourceRegistry $managedSourceRegistry,
        private readonly SupportForumClient $supportForumClient,
        private readonly ReleaseClassifier $releaseClassifier,
        private readonly GitHubAutomationClient $gitHubClient,
    ) {
    }

    /**
     * @param array<string, array{color:string, description:string}> $baseDefinitions
     * @return array<string, array{color:string, description:string}>
     */
    public function labelDefinitionsForRun(array $baseDefinitions): array
    {
        $definitions = $baseDefinitions;

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
     * @param array<string, mixed> $pullRequest
     * @return array<string, mixed>
     */
    public function planExistingPullRequest(array $pullRequest, string $baseVersion, string $latestVersion, string $latestReleaseAt, string $baseRevision): array
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

        $requiresBranchRefresh = AutomationPullRequestGuard::branchRefreshRequired($metadata, $baseRevision);

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
     * @param array<string, mixed> $pullRequest
     * @param array<string, mixed> $metadata
     * @return list<array{title:string, url:string, opened_at:string}>
     */
    public function supportTopicsForExistingPullRequest(
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
    public function supportTopicsForNewPullRequest(array $dependency, DateTimeImmutable $releaseAt): array
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
    public function deriveDependencyLabels(array $dependency, string $scope, string $notesText, array $supportTopics, array $blockedBy): array
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
     * @param list<array<string, mixed>> $pullRequests
     * @return array<string, list<array<string, mixed>>>
     */
    public function indexManagedPullRequests(array $pullRequests): array
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

            if (! AutomationPullRequestGuard::isSameRepositoryAutomationPullRequest($pullRequest)) {
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
     * @return array<string, mixed>|null
     */
    public function findOpenPullRequestForTarget(string $componentKey, string $targetVersion): ?array
    {
        $matching = [];

        foreach ($this->gitHubClient->listOpenPullRequests('automation:dependency-update') as $pullRequest) {
            $metadata = PrBodyRenderer::extractMetadata((string) ($pullRequest['body'] ?? ''));

            if ($metadata === null) {
                continue;
            }

            if (! $this->metadataMatchesComponentKey($metadata, $componentKey)) {
                continue;
            }

            if (! AutomationPullRequestGuard::isSameRepositoryAutomationPullRequest($pullRequest)) {
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

    public function closeSupersededPullRequest(int $number, string $reason): void
    {
        fwrite(STDOUT, sprintf("Closing PR #%d: %s\n", $number, $reason));
        $this->gitHubClient->closePullRequest($number, $reason);
    }

    public function titleForPullRequest(string $dependencyName, string $baseVersion, string $targetVersion): string
    {
        return sprintf('Update %s from %s to %s', $dependencyName, $baseVersion, $targetVersion);
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
}
