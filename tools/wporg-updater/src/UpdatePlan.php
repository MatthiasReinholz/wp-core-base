<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

/** Pure planning policy shared by core, dependency, and framework update queues. */
final class UpdatePlan
{
    private function __construct(
        public readonly string $baseVersion,
        public readonly string $targetVersion,
        public readonly string $releaseAt,
        public readonly string $scope,
        public readonly bool $requiresBranchRefresh,
    ) {}

    public static function scope(string $baseVersion, string $targetVersion, ?ReleaseClassifier $classifier = null): string
    {
        return ($classifier ?? new ReleaseClassifier())->classifyScope($baseVersion, $targetVersion);
    }

    /** @param array<string,mixed> $metadata */
    public static function refresh(
        array $metadata,
        string $baseVersion,
        string $latestVersion,
        string $latestReleaseAt,
        string $baseRevision,
        ?ReleaseClassifier $classifier = null,
    ): self {
        $classifier ??= new ReleaseClassifier();
        $targetVersion = SourceRecordValues::requiredString($metadata, 'target_version', 'Managed pull request metadata');
        $releaseAt = SourceRecordValues::requiredString($metadata, 'release_at', 'Managed pull request metadata');
        if ($baseVersion === '' || $latestVersion === '') {
            throw new RuntimeException('Update planning requires the installed base and latest versions.');
        }
        $advancePatch = $classifier->samePatchLine($targetVersion, $latestVersion)
            && version_compare($latestVersion, $targetVersion, '>')
            && $classifier->classifyScope($targetVersion, $latestVersion) === 'patch';
        if ($advancePatch) {
            $targetVersion = $latestVersion;
            $releaseAt = $latestReleaseAt;
        }
        $requiresRefresh = $advancePatch || ($metadata['base_version'] ?? null) !== $baseVersion
            || self::branchRefreshRequired($metadata, $baseRevision);
        return new self(
            $baseVersion,
            $targetVersion,
            $releaseAt,
            self::scope($baseVersion, $targetVersion, $classifier),
            $requiresRefresh
        );
    }
    /** @param array<string,mixed> $metadata */
    public static function branchRefreshRequired(array $metadata, string $baseRevision): bool
    {
        if ($baseRevision === '') {
            return false;
        }
        $recordedRevision = $metadata['base_revision'] ?? null;
        return ! is_string($recordedRevision) || ! hash_equals($recordedRevision, $baseRevision);
    }
}
