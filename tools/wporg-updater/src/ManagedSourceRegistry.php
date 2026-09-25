<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class ManagedSourceRegistry
{
    /** @var array<string, ManagedDependencySource> */
    private array $sources = [];

    /**
     * @param ManagedDependencySource ...$sources
     */
    public function __construct(ManagedDependencySource ...$sources)
    {
        foreach ($sources as $source) {
            $key = $source->key();
            if ($key === '' || trim($key) !== $key || preg_match('/^[a-z0-9]+(?:[-.][a-z0-9]+)*$/D', $key) !== 1) {
                throw new RuntimeException('Managed source registry keys must be non-empty lowercase identifiers.');
            }
            if (isset($this->sources[$key])) {
                throw new RuntimeException(sprintf('Duplicate managed source registry key: %s.', $key));
            }
            $this->sources[$key] = $source;
        }
    }

    /** @param array<string,mixed> $dependency */
    public function supportsHistoricalVersions(array $dependency): bool
    {
        $source = $this->for($dependency);
        return ! $source instanceof HistoricalVersionSource || $source->supportsHistoricalVersions($dependency);
    }

    /** @param array<string,mixed> $dependency @return array<string,mixed> */
    public function fetchCatalog(array $dependency): array
    {
        $source = $this->for($dependency);
        return SourceCatalog::fromArray($source->fetchCatalog($dependency), $source->key())->toArray();
    }

    /** @param array<string,mixed> $dependency @param array<string,mixed> $catalog @return array<string,mixed> */
    public function releaseDataForVersion(array $dependency, array $catalog, string $targetVersion, string $fallbackReleaseAt): array
    {
        $source = $this->for($dependency);
        $validatedCatalog = SourceCatalog::fromArray($catalog, $source->key())->toArray();
        if (! $this->supportsHistoricalVersions($dependency) && $targetVersion !== $validatedCatalog['latest_version']) {
            throw new RuntimeException(sprintf('Source %s only resolves the currently advertised version %s.', $source->key(), $validatedCatalog['latest_version']));
        }
        return SourceRelease::fromArray(
            $source->releaseDataForVersion($dependency, $validatedCatalog, $targetVersion, $fallbackReleaseAt),
            $source->key(),
            $targetVersion
        )->toArray();
    }

    /**
     * @param array<string, mixed> $dependency
     */
    public function for(array $dependency): ManagedDependencySource
    {
        $key = $dependency['source'] ?? null;

        if (! is_string($key) || $key === '') {
            throw new RuntimeException(sprintf(
                'Unsupported managed dependency source: %s',
                is_scalar($key) ? (string) $key : gettype($key)
            ));
        }

        $lookupKey = $key;

        if ($key === 'premium') {
            $provider = PremiumSourceResolver::providerForDependency($dependency);

            if ($provider === null) {
                throw new RuntimeException('Premium dependencies must define a provider.');
            }

            $lookupKey = $provider;
        }

        if (! isset($this->sources[$lookupKey])) {
            throw new RuntimeException(sprintf('Unsupported managed dependency source: %s', $lookupKey));
        }

        return $this->sources[$lookupKey];
    }
}
