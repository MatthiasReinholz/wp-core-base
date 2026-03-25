<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use DateTimeImmutable;
use RuntimeException;

final class RoleEditorProManagedSource extends AbstractPremiumManagedSource
{
    public function key(): string
    {
        return 'role-editor-pro';
    }

    public function fetchCatalog(array $dependency): array
    {
        $credentials = $this->credentialsFor($dependency, ['license_key']);
        $metadataUrl = sprintf(
            'https://update.role-editor.com/?action=get_metadata&slug=%s&license_key=%s',
            rawurlencode((string) $dependency['slug']),
            rawurlencode((string) $credentials['license_key'])
        );
        $metadata = $this->requestJson('GET', $metadataUrl);
        $latestVersion = $this->stringField($metadata, ['version', 'new_version']);
        $releaseAt = $this->nullableDateField($metadata, ['last_updated', 'updated']) ?? gmdate(DATE_ATOM);

        return [
            'source' => $this->key(),
            'latest_version' => $latestVersion,
            'latest_release_at' => $releaseAt,
            'metadata' => $metadata,
        ];
    }

    public function releaseDataForVersion(array $dependency, array $catalog, string $targetVersion, string $fallbackReleaseAt): array
    {
        $latestVersion = (string) ($catalog['latest_version'] ?? '');

        if ($latestVersion === '' || version_compare($targetVersion, $latestVersion, '!==')) {
            throw new RuntimeException(sprintf(
                'User Role Editor Pro currently exposes only the latest downloadable package through workflow automation. Requested %s, latest is %s.',
                $targetVersion,
                $latestVersion !== '' ? $latestVersion : 'unknown'
            ));
        }

        $metadata = $catalog['metadata'] ?? null;

        if (! is_array($metadata)) {
            throw new RuntimeException('User Role Editor Pro catalog is missing metadata.');
        }

        $downloadUrl = $this->stringField($metadata, ['download_url', 'download_link', 'package']);
        $notesMarkup = $this->releaseNotesMarkup($metadata, $targetVersion);

        return [
            'source' => $this->key(),
            'version' => $targetVersion,
            'release_at' => (string) ($catalog['latest_release_at'] ?? $fallbackReleaseAt),
            'archive_subdir' => trim((string) $dependency['archive_subdir'], '/'),
            'download_url' => $downloadUrl,
            'notes_markup' => $notesMarkup,
            'notes_text' => trim(strip_tags($notesMarkup)),
            'source_reference' => 'https://update.role-editor.com/',
            'source_details' => [
                ['label' => 'Vendor source', 'value' => '[Open](https://update.role-editor.com/)'],
                ['label' => 'Update contract', 'value' => '`role-editor-pro` premium source'],
            ],
        ];
    }

    public function downloadReleaseToFile(array $dependency, array $releaseData, string $destination): void
    {
        $this->downloadBinary((string) $releaseData['download_url'], $destination);
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

        throw new RuntimeException(sprintf('User Role Editor Pro response did not contain any of: %s.', implode(', ', $fields)));
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
        foreach (['sections', 'changelog', 'upgrade_notice'] as $field) {
            $value = $payload[$field] ?? null;

            if (is_array($value) && isset($value['changelog']) && is_string($value['changelog']) && trim($value['changelog']) !== '') {
                return trim($value['changelog']);
            }

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return sprintf('<p><em>Release notes unavailable for version %s.</em></p>', htmlspecialchars($targetVersion, ENT_QUOTES));
    }
}
