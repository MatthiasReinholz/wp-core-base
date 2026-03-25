<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

final class FrameworkRuntimeFiles
{
    public const GOVERNANCE_LOADER_BASENAME = 'wp-core-base-admin-governance.php';
    public const GOVERNANCE_DATA_BASENAME = 'wp-core-base-admin-governance.data.php';

    public static function governanceLoaderPath(Config $config): string
    {
        return $config->paths['mu_plugins_root'] . '/' . self::GOVERNANCE_LOADER_BASENAME;
    }

    public static function governanceDataPath(Config $config): string
    {
        return $config->paths['mu_plugins_root'] . '/' . self::GOVERNANCE_DATA_BASENAME;
    }

    /**
     * @return list<string>
     */
    public static function staticManagedPaths(Config $config): array
    {
        return [self::governanceLoaderPath($config)];
    }

    /**
     * @return list<array{path:string,absolute_path:string,kind:string}>
     */
    public static function runtimeEntries(Config $config): array
    {
        $paths = [
            self::governanceLoaderPath($config),
            self::governanceDataPath($config),
        ];

        return array_map(
            static fn (string $path): array => [
                'path' => $path,
                'absolute_path' => $config->repoRoot . '/' . $path,
                'kind' => 'mu-plugin-file',
            ],
            $paths
        );
    }

    public static function contains(Config $config, string $path): bool
    {
        return in_array($path, array_map(static fn (array $entry): string => $entry['path'], self::runtimeEntries($config)), true);
    }
}
