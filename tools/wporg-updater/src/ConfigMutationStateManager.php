<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class ConfigMutationStateManager
{
    public function __construct(
        private readonly ConfigWriter $manifestWriter,
        private readonly RuntimeInspector $runtimeInspector,
        private readonly ?AdminGovernanceExporter $adminGovernanceExporter = null,
    ) {
    }

    /**
     * @return array<string, array{exists:bool, contents:?string}>
     */
    public function snapshot(Config $currentConfig, Config $nextConfig): array
    {
        return $this->captureFileStates($this->trackedConfigPaths($currentConfig, $nextConfig));
    }

    public function persist(Config $config, ?Config $previousConfig = null): void
    {
        $this->manifestWriter->write($config);

        if ($this->adminGovernanceExporter !== null) {
            $this->adminGovernanceExporter->refresh($config);

            if ($previousConfig !== null) {
                $previousGovernancePath = $previousConfig->repoRoot . '/' . FrameworkRuntimeFiles::governanceDataPath($previousConfig);
                $nextGovernancePath = $config->repoRoot . '/' . FrameworkRuntimeFiles::governanceDataPath($config);

                if ($previousGovernancePath !== $nextGovernancePath && (is_file($previousGovernancePath) || is_link($previousGovernancePath))) {
                    $this->runtimeInspector->clearPath($previousGovernancePath);
                }
            }
        }
    }

    /**
     * @param array<string, array{exists:bool, contents:?string}> $states
     */
    public function restore(array $states): void
    {
        $writer = new AtomicFileWriter();

        foreach ($states as $path => $state) {
            if ($state['exists']) {
                $writer->write($path, (string) $state['contents']);
                continue;
            }

            if (is_file($path) || is_link($path)) {
                $this->runtimeInspector->clearPath($path);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function trackedConfigPaths(Config $currentConfig, Config $nextConfig): array
    {
        $paths = [$currentConfig->manifestPath];

        if ($this->adminGovernanceExporter !== null) {
            $paths[] = $currentConfig->repoRoot . '/' . FrameworkRuntimeFiles::governanceDataPath($currentConfig);
            $paths[] = $nextConfig->repoRoot . '/' . FrameworkRuntimeFiles::governanceDataPath($nextConfig);
        }

        return array_values(array_unique($paths));
    }

    /**
     * @param list<string> $paths
     * @return array<string, array{exists:bool, contents:?string}>
     */
    private function captureFileStates(array $paths): array
    {
        $states = [];

        foreach ($paths as $path) {
            $exists = is_file($path);
            $contents = $exists ? file_get_contents($path) : null;

            if ($exists && $contents === false) {
                throw new RuntimeException(sprintf('Unable to capture file state for %s.', $path));
            }

            $states[$path] = [
                'exists' => $exists,
                'contents' => $contents === false ? null : $contents,
            ];
        }

        return $states;
    }
}
