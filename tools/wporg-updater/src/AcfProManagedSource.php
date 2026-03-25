<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use DateTimeImmutable;
use RuntimeException;

final class AcfProManagedSource extends AbstractPremiumManagedSource
{
    public function key(): string
    {
        return 'acf-pro';
    }

    public function fetchCatalog(array $dependency): array
    {
        $credentials = $this->credentialsFor($dependency, ['license_key', 'site_url']);
        $response = $this->requestJson(
            'POST',
            'https://connect.advancedcustomfields.com/v2/plugins/update-check',
            $this->headers($credentials),
            [
                'plugins' => json_encode([[
                    'id' => 'pro',
                    'slug' => (string) $dependency['slug'],
                    'basename' => (string) $dependency['main_file'],
                    'version' => (string) ($dependency['version'] ?? ''),
                ]], JSON_THROW_ON_ERROR),
                'wp' => json_encode([
                    'wp_url' => $credentials['site_url'],
                    'php_version' => PHP_VERSION,
                ], JSON_THROW_ON_ERROR),
                'acf' => json_encode([
                    'acf_version' => (string) ($dependency['version'] ?? ''),
                    'acf_pro' => true,
                    'block_count' => 0,
                ], JSON_THROW_ON_ERROR),
            ]
        );

        $version = $this->stringField($response, ['version', 'new_version']);
        $releaseAt = $this->nullableDateField($response, ['last_updated', 'updated_at']) ?? gmdate(DATE_ATOM);

        return [
            'source' => $this->key(),
            'latest_version' => $version,
            'latest_release_at' => $releaseAt,
            'latest_payload' => $response,
        ];
    }

    public function releaseDataForVersion(array $dependency, array $catalog, string $targetVersion, string $fallbackReleaseAt): array
    {
        $latestVersion = (string) ($catalog['latest_version'] ?? '');

        if ($latestVersion === '' || version_compare($targetVersion, $latestVersion, '!==')) {
            throw new RuntimeException(sprintf(
                'ACF PRO currently exposes only the latest downloadable package through workflow automation. Requested %s, latest is %s.',
                $targetVersion,
                $latestVersion !== '' ? $latestVersion : 'unknown'
            ));
        }

        $payload = $catalog['latest_payload'] ?? null;

        if (! is_array($payload)) {
            throw new RuntimeException('ACF PRO catalog is missing the latest payload.');
        }

        $notesMarkup = $this->releaseNotesMarkup($payload, $targetVersion);
        $downloadUrl = $this->stringField($payload, ['download_url', 'package', 'download_link']);

        return [
            'source' => $this->key(),
            'version' => $targetVersion,
            'release_at' => (string) ($catalog['latest_release_at'] ?? $fallbackReleaseAt),
            'archive_subdir' => trim((string) $dependency['archive_subdir'], '/'),
            'download_url' => $downloadUrl,
            'notes_markup' => $notesMarkup,
            'notes_text' => trim(strip_tags($notesMarkup)),
            'source_reference' => 'https://connect.advancedcustomfields.com/v2/plugins/update-check',
            'source_details' => [
                ['label' => 'Vendor source', 'value' => '[Open](https://connect.advancedcustomfields.com/)'],
                ['label' => 'Update contract', 'value' => '`acf-pro` premium source'],
            ],
        ];
    }

    public function downloadReleaseToFile(array $dependency, array $releaseData, string $destination): void
    {
        $this->downloadBinary((string) $releaseData['download_url'], $destination);
    }

    /**
     * @param array<string, mixed> $credentials
     * @return array<string, string>
     */
    private function headers(array $credentials): array
    {
        $headers = [
            'X-ACF-Version' => '3.0',
            'X-ACF-URL' => (string) $credentials['site_url'],
            'X-ACF-License' => (string) $credentials['license_key'],
            'X-ACF-Plugin' => 'pro',
        ];

        $releaseAccessKey = $credentials['release_access_key'] ?? null;

        if (is_string($releaseAccessKey) && trim($releaseAccessKey) !== '') {
            $headers['X-ACF-Release-Access-Key'] = trim($releaseAccessKey);
        }

        return $headers;
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $fields
     */
    private function stringField(array $payload, array $fields): string
    {
        foreach ($fields as $field) {
            $value = $payload[$field] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        throw new RuntimeException(sprintf('ACF PRO response did not contain any of: %s.', implode(', ', $fields)));
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $fields
     */
    private function nullableDateField(array $payload, array $fields): ?string
    {
        foreach ($fields as $field) {
            $value = $payload[$field] ?? null;

            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            return (new DateTimeImmutable($value))->format(DATE_ATOM);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function releaseNotesMarkup(array $payload, string $targetVersion): string
    {
        foreach (['changelog', 'upgrade_notice', 'description'] as $field) {
            $value = $payload[$field] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return sprintf('<p><em>Release notes unavailable for version %s.</em></p>', htmlspecialchars($targetVersion, ENT_QUOTES));
    }
}
