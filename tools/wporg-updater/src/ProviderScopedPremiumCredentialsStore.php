<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class ProviderScopedPremiumCredentialsStore extends PremiumCredentialsStore
{
    /** @var array<string, bool> */
    private array $allowedLookupKeys;

    /**
     * @param list<string> $allowedLookupKeys
     */
    public function __construct(
        private readonly PremiumCredentialsStore $baseStore,
        private readonly string $provider,
        array $allowedLookupKeys,
    ) {
        parent::__construct(null);
        $this->allowedLookupKeys = array_fill_keys($allowedLookupKeys, true);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        $all = $this->baseStore->all();

        if ($this->allowedLookupKeys === []) {
            return [];
        }

        $scoped = [];

        foreach (array_keys($this->allowedLookupKeys) as $lookupKey) {
            $credentials = $all[$lookupKey] ?? null;

            if (is_array($credentials)) {
                $scoped[$lookupKey] = $credentials;
            }
        }

        return $scoped;
    }

    /**
     * @param array<string, mixed> $dependency
     * @return array<string, mixed>
     */
    public function credentialsFor(array $dependency): array
    {
        $this->assertDependencyBelongsToProvider($dependency);

        foreach ($this->baseStore->lookupKeysFor($dependency) as $lookupKey) {
            $credentials = $this->all()[$lookupKey] ?? null;

            if (is_array($credentials)) {
                return $credentials;
            }
        }

        throw new RuntimeException(sprintf(
            'Premium credentials are missing for %s. Provide %s with an entry for a key owned by provider `%s`.',
            (string) ($dependency['component_key'] ?? 'unknown'),
            self::envName(),
            $this->provider
        ));
    }

    /**
     * @param array<string, mixed> $dependency
     */
    private function assertDependencyBelongsToProvider(array $dependency): void
    {
        $dependencyProvider = PremiumSourceResolver::providerForDependency($dependency);

        if (! is_string($dependencyProvider) || $dependencyProvider !== $this->provider) {
            throw new RuntimeException(sprintf(
                'Provider-scoped credentials for `%s` cannot resolve dependency for provider `%s`.',
                $this->provider,
                is_string($dependencyProvider) && $dependencyProvider !== '' ? $dependencyProvider : 'unknown'
            ));
        }
    }
}

