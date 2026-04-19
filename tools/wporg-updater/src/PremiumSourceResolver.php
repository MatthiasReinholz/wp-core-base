<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class PremiumSourceResolver
{
    /**
     * @return list<string>
     */
    public static function allowedSources(): array
    {
        return ['wordpress.org', 'github-release', 'gitlab-release', 'generic-json', 'premium', 'local'];
    }

    public static function isPremiumSource(string $source): bool
    {
        return $source === 'premium';
    }

    /**
     * @param array<string, mixed> $sourceConfig
     */
    public static function componentKey(string $kind, string $source, string $slug, array $sourceConfig = []): string
    {
        if (! self::isPremiumSource($source)) {
            return sprintf('%s:%s:%s', $kind, $source, $slug);
        }

        $provider = self::providerFor($source, $sourceConfig);

        return sprintf('%s:%s:%s:%s', $kind, $source, $provider, $slug);
    }

    public static function legacyPremiumComponentKey(string $kind, string $slug): string
    {
        return sprintf('%s:premium:%s', $kind, $slug);
    }

    /**
     * @param array<string, mixed> $dependency
     * @return list<string>
     */
    public static function legacyComponentKeysForDependency(array $dependency): array
    {
        $source = $dependency['source'] ?? null;
        $kind = $dependency['kind'] ?? null;
        $slug = $dependency['slug'] ?? null;

        if (! is_string($source) || ! self::isPremiumSource($source) || ! is_string($kind) || ! is_string($slug)) {
            return [];
        }

        return [self::legacyPremiumComponentKey($kind, $slug)];
    }

    /**
     * @param array<string, mixed> $dependency
     */
    public static function matchesComponentKey(array $dependency, string $key): bool
    {
        $componentKey = $dependency['component_key'] ?? null;

        if (is_string($componentKey) && $componentKey === $key) {
            return true;
        }

        return in_array($key, self::legacyComponentKeysForDependency($dependency), true);
    }

    /**
     * @param array<string, mixed> $dependency
     */
    public static function providerForDependency(array $dependency): ?string
    {
        $source = $dependency['source'] ?? null;

        if (! is_string($source) || $source === '') {
            return null;
        }

        return self::providerFor($source, is_array($dependency['source_config'] ?? null) ? $dependency['source_config'] : []);
    }

    /**
     * @param array<string, mixed> $sourceConfig
     */
    public static function providerFor(string $source, array $sourceConfig = []): ?string
    {
        if ($source !== 'premium') {
            return null;
        }

        $provider = $sourceConfig['provider'] ?? null;

        if (! is_string($provider) || trim($provider) === '') {
            throw new RuntimeException('Premium dependencies must define source_config.provider.');
        }

        $provider = trim($provider);

        if (! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $provider)) {
            throw new RuntimeException(
                'Premium provider keys must use lowercase letters, numbers, and single hyphen separators.'
            );
        }

        return $provider;
    }

    /**
     * @param array<string, mixed> $sourceConfig
     * @return array<string, mixed>
     */
    public static function normalizeSourceConfig(string $source, array $sourceConfig): array
    {
        if (! self::isPremiumSource($source)) {
            unset($sourceConfig['provider']);
            return $sourceConfig;
        }

        $provider = self::providerFor($source, $sourceConfig);

        if ($provider !== null) {
            $sourceConfig['provider'] = $provider;
        }

        return $sourceConfig;
    }
}
