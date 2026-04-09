<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater\Cli\Handlers;

use RuntimeException;
use WpOrgPluginUpdater\Cli\CliModeHandler;
use WpOrgPluginUpdater\Config;
use WpOrgPluginUpdater\FrameworkInstaller;
use WpOrgPluginUpdater\DownstreamScaffolder;
use WpOrgPluginUpdater\MutationLock;
use WpOrgPluginUpdater\PremiumProviderScaffolder;
use WpOrgPluginUpdater\RuntimeInspector;

final class ScaffoldModeHandler implements CliModeHandler
{
    public function __construct(
        private readonly string $frameworkRoot,
        private readonly string $repoRoot,
        private readonly string $toolPath,
        private readonly bool $force,
        private readonly MutationLock $mutationLock,
    ) {
    }

    public function supports(string $mode): bool
    {
        return in_array($mode, ['scaffold-downstream', 'scaffold-premium-provider', 'framework-apply'], true);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function handle(string $mode, array $options): int
    {
        if ($mode === 'scaffold-downstream') {
            $profile = $options['profile'] ?? 'content-only-default';
            $contentRoot = $options['content-root'] ?? null;
            $scaffolder = new DownstreamScaffolder($this->frameworkRoot, $this->repoRoot);
            $adoptExistingManagedFiles = isset($options['adopt-existing-managed-files']);

            return $this->mutationLock->synchronized(
                $this->repoRoot,
                fn (): int => $scaffolder->scaffold(
                    (string) $this->toolPath,
                    (string) $profile,
                    is_string($contentRoot) ? $contentRoot : null,
                    $this->force,
                    $adoptExistingManagedFiles
                ),
                'scaffold-downstream'
            );
        }

        if ($mode === 'scaffold-premium-provider') {
            $provider = isset($options['provider']) && is_string($options['provider']) ? $options['provider'] : null;

            if ($provider === null || trim($provider) === '') {
                throw new RuntimeException('scaffold-premium-provider requires --provider=your-provider.');
            }

            $class = isset($options['class']) && is_string($options['class']) ? $options['class'] : null;
            $path = isset($options['path']) && is_string($options['path']) ? $options['path'] : null;
            $result = (new PremiumProviderScaffolder($this->frameworkRoot, $this->repoRoot))->scaffold($provider, $class, $path, $this->force);
            fwrite(STDOUT, sprintf("Scaffolded premium provider %s\n", $result['provider']));
            fwrite(STDOUT, sprintf("Registry: %s\n", $result['registry_path']));
            fwrite(STDOUT, sprintf("Class: %s\n", $result['class']));
            fwrite(STDOUT, sprintf("Path: %s\n", $result['path']));

            return 0;
        }

        $payloadRoot = $options['payload-root'] ?? null;
        $distributionPath = $options['distribution-path'] ?? null;
        $resultPath = $options['result-path'] ?? null;

        if (! is_string($payloadRoot) || $payloadRoot === '') {
            throw new RuntimeException('framework-apply requires --payload-root.');
        }

        if (! is_string($resultPath) || $resultPath === '') {
            throw new RuntimeException('framework-apply requires --result-path.');
        }

        $config = Config::load($this->repoRoot);
        $runtimeInspector = new RuntimeInspector($config->runtime);
        $result = $this->mutationLock->synchronized(
            $this->repoRoot,
            fn (): array => (new FrameworkInstaller($this->repoRoot, $runtimeInspector))->apply(
                $payloadRoot,
                is_string($distributionPath) ? $distributionPath : 'vendor/wp-core-base'
            ),
            'framework-apply'
        );

        if (file_put_contents($resultPath, json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)) === false) {
            throw new RuntimeException(sprintf('Unable to write framework apply result file: %s', $resultPath));
        }

        return 0;
    }
}
