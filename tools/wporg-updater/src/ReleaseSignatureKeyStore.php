<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class ReleaseSignatureKeyStore
{
    public const PUBLIC_KEY_RELATIVE_PATH = 'tools/wporg-updater/keys/framework-release-public.pem';
    public const PUBLIC_KEY_ROTATED_GLOB = 'framework-release-public-*.pem';
    public const PUBLIC_KEY_PATHS_ENV = 'WP_CORE_BASE_RELEASE_PUBLIC_KEY_PATHS';

    public static function defaultPublicKeyPath(FrameworkConfig $framework): string
    {
        $distributionPath = trim($framework->distributionPath(), '/');
        $frameworkRoot = $distributionPath === '' || $distributionPath === '.'
            ? $framework->repoRoot
            : $framework->repoRoot . '/' . $distributionPath;

        return $frameworkRoot . '/' . self::PUBLIC_KEY_RELATIVE_PATH;
    }

    /**
     * @return list<string>
     */
    public static function publicKeyPaths(FrameworkConfig $framework, ?string $preferredPublicKeyPath = null): array
    {
        $paths = [];

        if (is_string($preferredPublicKeyPath) && trim($preferredPublicKeyPath) !== '') {
            $paths[] = trim($preferredPublicKeyPath);
        }

        $defaultPath = self::defaultPublicKeyPath($framework);
        $paths[] = $defaultPath;

        $keyDirectory = dirname($defaultPath);
        $rotatedKeys = glob($keyDirectory . '/' . self::PUBLIC_KEY_ROTATED_GLOB) ?: [];

        foreach ($rotatedKeys as $rotatedKeyPath) {
            if (is_string($rotatedKeyPath) && trim($rotatedKeyPath) !== '') {
                $paths[] = $rotatedKeyPath;
            }
        }

        $envPaths = getenv(self::PUBLIC_KEY_PATHS_ENV);

        if (is_string($envPaths) && trim($envPaths) !== '') {
            foreach (preg_split('/\s*,\s*/', trim($envPaths)) ?: [] as $envPath) {
                if ($envPath !== '') {
                    $paths[] = $envPath;
                }
            }
        }

        return array_values(array_unique($paths));
    }

    public static function privateKeyFromEnvironment(string $environmentVariable): string
    {
        $value = getenv($environmentVariable);

        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException(sprintf(
                'Release signing private key environment variable is not set: %s',
                $environmentVariable
            ));
        }

        return $value;
    }

    public static function optionalEnvironmentValue(?string $environmentVariable): ?string
    {
        if ($environmentVariable === null || trim($environmentVariable) === '') {
            return null;
        }

        $value = getenv($environmentVariable);

        if ($value === false) {
            return null;
        }

        return (string) $value;
    }
}
