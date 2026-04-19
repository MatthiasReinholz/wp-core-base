<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class GitLabReleaseManagedSource implements ManagedDependencySource
{
    public function __construct(
        private readonly GitLabReleaseClient $client,
    ) {
    }

    public function key(): string
    {
        return 'gitlab-release';
    }

    public function fetchCatalog(array $dependency): array
    {
        $releases = $this->client->fetchStableReleases($dependency);
        $releasesByVersion = [];

        foreach ($releases as $release) {
            $version = (string) ($release['normalized_version'] ?? '');

            if ($version !== '') {
                $releasesByVersion[$version] = $release;
            }
        }

        $latest = $releases[0];

        return [
            'source' => 'gitlab-release',
            'project' => $this->client->project($dependency),
            'latest_version' => (string) $latest['normalized_version'],
            'latest_release_at' => $this->client->latestReleaseAt($latest),
            'releases_by_version' => $releasesByVersion,
        ];
    }

    public function releaseDataForVersion(array $dependency, array $catalog, string $targetVersion, string $fallbackReleaseAt): array
    {
        $project = (string) ($catalog['project'] ?? '');
        $release = $catalog['releases_by_version'][$targetVersion] ?? null;

        if ($project === '' || ! is_array($release)) {
            throw new RuntimeException(sprintf(
                'Could not find GitLab release metadata for %s version %s.',
                $project !== '' ? $project : (string) $dependency['slug'],
                $targetVersion
            ));
        }

        $notesMarkup = $this->client->releaseNotesMarkdown($release, $targetVersion);
        $checksum = $this->expectedChecksumSha256($release, $dependency);

        return [
            'source' => 'gitlab-release',
            'version' => $targetVersion,
            'release' => $release,
            'project' => $project,
            'archive_subdir' => $this->client->archiveSubdir($dependency),
            'release_at' => $this->client->latestReleaseAt($release),
            'notes_markup' => $notesMarkup,
            'notes_text' => $this->client->markdownToText($notesMarkup),
            'source_reference' => sprintf('%s@%s', $project, $targetVersion),
            'checksum_sha256' => $checksum,
            'trust_state' => $checksum !== null ? DependencyTrustState::VERIFIED : DependencyTrustState::METADATA_ONLY,
            'trust_details' => $checksum !== null
                ? 'Archive checksum was independently verified against a release-side checksum sidecar.'
                : 'GitLab release metadata was resolved, but artifact authenticity was not yet independently verified.',
            'source_details' => [
                ['label' => 'Source project', 'value' => sprintf('[`%s`](%s)', $project, $this->client->projectUrl($dependency))],
                ['label' => 'GitLab release', 'value' => sprintf('[Open](%s)', $this->client->releaseUrl($release, $dependency))],
                ['label' => 'Issue tracker', 'value' => sprintf('[Open](%s)', $this->client->issuesUrl($dependency))],
            ],
        ];
    }

    public function downloadReleaseToFile(array $dependency, array $releaseData, string $destination): void
    {
        $release = $releaseData['release'] ?? null;

        if (! is_array($release)) {
            throw new RuntimeException(sprintf(
                'GitLab release data for %s is missing the release payload.',
                (string) $dependency['component_key']
            ));
        }

        $this->client->downloadReleaseToFile($release, $dependency, $destination);
    }

    public function supportsForumSync(array $dependency): bool
    {
        return false;
    }

    /**
     * @param array<string, mixed> $release
     * @param array<string, mixed> $dependency
     */
    private function expectedChecksumSha256(array $release, array $dependency): ?string
    {
        $checksumAssetPattern = $dependency['source_config']['checksum_asset_pattern'] ?? null;

        if (! is_string($checksumAssetPattern) || trim($checksumAssetPattern) === '') {
            return null;
        }

        return $this->client->checksumSha256ForAssetPattern(
            $release,
            $dependency,
            trim($checksumAssetPattern),
            $this->client->assetNameForRelease($release, $dependency)
        );
    }
}
