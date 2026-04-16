<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater\Cli\Handlers;

use Closure;
use WpOrgPluginUpdater\AdminGovernanceExporter;
use WpOrgPluginUpdater\Cli\CliModeHandler;
use WpOrgPluginUpdater\Config;
use WpOrgPluginUpdater\ManifestSuggester;
use WpOrgPluginUpdater\ManifestWriter;
use WpOrgPluginUpdater\MutationLock;
use WpOrgPluginUpdater\RuntimeInspector;
use WpOrgPluginUpdater\RuntimeStager;

final class RuntimeMaintenanceModeHandler implements CliModeHandler
{
    public function __construct(
        private readonly Config $config,
        private readonly MutationLock $mutationLock,
        private readonly string $repoRoot,
        private readonly string $commandPrefix,
        private readonly bool $jsonOutput,
        private readonly Closure $emitJson,
    ) {
    }

    public function supports(string $mode): bool
    {
        return in_array($mode, ['stage-runtime', 'refresh-admin-governance', 'suggest-manifest', 'format-manifest'], true);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function handle(string $mode, array $options): int
    {
        $adminGovernanceExporter = new AdminGovernanceExporter(new RuntimeInspector($this->config->runtime));

        if ($mode === 'stage-runtime') {
            $runtimeInspector = new RuntimeInspector($this->config->runtime);
            $stager = new RuntimeStager($this->config, $runtimeInspector, $adminGovernanceExporter);
            $stagedPaths = $stager->stage((string) ($options['output'] ?? ''));

            if ($this->jsonOutput) {
                ($this->emitJson)([
                    'status' => 'success',
                    'staged_path_count' => count($stagedPaths),
                    'staged_paths' => $stagedPaths,
                ]);
            }

            fwrite(STDOUT, "Staged runtime paths:\n");

            foreach ($stagedPaths as $path) {
                fwrite(STDOUT, sprintf("- %s\n", $path));
            }

            return 0;
        }

        if ($mode === 'refresh-admin-governance') {
            $this->mutationLock->synchronized(
                $this->repoRoot,
                function () use ($adminGovernanceExporter): void {
                    $adminGovernanceExporter->refresh($this->config);
                },
                'refresh-admin-governance'
            );
            fwrite(STDOUT, "Refreshed admin governance runtime data\n");

            return 0;
        }

        if ($mode === 'suggest-manifest') {
            fwrite(STDOUT, (new ManifestSuggester($this->config, $this->commandPrefix))->render());

            return 0;
        }

        $this->mutationLock->synchronized(
            $this->repoRoot,
            function (): void {
                (new ManifestWriter())->write($this->config);
            },
            'format-manifest'
        );
        fwrite(STDOUT, sprintf("Formatted manifest: %s\n", $this->config->manifestPath));

        return 0;
    }
}
