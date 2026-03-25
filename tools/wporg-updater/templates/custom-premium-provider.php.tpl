<?php

declare(strict_types=1);

__NAMESPACE_DECLARATION__use RuntimeException;
use WpOrgPluginUpdater\AbstractPremiumManagedSource;

final class __CLASS_NAME__ extends AbstractPremiumManagedSource
{
    public function key(): string
    {
        return '__PROVIDER_KEY__';
    }

    protected function requiredCredentialFields(): array
    {
        return [];
    }

    public function fetchCatalog(array $dependency): array
    {
        // Return at least:
        // - latest_version => string
        // - latest_release_at => ISO-8601 timestamp
        throw new RuntimeException('Implement fetchCatalog() for your premium provider.');
    }

    public function releaseDataForVersion(array $dependency, array $catalog, string $targetVersion, string $fallbackReleaseAt): array
    {
        // Return at least:
        // - version => string
        // - release_at => ISO-8601 timestamp
        // - download_url => string (if downloadReleaseToFile() expects a direct URL)
        throw new RuntimeException('Implement releaseDataForVersion() for your premium provider.');
    }

    public function downloadReleaseToFile(array $dependency, array $releaseData, string $destination): void
    {
        // Write the release archive ZIP to $destination.
        throw new RuntimeException('Implement downloadReleaseToFile() for your premium provider.');
    }
}
