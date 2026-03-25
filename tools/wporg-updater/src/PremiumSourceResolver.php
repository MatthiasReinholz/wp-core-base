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
        return ['wordpress.org', 'github-release', 'premium', 'local'];
    }

    public static function isPremiumSource(string $source): bool
    {
        return $source === 'premium';
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
