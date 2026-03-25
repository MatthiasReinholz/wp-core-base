<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class GitHubReleaseManagedSource implements ManagedDependencySource
{
    public function __construct(
        private readonly GitHubReleaseClient $client,
    ) {
    }

    public function key(): string
    {
        return 'github-release';
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
            'source' => 'github-release',
            'repository' => $this->client->repository($dependency),
            'latest_version' => (string) $latest['normalized_version'],
            'latest_release_at' => $this->client->latestReleaseAt($latest),
            'releases_by_version' => $releasesByVersion,
        ];
    }

    public function releaseDataForVersion(array $dependency, array $catalog, string $targetVersion, string $fallbackReleaseAt): array
    {
        $repository = (string) ($catalog['repository'] ?? '');
        $release = $catalog['releases_by_version'][$targetVersion] ?? null;

        if ($repository === '' || ! is_array($release)) {
            throw new RuntimeException(sprintf(
                'Could not find GitHub release metadata for %s version %s.',
                $repository !== '' ? $repository : (string) $dependency['slug'],
                $targetVersion
            ));
        }

        $notesMarkup = $this->client->releaseNotesMarkdown($release, $targetVersion);

        return [
            'source' => 'github-release',
            'version' => $targetVersion,
            'release' => $release,
            'repository' => $repository,
            'archive_subdir' => $this->client->archiveSubdir($dependency),
            'release_at' => $this->client->latestReleaseAt($release),
            'notes_markup' => $notesMarkup,
            'notes_text' => $this->client->markdownToText($notesMarkup),
            'source_reference' => sprintf('%s@%s', $repository, $targetVersion),
            'source_details' => [
                ['label' => 'Source repository', 'value' => sprintf('[`%s`](https://github.com/%s)', $repository, $repository)],
                ['label' => 'GitHub release', 'value' => sprintf('[Open](%s)', $this->client->releaseUrl($release, $repository))],
                ['label' => 'Issue tracker', 'value' => sprintf('[Open](%s)', $this->client->issuesUrl($repository))],
            ],
        ];
    }

    public function downloadReleaseToFile(array $dependency, array $releaseData, string $destination): void
    {
        $release = $releaseData['release'] ?? null;

        if (! is_array($release)) {
            throw new RuntimeException(sprintf(
                'GitHub release data for %s is missing the release payload.',
                (string) $dependency['component_key']
            ));
        }

        $this->client->downloadReleaseToFile($release, $dependency, $destination);
    }

    public function supportsForumSync(array $dependency): bool
    {
        return false;
    }
}
