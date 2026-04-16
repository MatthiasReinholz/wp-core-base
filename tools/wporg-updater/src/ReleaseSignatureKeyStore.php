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

        $defaultPath = self::defaultPublicKeyPath($framework);
        $keyDirectory = dirname($defaultPath);
        $rotatedKeys = glob($keyDirectory . '/' . self::PUBLIC_KEY_ROTATED_GLOB) ?: [];
        $keyringPaths = [$defaultPath];

        foreach ($rotatedKeys as $rotatedKeyPath) {
            if (is_string($rotatedKeyPath) && trim($rotatedKeyPath) !== '') {
                $keyringPaths[] = $rotatedKeyPath;
            }
        }

        if (is_string($preferredPublicKeyPath) && trim($preferredPublicKeyPath) !== '') {
            $resolvedPreferredPath = trim($preferredPublicKeyPath);

            if (! in_array($resolvedPreferredPath, $keyringPaths, true)) {
                self::assertSafeExternalPublicKeyPath($resolvedPreferredPath);
            }

            $paths[] = $resolvedPreferredPath;
        }

        $paths[] = $defaultPath;

        foreach ($rotatedKeys as $rotatedKeyPath) {
            if (is_string($rotatedKeyPath) && trim($rotatedKeyPath) !== '') {
                $paths[] = $rotatedKeyPath;
            }
        }

        $envPaths = getenv(self::PUBLIC_KEY_PATHS_ENV);

        if (is_string($envPaths) && trim($envPaths) !== '') {
            foreach (preg_split('/\s*,\s*/', trim($envPaths)) ?: [] as $envPath) {
                if ($envPath !== '') {
                    if (! self::isAbsolutePath($envPath)) {
                        throw new RuntimeException(sprintf(
                            '%s entries must be absolute paths: %s',
                            self::PUBLIC_KEY_PATHS_ENV,
                            $envPath
                        ));
                    }

                    self::assertSafeExternalPublicKeyPath($envPath);
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

    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\\\')
            || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1;
    }

    private static function assertSafeExternalPublicKeyPath(string $path): void
    {
        if (! self::isAbsolutePath($path)) {
            throw new RuntimeException(sprintf(
                'Release public key override path must be absolute: %s',
                $path
            ));
        }

        if (is_link($path)) {
            throw new RuntimeException(sprintf(
                'Release public key override path is a symlink and is not trusted: %s',
                $path
            ));
        }

        if (! is_file($path)) {
            throw new RuntimeException(sprintf(
                'Release public key override path must be a regular file: %s',
                $path
            ));
        }

        self::assertNotWorldWritable($path, 'Release public key override file is world-writable');
        self::assertParentsNotGroupOrWorldWritable($path);
    }

    private static function assertNotWorldWritable(string $path, string $message): void
    {
        $permissions = @fileperms($path);

        if (! is_int($permissions)) {
            throw new RuntimeException(sprintf('Unable to inspect permissions for release public key path: %s', $path));
        }

        if (($permissions & 0x0002) !== 0) {
            throw new RuntimeException(sprintf('%s: %s', $message, $path));
        }
    }

    private static function assertParentsNotGroupOrWorldWritable(string $path): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            return;
        }

        $current = dirname($path);

        while ($current !== '' && $current !== '.' && $current !== DIRECTORY_SEPARATOR) {
            if (! is_dir($current)) {
                throw new RuntimeException(sprintf(
                    'Release public key override parent directory does not exist: %s',
                    $current
                ));
            }

            if (is_link($current)) {
                throw new RuntimeException(sprintf(
                    'Release public key override parent directory is a symlink and is not trusted: %s',
                    $current
                ));
            }

            $permissions = @fileperms($current);

            if (! is_int($permissions)) {
                throw new RuntimeException(sprintf(
                    'Unable to inspect permissions for release public key parent directory: %s',
                    $current
                ));
            }

            if (($permissions & 0x0012) !== 0) {
                throw new RuntimeException(sprintf(
                    'Release public key override parent directory is group/world-writable: %s',
                    $current
                ));
            }

            $parent = dirname($current);

            if ($parent === $current) {
                break;
            }

            $current = $parent;
        }
    }
}
