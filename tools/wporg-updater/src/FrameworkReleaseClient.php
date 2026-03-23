<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class FrameworkReleaseClient
{
    public function __construct(
        private readonly GitHubReleaseClient $gitHubReleaseClient,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchStableReleases(FrameworkConfig $framework): array
    {
        return $this->gitHubReleaseClient->fetchStableReleases($this->dependencyShape($framework));
    }

    /**
     * @param array<string, mixed> $release
     * @return array<string, mixed>
     */
    public function releaseData(FrameworkConfig $framework, array $release): array
    {
        $version = $this->gitHubReleaseClient->latestVersion($release, $this->dependencyShape($framework));
        $releaseNotes = trim((string) ($release['body'] ?? ''));

        if ($releaseNotes === '') {
            throw new RuntimeException(sprintf('Framework release v%s is missing release notes.', $version));
        }

        $missingSections = FrameworkReleaseNotes::missingRequiredSections($releaseNotes);

        if ($missingSections !== []) {
            throw new RuntimeException(sprintf(
                'Framework release v%s is missing required release-note sections: %s.',
                $version,
                implode(', ', $missingSections)
            ));
        }

        return [
            'version' => $version,
            'release_at' => $this->gitHubReleaseClient->latestReleaseAt($release),
            'release_url' => $this->gitHubReleaseClient->releaseUrl($release, $framework->repository),
            'notes_markdown' => $releaseNotes,
            'notes_text' => $this->gitHubReleaseClient->markdownToText($releaseNotes),
            'notes_sections' => FrameworkReleaseNotes::parseSections($releaseNotes),
            'release' => $release,
        ];
    }

    /**
     * @param array<string, mixed> $release
     */
    public function downloadReleaseAsset(FrameworkConfig $framework, array $release, string $destination): void
    {
        $this->gitHubReleaseClient->downloadReleaseToFile($release, $this->dependencyShape($framework), $destination);
    }

    /**
     * @return array<string, mixed>
     */
    private function dependencyShape(FrameworkConfig $framework): array
    {
        return [
            'slug' => 'wp-core-base',
            'source_config' => [
                'github_repository' => $framework->repository,
                'github_release_asset_pattern' => $framework->assetName(),
                'github_token_env' => null,
            ],
            'archive_subdir' => '',
        ];
    }
}
