<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class AdminGovernanceExporter
{
    public function __construct(
        private readonly RuntimeInspector $runtimeInspector,
    ) {
    }

    public function refresh(Config $config): void
    {
        $path = $config->repoRoot . '/' . FrameworkRuntimeFiles::governanceDataPath($config);
        $this->writeDataFile($config, $path);
    }

    public function exportToRuntime(Config $config, string $runtimeRoot): void
    {
        $path = rtrim($runtimeRoot, '/') . '/' . FrameworkRuntimeFiles::governanceDataPath($config);
        $this->writeDataFile($config, $path);
    }

    public function isStaleOrMissing(Config $config): bool
    {
        $path = $config->repoRoot . '/' . FrameworkRuntimeFiles::governanceDataPath($config);

        if (! is_file($path)) {
            return true;
        }

        $data = require $path;

        if (! is_array($data)) {
            return true;
        }

        return (string) ($data['manifest_checksum'] ?? '') !== $this->manifestChecksum($config);
    }

    private function writeDataFile(Config $config, string $path): void
    {
        (new PhpArrayFileWriter())->write($path, $this->payload($config));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Config $config): array
    {
        $plugins = [];
        $muPlugins = [];

        foreach ($config->dependencies() as $dependency) {
            $basename = $this->pluginBasename($config, $dependency);

            if ($basename === null) {
                continue;
            }

            $entry = [
                'component_key' => $dependency['component_key'],
                'management' => $dependency['management'],
                'source' => $dependency['source'],
                'workflow_managed' => $dependency['management'] === 'managed',
                'label' => $dependency['management'] === 'managed'
                    ? 'Managed by wp-core-base workflows'
                    : ($dependency['management'] === 'ignored' ? 'Ignored by wp-core-base' : 'Local code managed in-repo'),
            ];

            if ($this->isMuPlugin($config, $dependency)) {
                $muPlugins[$basename] = $entry;
            } else {
                $plugins[$basename] = $entry;
            }
        }

        ksort($plugins);
        ksort($muPlugins);

        return [
            'manifest_checksum' => $this->manifestChecksum($config),
            'plugins' => $plugins,
            'mu_plugins' => $muPlugins,
        ];
    }

    private function manifestChecksum(Config $config): string
    {
        $contents = file_get_contents($config->manifestPath);

        if ($contents === false) {
            throw new RuntimeException(sprintf('Unable to read manifest for governance export: %s', $config->manifestPath));
        }

        return 'sha256:' . hash('sha256', $contents);
    }

    /**
     * @param array<string, mixed> $dependency
     */
    private function pluginBasename(Config $config, array $dependency): ?string
    {
        if (! in_array((string) $dependency['kind'], ['plugin', 'mu-plugin-package', 'mu-plugin-file'], true)) {
            return null;
        }

        if ((string) $dependency['kind'] === 'mu-plugin-file') {
            return basename((string) $dependency['path']);
        }

        $root = $this->isMuPlugin($config, $dependency)
            ? $config->paths['mu_plugins_root']
            : $config->paths['plugins_root'];

        $relativeMainFile = trim((string) $dependency['main_file'], '/');
        $subPath = substr((string) $dependency['path'], strlen($root));
        $subPath = trim((string) $subPath, '/');

        if ($subPath === '') {
            return $relativeMainFile;
        }

        return trim($subPath . '/' . $relativeMainFile, '/');
    }

    /**
     * @param array<string, mixed> $dependency
     */
    private function isMuPlugin(Config $config, array $dependency): bool
    {
        return str_starts_with((string) $dependency['path'], $config->paths['mu_plugins_root'] . '/')
            || (string) $dependency['kind'] === 'mu-plugin-file';
    }
}
