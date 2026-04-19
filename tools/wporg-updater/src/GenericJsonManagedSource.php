<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use DateTimeImmutable;
use RuntimeException;

final class GenericJsonManagedSource implements ManagedDependencySource
{
    public function __construct(
        private readonly JsonHttpTransport $httpClient,
    ) {
    }

    public function key(): string
    {
        return 'generic-json';
    }

    public function fetchCatalog(array $dependency): array
    {
        $metadataUrl = $this->metadataUrl($dependency);
        $metadata = $this->fetchMetadata($metadataUrl);

        return [
            'source' => 'generic-json',
            'metadata_url' => $metadataUrl,
            'metadata' => $metadata,
            'latest_version' => $this->requiredMetadataString($metadata, 'version', $metadataUrl),
            'latest_release_at' => $this->releaseAt($metadata, $metadataUrl),
        ];
    }

    public function releaseDataForVersion(array $dependency, array $catalog, string $targetVersion, string $fallbackReleaseAt): array
    {
        $metadata = $catalog['metadata'] ?? null;
        $metadataUrl = (string) ($catalog['metadata_url'] ?? '');
        $latestVersion = (string) ($catalog['latest_version'] ?? '');

        if (! is_array($metadata) || $metadataUrl === '' || $latestVersion === '') {
            throw new RuntimeException(sprintf(
                'Missing generic JSON metadata for %s.',
                (string) ($dependency['component_key'] ?? $dependency['slug'] ?? 'unknown')
            ));
        }

        if ($targetVersion !== $latestVersion) {
            throw new RuntimeException(sprintf(
                'Generic JSON metadata at %s currently advertises only version %s. Requested version %s cannot be resolved from that endpoint.',
                $metadataUrl,
                $latestVersion,
                $targetVersion
            ));
        }

        $notesMarkup = $this->notesMarkup($metadata, $targetVersion);
        $detailsUrl = $this->optionalHttpsMetadataUrl($metadata, ['details_url', 'homepage']);

        $sourceDetails = [
            ['label' => 'Metadata endpoint', 'value' => sprintf('[Open](%s)', $metadataUrl)],
        ];

        if ($detailsUrl !== null) {
            $sourceDetails[] = ['label' => 'Distribution page', 'value' => sprintf('[Open](%s)', $detailsUrl)];
        }

        return [
            'source' => 'generic-json',
            'version' => $targetVersion,
            'download_url' => $this->downloadUrl($metadata, $metadataUrl),
            'archive_subdir' => trim((string) ($dependency['archive_subdir'] ?? ''), '/'),
            'release_at' => (string) ($catalog['latest_release_at'] ?? $fallbackReleaseAt),
            'notes_markup' => $notesMarkup,
            'notes_text' => $this->markupToText($notesMarkup, $targetVersion),
            'source_reference' => $metadataUrl,
            'trust_state' => DependencyTrustState::METADATA_ONLY,
            'trust_details' => 'Generic JSON metadata was resolved, but artifact authenticity was not independently verified.',
            'source_details' => $sourceDetails,
        ];
    }

    public function downloadReleaseToFile(array $dependency, array $releaseData, string $destination): void
    {
        $downloadUrl = $releaseData['download_url'] ?? null;

        if (! is_string($downloadUrl) || $downloadUrl === '') {
            throw new RuntimeException(sprintf(
                'Generic JSON release data for %s is missing download_url.',
                (string) ($dependency['component_key'] ?? $dependency['slug'] ?? 'unknown')
            ));
        }

        $this->assertHttpsUrl($downloadUrl, 'generic-json download_url');

        $this->httpClient->downloadToFileWithOptions($downloadUrl, $destination, [], [
            'max_redirects' => 5,
            'max_download_bytes' => 512 * 1024 * 1024,
        ]);
    }

    public function supportsForumSync(array $dependency): bool
    {
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchMetadata(string $metadataUrl): array
    {
        return $this->httpClient->getJsonWithOptions($metadataUrl, [
            'Accept' => 'application/json',
        ], [
            'max_body_bytes' => 1024 * 1024,
        ]);
    }

    /**
     * @param array<string, mixed> $dependency
     */
    private function metadataUrl(array $dependency): string
    {
        $metadataUrl = $dependency['source_config']['generic_json_url'] ?? null;

        if (! is_string($metadataUrl) || trim($metadataUrl) === '') {
            throw new RuntimeException(sprintf(
                'Dependency %s is missing source_config.generic_json_url.',
                (string) ($dependency['slug'] ?? 'unknown')
            ));
        }

        return $this->assertHttpsUrl(trim($metadataUrl), 'source_config.generic_json_url');
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function downloadUrl(array $metadata, string $metadataUrl): string
    {
        foreach (['download_url', 'download_link'] as $field) {
            $value = $metadata[$field] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return $this->assertHttpsUrl(trim($value), sprintf('%s %s', $metadataUrl, $field));
            }
        }

        throw new RuntimeException(sprintf(
            'Generic JSON metadata at %s must define a non-empty HTTPS download_url.',
            $metadataUrl
        ));
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function releaseAt(array $metadata, string $metadataUrl): string
    {
        foreach (['release_at', 'published_at', 'last_updated', 'updated'] as $field) {
            $value = $metadata[$field] ?? null;

            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            try {
                return (new DateTimeImmutable(trim($value)))->format(DATE_ATOM);
            } catch (\Throwable) {
                continue;
            }
        }

        throw new RuntimeException(sprintf(
            'Generic JSON metadata at %s must define a valid release_at, published_at, last_updated, or updated timestamp.',
            $metadataUrl
        ));
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function notesMarkup(array $metadata, string $targetVersion): string
    {
        $sections = $metadata['sections'] ?? null;

        if (is_array($sections)) {
            foreach (['changelog', 'upgrade_notice', 'description'] as $field) {
                $value = $sections[$field] ?? null;

                if (is_string($value) && trim($value) !== '') {
                    return trim($value);
                }
            }
        }

        foreach (['changelog', 'upgrade_notice', 'description'] as $field) {
            $value = $metadata[$field] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return sprintf('_Release notes unavailable for version %s._', $targetVersion);
    }

    /**
     * @param array<string, mixed> $metadata
     * @param list<string> $fields
     */
    private function optionalHttpsMetadataUrl(array $metadata, array $fields): ?string
    {
        foreach ($fields as $field) {
            $value = $metadata[$field] ?? null;

            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            try {
                return $this->assertHttpsUrl(trim($value), $field);
            } catch (RuntimeException) {
                continue;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function requiredMetadataString(array $metadata, string $field, string $metadataUrl): string
    {
        $value = $metadata[$field] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException(sprintf(
                'Generic JSON metadata at %s must define a non-empty %s field.',
                $metadataUrl,
                $field
            ));
        }

        return trim($value);
    }

    private function markupToText(string $markup, string $targetVersion): string
    {
        $text = trim(
            preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($markup), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? ''
        );

        if ($text !== '') {
            return $text;
        }

        return sprintf('Release notes unavailable for version %s.', $targetVersion);
    }

    private function assertHttpsUrl(string $url, string $label): string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || trim((string) ($parts['host'] ?? '')) === '') {
            throw new RuntimeException(sprintf('%s must be an HTTPS URL: %s', $label, $url));
        }

        return $url;
    }
}
