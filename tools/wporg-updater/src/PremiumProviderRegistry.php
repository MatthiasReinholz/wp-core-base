<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;
use TypeError;

final class PremiumProviderRegistry
{
    private const DEFAULT_RELATIVE_PATH = '.wp-core-base/premium-providers.php';

    /**
     * @param array<string, array{class:string,path:?string}> $definitions
     */
    private function __construct(
        private readonly string $repoRoot,
        private readonly string $path,
        private readonly array $definitions,
        private readonly bool $exists,
    ) {
    }

    public static function load(string $repoRoot): self
    {
        $path = rtrim($repoRoot, '/') . '/' . self::DEFAULT_RELATIVE_PATH;

        if (! is_file($path)) {
            return new self($repoRoot, $path, [], false);
        }

        $loaded = require $path;

        if (! is_array($loaded)) {
            throw new RuntimeException(sprintf('Premium provider registry must return an array: %s', $path));
        }

        $definitions = [];

        foreach ($loaded as $provider => $definition) {
            if (! is_string($provider) || ! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $provider)) {
                throw new RuntimeException(sprintf(
                    'Premium provider keys in %s must use lowercase letters, numbers, and single hyphen separators.',
                    $path
                ));
            }

            if (in_array($provider, ['wordpress.org', 'github-release', 'gitlab-release', 'premium', 'local'], true)) {
                throw new RuntimeException(sprintf(
                    'Premium provider key `%s` is reserved and may not be used in %s.',
                    $provider,
                    $path
                ));
            }

            if (! is_array($definition)) {
                throw new RuntimeException(sprintf('Premium provider %s must define an array in %s.', $provider, $path));
            }

            $class = $definition['class'] ?? null;

            if (! is_string($class) || trim($class) === '') {
                throw new RuntimeException(sprintf('Premium provider %s must define a non-empty class in %s.', $provider, $path));
            }

            $sourcePath = $definition['path'] ?? null;

            if ($sourcePath !== null && (! is_string($sourcePath) || trim($sourcePath) === '')) {
                throw new RuntimeException(sprintf('Premium provider %s path must be a non-empty string when present.', $provider));
            }

            $normalizedPath = null;

            if (is_string($sourcePath)) {
                $normalizedPath = trim(str_replace('\\', '/', $sourcePath), '/');

                if ($normalizedPath === '' || str_contains($normalizedPath, '../') || str_starts_with($normalizedPath, '/')) {
                    throw new RuntimeException(sprintf('Premium provider %s path must be a safe relative path.', $provider));
                }
            }

            $definitions[$provider] = [
                'class' => trim($class),
                'path' => $normalizedPath,
            ];
        }

        ksort($definitions);

        return new self($repoRoot, $path, $definitions, true);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function exists(): bool
    {
        return $this->exists;
    }

    /**
     * @return array<string, array{class:string,path:?string}>
     */
    public function definitions(): array
    {
        return $this->definitions;
    }

    /**
     * @return list<string>
     */
    public function providerKeys(): array
    {
        return array_keys($this->definitions);
    }

    public function hasProvider(string $provider): bool
    {
        return isset($this->definitions[$provider]);
    }

    public function assertProviderRegistered(string $provider): void
    {
        if (! $this->hasProvider($provider)) {
            throw new RuntimeException(sprintf(
                'Premium provider `%s` is not registered. Add it to %s or scaffold it first.',
                $provider,
                self::DEFAULT_RELATIVE_PATH
            ));
        }
    }

    /**
     * @param list<array<string, mixed>> $dependencies
     * @return array<string, PremiumManagedDependencySource>
     */
    public function instantiate(HttpClient $httpClient, PremiumCredentialsStore $credentialsStore, array $dependencies = []): array
    {
        $sources = [];

        foreach ($this->definitions as $provider => $definition) {
            $providerCredentials = $this->scopedCredentialsStoreForProvider($provider, $credentialsStore, $dependencies);
            $path = $definition['path'];

            if ($path !== null) {
                $absolutePath = $this->repoRoot . '/' . $path;

                if (! is_file($absolutePath)) {
                    throw new RuntimeException(sprintf(
                        'Premium provider `%s` points to a missing class file: %s',
                        $provider,
                        $absolutePath
                    ));
                }

                require_once $absolutePath;
            }

            $class = $definition['class'];

            if (! class_exists($class)) {
                throw new RuntimeException(sprintf(
                    'Premium provider `%s` class %s could not be loaded from %s.',
                    $provider,
                    $class,
                    self::DEFAULT_RELATIVE_PATH
                ));
            }

            try {
                $instance = new $class($httpClient, $providerCredentials);
            } catch (TypeError $exception) {
                throw new RuntimeException(sprintf(
                    'Premium provider `%s` class %s must be constructible with (HttpClient, PremiumCredentialsStore).',
                    $provider,
                    $class
                ), previous: $exception);
            }

            if (! $instance instanceof PremiumManagedDependencySource) {
                throw new RuntimeException(sprintf(
                    'Premium provider `%s` class %s must implement PremiumManagedDependencySource.',
                    $provider,
                    $class
                ));
            }

            if ($instance->key() !== $provider) {
                throw new RuntimeException(sprintf(
                    'Premium provider `%s` class %s returned key `%s`. The class key must match the registry key.',
                    $provider,
                    $class,
                    $instance->key()
                ));
            }

            $sources[$provider] = $instance;
        }

        return $sources;
    }

    /**
     * @param list<array<string, mixed>> $dependencies
     * @return list<string>
     */
    private function credentialLookupKeysForProvider(string $provider, PremiumCredentialsStore $credentialsStore, array $dependencies): array
    {
        $lookupKeys = [];

        foreach ($dependencies as $dependency) {
            if (! is_array($dependency)) {
                continue;
            }

            $source = $dependency['source'] ?? null;

            if (! is_string($source) || ! PremiumSourceResolver::isPremiumSource($source)) {
                continue;
            }

            if (PremiumSourceResolver::providerForDependency($dependency) !== $provider) {
                continue;
            }

            foreach ($credentialsStore->lookupKeysFor($dependency) as $lookupKey) {
                $lookupKeys[$lookupKey] = true;
            }
        }

        return array_keys($lookupKeys);
    }

    /**
     * @param list<array<string, mixed>> $dependencies
     */
    private function scopedCredentialsStoreForProvider(
        string $provider,
        PremiumCredentialsStore $credentialsStore,
        array $dependencies,
    ): PremiumCredentialsStore {
        return new ProviderScopedPremiumCredentialsStore(
            $credentialsStore,
            $provider,
            $this->credentialLookupKeysForProvider($provider, $credentialsStore, $dependencies),
        );
    }
}
