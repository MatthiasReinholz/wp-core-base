<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class FreemiusPremiumManagedSource extends AbstractPremiumManagedSource
{
    public function key(): string
    {
        return 'freemius-premium';
    }

    public function fetchCatalog(array $dependency): array
    {
        $productId = $this->productId($dependency);
        [$scopeUrl, $headers] = $this->resolvedScope($dependency, $productId);
        $metadataUrl = $scopeUrl . '/updates/latest.json?is_premium=true&type=all&readme=true';
        $metadata = $this->requestJson('GET', $metadataUrl, $headers);
        $latestVersion = $this->stringField($metadata, ['version']);

        return [
            'source' => $this->key(),
            'latest_version' => $latestVersion,
            'latest_release_at' => (string) ($metadata['updated'] ?? $metadata['created'] ?? gmdate(DATE_ATOM)),
            'metadata' => $metadata,
            'scope_url' => $scopeUrl,
            'headers' => $headers,
            'product_id' => $productId,
        ];
    }

    public function releaseDataForVersion(array $dependency, array $catalog, string $targetVersion, string $fallbackReleaseAt): array
    {
        $latestVersion = (string) ($catalog['latest_version'] ?? '');

        if ($latestVersion === '' || version_compare($targetVersion, $latestVersion, '!==')) {
            throw new RuntimeException(sprintf(
                'Freemius premium workflow support currently exposes only the latest downloadable package. Requested %s, latest is %s.',
                $targetVersion,
                $latestVersion !== '' ? $latestVersion : 'unknown'
            ));
        }

        $metadata = $catalog['metadata'] ?? null;

        if (! is_array($metadata)) {
            throw new RuntimeException('Freemius premium catalog is missing metadata.');
        }

        $notesMarkup = $this->releaseNotesMarkup($metadata, $targetVersion);

        return [
            'source' => $this->key(),
            'version' => $targetVersion,
            'release_at' => (string) ($catalog['latest_release_at'] ?? $fallbackReleaseAt),
            'archive_subdir' => trim((string) $dependency['archive_subdir'], '/'),
            'download_url' => (string) $catalog['scope_url'] . '/updates/latest.zip?is_premium=true&type=all',
            'headers' => (array) ($catalog['headers'] ?? []),
            'notes_markup' => $notesMarkup,
            'notes_text' => trim(strip_tags($notesMarkup)),
            'source_reference' => (string) $catalog['scope_url'],
            'source_details' => [
                ['label' => 'Vendor source', 'value' => '[Open](https://api.freemius.com/)'],
                ['label' => 'Update contract', 'value' => '`freemius-premium` premium source'],
            ],
        ];
    }

    public function downloadReleaseToFile(array $dependency, array $releaseData, string $destination): void
    {
        $this->downloadBinary((string) $releaseData['download_url'], $destination, (array) ($releaseData['headers'] ?? []));
    }

    private function productId(array $dependency): int
    {
        $configured = $dependency['source_config']['provider_product_id'] ?? null;

        if (is_numeric($configured) && (int) $configured > 0) {
            return (int) $configured;
        }

        return match ((string) ($dependency['slug'] ?? '')) {
            'blocksy-companion-pro' => 5115,
            default => throw new RuntimeException(sprintf(
                'freemius-premium dependency %s must define source_config.provider_product_id.',
                (string) ($dependency['component_key'] ?? 'unknown')
            )),
        };
    }

    /**
     * @return array{0:string,1:array<string,string>}
     */
    private function resolvedScope(array $dependency, int $productId): array
    {
        $credentials = $this->credentialsFor($dependency);

        $apiToken = $credentials['api_token'] ?? null;

        if (is_string($apiToken) && trim($apiToken) !== '') {
            return [
                sprintf('https://api.freemius.com/v1/products/%d', $productId),
                ['Authorization' => 'Bearer ' . trim($apiToken)],
            ];
        }

        $installId = $credentials['install_id'] ?? null;
        $installApiToken = $credentials['install_api_token'] ?? null;

        if (is_numeric($installId) && is_string($installApiToken) && trim($installApiToken) !== '') {
            return [
                sprintf('https://api.freemius.com/v1/products/%d/installs/%d', $productId, (int) $installId),
                ['Authorization' => 'Bearer ' . trim($installApiToken)],
            ];
        }

        $licenseKey = $credentials['license_key'] ?? null;
        $siteUrl = $credentials['site_url'] ?? null;

        if (! is_string($licenseKey) || trim($licenseKey) === '' || ! is_string($siteUrl) || trim($siteUrl) === '') {
            throw new RuntimeException(sprintf(
                'freemius-premium dependency %s requires either api_token, install_id + install_api_token, or license_key + site_url in %s.',
                (string) ($dependency['component_key'] ?? 'unknown'),
                PremiumCredentialsStore::envName()
            ));
        }

        $uid = $this->installationUid((string) $dependency['slug'], $siteUrl);
        $activationUrl = sprintf(
            'https://api.freemius.com/v1/products/%d/licenses/activate.json?uid=%s&license_key=%s',
            $productId,
            rawurlencode($uid),
            rawurlencode(trim($licenseKey))
        );

        try {
            $result = $this->requestJson('POST', $activationUrl, [], ['site_url' => trim($siteUrl)]);
        } catch (RuntimeException $exception) {
            throw new RuntimeException(
                'Freemius activation did not succeed with license_key + site_url alone. Provide api_token or install_id + install_api_token for reliable workflow updates.',
                previous: $exception
            );
        }

        $activatedInstallId = $result['install_id'] ?? $result['install']['id'] ?? null;
        $activatedToken = $result['install_api_token'] ?? $result['install']['api_token'] ?? null;

        if (! is_numeric($activatedInstallId) || ! is_string($activatedToken) || trim($activatedToken) === '') {
            throw new RuntimeException(
                'Freemius activation response did not return install_id and install_api_token. Provide api_token or install_id + install_api_token for reliable workflow updates.'
            );
        }

        return [
            sprintf('https://api.freemius.com/v1/products/%d/installs/%d', $productId, (int) $activatedInstallId),
            ['Authorization' => 'Bearer ' . trim($activatedToken)],
        ];
    }

    private function installationUid(string $slug, string $siteUrl): string
    {
        return substr(hash('sha256', strtolower(trim($slug)) . '|' . strtolower(trim($siteUrl))), 0, 32);
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

        throw new RuntimeException(sprintf('Freemius response did not contain any of: %s.', implode(', ', $fields)));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function releaseNotesMarkup(array $payload, string $targetVersion): string
    {
        $readme = $payload['readme'] ?? null;

        if (is_array($readme)) {
            $sections = $readme['sections'] ?? null;

            if (is_array($sections) && isset($sections['changelog']) && is_string($sections['changelog']) && trim($sections['changelog']) !== '') {
                return trim($sections['changelog']);
            }
        }

        $upgradeNotice = $payload['upgrade_notice'] ?? null;

        if (is_string($upgradeNotice) && trim($upgradeNotice) !== '') {
            return trim($upgradeNotice);
        }

        return sprintf('<p><em>Release notes unavailable for version %s.</em></p>', htmlspecialchars($targetVersion, ENT_QUOTES));
    }
}
