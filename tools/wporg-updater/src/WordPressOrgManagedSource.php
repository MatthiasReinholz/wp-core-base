<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class WordPressOrgManagedSource implements ManagedDependencySource
{
    /** @var list<string> */
    private const ALLOWED_REDIRECT_HOSTS = [
        'api.wordpress.org',
        'wordpress.org',
        'downloads.wordpress.org',
        'downloads.w.org',
    ];

    public function __construct(
        private readonly WordPressOrgClient $client,
        private readonly HttpClient $httpClient,
    ) {
    }

    public function key(): string
    {
        return 'wordpress.org';
    }

    public function fetchCatalog(array $dependency): array
    {
        $info = $this->client->fetchComponentInfo((string) $dependency['kind'], (string) $dependency['slug']);

        return [
            'source' => 'wordpress.org',
            'info' => $info,
            'latest_version' => $this->client->latestVersion((string) $dependency['kind'], $info),
            'latest_release_at' => $this->client->latestReleaseAt((string) $dependency['kind'], $info),
        ];
    }

    public function releaseDataForVersion(array $dependency, array $catalog, string $targetVersion, string $fallbackReleaseAt): array
    {
        $info = $catalog['info'] ?? null;

        if (! is_array($info)) {
            throw new RuntimeException(sprintf('Missing WordPress.org catalog metadata for %s.', (string) $dependency['slug']));
        }

        $componentUrl = $this->client->componentUrl((string) $dependency['kind'], (string) $dependency['slug']);
        $sourceDetails = [
            ['label' => 'WordPress.org page', 'value' => sprintf('[Open](%s)', $componentUrl)],
        ];

        if ((string) $dependency['kind'] === 'plugin') {
            $sourceDetails[] = [
                'label' => 'WordPress.org support forum',
                'value' => sprintf('[Open](%s)', $this->client->supportUrl((string) $dependency['slug'])),
            ];
        }

        $notesMarkup = $this->client->extractReleaseNotes((string) $dependency['kind'], $info, $targetVersion);

        return [
            'source' => 'wordpress.org',
            'version' => $targetVersion,
            'download_url' => $this->client->downloadUrlForVersion((string) $dependency['kind'], $info, $targetVersion),
            'archive_subdir' => trim((string) $dependency['archive_subdir'], '/'),
            'release_at' => $fallbackReleaseAt,
            'notes_markup' => $notesMarkup,
            'notes_text' => $this->client->htmlToText($notesMarkup),
            'source_reference' => $componentUrl,
            'trust_state' => DependencyTrustState::METADATA_ONLY,
            'trust_details' => 'WordPress.org plugin/theme APIs do not provide version-bound archive checksums for independent artifact verification.',
            'source_details' => $sourceDetails,
        ];
    }

    public function downloadReleaseToFile(array $dependency, array $releaseData, string $destination): void
    {
        $this->httpClient->downloadToFileWithOptions((string) $releaseData['download_url'], $destination, [], [
            'allowed_redirect_hosts' => self::ALLOWED_REDIRECT_HOSTS,
            'strip_auth_on_cross_origin_redirect' => true,
            'max_redirects' => 3,
            'max_download_bytes' => 512 * 1024 * 1024,
        ]);
    }

    public function supportsForumSync(array $dependency): bool
    {
        return (string) $dependency['kind'] === 'plugin';
    }
}
