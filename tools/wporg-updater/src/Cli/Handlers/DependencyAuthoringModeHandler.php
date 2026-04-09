<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater\Cli\Handlers;

use Closure;
use RuntimeException;
use WpOrgPluginUpdater\AdminGovernanceExporter;
use WpOrgPluginUpdater\Cli\CliModeHandler;
use WpOrgPluginUpdater\CommandHelp;
use WpOrgPluginUpdater\Config;
use WpOrgPluginUpdater\DependencyAuthoringService;
use WpOrgPluginUpdater\DependencyMetadataResolver;
use WpOrgPluginUpdater\InteractivePrompter;
use WpOrgPluginUpdater\ManagedSourceRegistry;
use WpOrgPluginUpdater\ManifestWriter;
use WpOrgPluginUpdater\MutationLock;
use WpOrgPluginUpdater\PremiumSourceResolver;
use WpOrgPluginUpdater\RuntimeInspector;
use WpOrgPluginUpdater\TempDirectoryJanitor;

final class DependencyAuthoringModeHandler implements CliModeHandler
{
    /**
     * @param list<string> $premiumProviders
     */
    public function __construct(
        private readonly Config $config,
        private readonly ManagedSourceRegistry $managedSourceRegistry,
        private readonly AdminGovernanceExporter $adminGovernanceExporter,
        private readonly MutationLock $mutationLock,
        private readonly string $repoRoot,
        private readonly string $commandPrefix,
        private readonly string $phpCommandPrefix,
        private readonly bool $jsonOutput,
        private readonly Closure $emitJson,
        private readonly array $premiumProviders,
    ) {
    }

    public function supports(string $mode): bool
    {
        return in_array($mode, ['add-dependency', 'adopt-dependency', 'remove-dependency', 'list-dependencies'], true);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function handle(string $mode, array $options): int
    {
        if ($mode !== 'list-dependencies') {
            $this->cleanupStaleTemporaryDirectories();
        }

        if (isset($options['help'])) {
            fwrite(STDOUT, CommandHelp::render($mode, $this->commandPrefix, $this->phpCommandPrefix));
            return 0;
        }

        $prompter = new InteractivePrompter();

        if (
            $mode === 'add-dependency'
            && (
                isset($options['interactive'])
                || (
                    InteractivePrompter::canPrompt()
                    && (
                        ! isset($options['source'])
                        || ! isset($options['kind'])
                        || (! isset($options['slug']) && ! isset($options['path']))
                    )
                )
            )
        ) {
            $this->maybePromptForMissing($options, $prompter);
        }

        $authoringService = new DependencyAuthoringService(
            config: $this->config,
            metadataResolver: new DependencyMetadataResolver(),
            runtimeInspector: new RuntimeInspector($this->config->runtime),
            manifestWriter: new ManifestWriter(),
            managedSourceRegistry: $this->managedSourceRegistry,
            adminGovernanceExporter: $this->adminGovernanceExporter,
        );

        if ($mode === 'add-dependency') {
            return $this->handleAddDependency($authoringService, $options);
        }

        if ($mode === 'adopt-dependency') {
            return $this->handleAdoptDependency($authoringService, $options);
        }

        if ($mode === 'remove-dependency') {
            $result = $this->mutationLock->synchronized(
                $this->repoRoot,
                static fn (): array => $authoringService->removeDependency($options),
                'remove-dependency'
            );
            fwrite(STDOUT, sprintf(
                "Removed dependency %s%s\n",
                $result['removed']['component_key'],
                $result['deleted_path'] ? ' and deleted its path' : ''
            ));

            return 0;
        }

        fwrite(STDOUT, $authoringService->renderDependencyList());

        return 0;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function handleAddDependency(DependencyAuthoringService $authoringService, array $options): int
    {
        if (isset($options['plan']) || isset($options['dry-run']) || isset($options['preview'])) {
            $result = $authoringService->planAddDependency($options);

            if ($this->jsonOutput) {
                ($this->emitJson)([
                    'status' => 'success',
                    'operation' => 'add-dependency',
                    ...$result,
                ]);
            }

            fwrite(STDOUT, "Planned dependency addition\n");
            fwrite(STDOUT, sprintf("Component: %s\n", $result['component_key']));
            fwrite(STDOUT, sprintf("Source: %s\n", $result['source']));
            fwrite(STDOUT, sprintf("Kind: %s\n", $result['kind']));
            fwrite(STDOUT, sprintf("Target path: %s\n", $result['target_path']));

            if (($result['selected_version'] ?? null) !== null) {
                fwrite(STDOUT, sprintf("Selected version: %s\n", $result['selected_version']));
            }

            if (($result['main_file'] ?? null) !== null) {
                fwrite(STDOUT, sprintf("Main file: %s\n", $result['main_file']));
            }

            if (($result['archive_subdir'] ?? '') !== '') {
                fwrite(STDOUT, sprintf("Archive subdir: %s\n", $result['archive_subdir']));
            }

            fwrite(STDOUT, sprintf("Would replace existing path: %s\n", ($result['would_replace'] ?? false) ? 'yes' : 'no'));

            if (($result['source_reference'] ?? null) !== null) {
                fwrite(STDOUT, sprintf("Resolved source: %s\n", $result['source_reference']));
            }

            fwrite(STDOUT, sprintf("Sanitize paths: %s\n", implode(', ', (array) ($result['sanitize_paths'] ?? [])) ?: '(none)'));
            fwrite(STDOUT, sprintf("Sanitize files: %s\n", implode(', ', (array) ($result['sanitize_files'] ?? [])) ?: '(none)'));

            return 0;
        }

        $result = $this->mutationLock->synchronized(
            $this->repoRoot,
            static fn (): array => $authoringService->addDependency($options),
            'add-dependency'
        );
        fwrite(STDOUT, sprintf("Added dependency %s (%s)\n", $result['component_key'], $result['path']));

        if (($result['version'] ?? null) !== null) {
            fwrite(STDOUT, sprintf("Version: %s\n", $result['version']));
        }

        if (($result['checksum'] ?? null) !== null) {
            fwrite(STDOUT, sprintf("Checksum: %s\n", $result['checksum']));
        }

        foreach ((array) ($result['next_steps'] ?? []) as $step) {
            fwrite(STDOUT, sprintf("Next: %s\n", $step));
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function handleAdoptDependency(DependencyAuthoringService $authoringService, array $options): int
    {
        if (isset($options['plan']) || isset($options['dry-run']) || isset($options['preview'])) {
            $result = $authoringService->planAdoptDependency($options);

            if ($this->jsonOutput) {
                ($this->emitJson)([
                    'status' => 'success',
                    'operation' => 'adopt-dependency',
                    ...$result,
                ]);
            }

            fwrite(STDOUT, "Planned dependency adoption\n");
            fwrite(STDOUT, sprintf("Adopt from: %s\n", $result['adopted_from']));
            fwrite(STDOUT, sprintf("To component: %s\n", $result['component_key']));
            fwrite(STDOUT, sprintf("Target path: %s\n", $result['target_path']));

            if (($result['selected_version'] ?? null) !== null) {
                fwrite(STDOUT, sprintf("Selected version: %s\n", $result['selected_version']));
            }

            fwrite(STDOUT, sprintf("Preserve current version: %s\n", ($result['preserve_version'] ?? false) ? 'yes' : 'no'));
            fwrite(STDOUT, sprintf("Would replace existing path: %s\n", ($result['would_replace'] ?? false) ? 'yes' : 'no'));

            if (($result['source_reference'] ?? null) !== null) {
                fwrite(STDOUT, sprintf("Resolved source: %s\n", $result['source_reference']));
            }

            fwrite(STDOUT, sprintf("Sanitize paths: %s\n", implode(', ', (array) ($result['sanitize_paths'] ?? [])) ?: '(none)'));
            fwrite(STDOUT, sprintf("Sanitize files: %s\n", implode(', ', (array) ($result['sanitize_files'] ?? [])) ?: '(none)'));

            return 0;
        }

        $result = $this->mutationLock->synchronized(
            $this->repoRoot,
            static fn (): array => $authoringService->adoptDependency($options),
            'adopt-dependency'
        );
        fwrite(STDOUT, sprintf(
            "Adopted dependency %s from %s\n",
            $result['component_key'],
            $result['adopted_from']
        ));

        if (($result['version'] ?? null) !== null) {
            fwrite(STDOUT, sprintf("Version: %s\n", $result['version']));
        }

        if (($result['checksum'] ?? null) !== null) {
            fwrite(STDOUT, sprintf("Checksum: %s\n", $result['checksum']));
        }

        foreach ((array) ($result['next_steps'] ?? []) as $step) {
            fwrite(STDOUT, sprintf("Next: %s\n", $step));
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function maybePromptForMissing(array &$options, InteractivePrompter $prompter): void
    {
        $source = isset($options['source']) && is_string($options['source']) ? $options['source'] : null;
        $kind = isset($options['kind']) && is_string($options['kind']) ? $options['kind'] : null;

        if ($source === null || $source === '') {
            $options['source'] = $prompter->choose(
                'Select dependency source',
                ['wordpress.org', 'github-release', 'premium', 'local'],
                'local'
            );
            $source = $options['source'];
        }

        if ($kind === null || $kind === '') {
            $options['kind'] = $prompter->choose(
                'Select dependency kind',
                ['plugin', 'theme', 'mu-plugin-package', 'mu-plugin-file', 'runtime-file', 'runtime-directory'],
                'plugin'
            );
            $kind = $options['kind'];
        }

        if ((! isset($options['slug']) || ! is_string($options['slug']) || trim($options['slug']) === '') && ! isset($options['path'])) {
            if ($source === 'local') {
                $options['path'] = $prompter->ask('Runtime path');
            } else {
                $options['slug'] = $prompter->ask('Slug');
            }
        }

        if ($source === 'github-release') {
            if (! isset($options['github-repository']) || ! is_string($options['github-repository']) || trim($options['github-repository']) === '') {
                $options['github-repository'] = $prompter->ask('GitHub repository (owner/repo)');
            }

            if (! isset($options['private']) && $prompter->confirm('Is this GitHub repository private?', false)) {
                $options['private'] = true;
            }

            if (($options['private'] ?? false) === true && (! isset($options['github-token-env']) || ! is_string($options['github-token-env']) || trim($options['github-token-env']) === '')) {
                $tokenEnv = $prompter->ask('GitHub token env var name (leave blank for default)', '');

                if ($tokenEnv !== '') {
                    $options['github-token-env'] = $tokenEnv;
                }
            }
        }

        if (PremiumSourceResolver::isPremiumSource($source)) {
            if ($source === 'premium' && (! isset($options['provider']) || ! is_string($options['provider']) || trim($options['provider']) === '')) {
                if ($this->premiumProviders === []) {
                    throw new RuntimeException('No premium providers are registered. Scaffold one with scaffold-premium-provider or add it to .wp-core-base/premium-providers.php.');
                }

                $options['provider'] = $prompter->choose(
                    'Select premium provider',
                    $this->premiumProviders,
                    $this->premiumProviders[0]
                );
            }

            if (! isset($options['credential-key']) || ! is_string($options['credential-key']) || trim($options['credential-key']) === '') {
                $credentialKey = $prompter->ask('Premium credential lookup key (leave blank for component key)', '');

                if ($credentialKey !== '') {
                    $options['credential-key'] = $credentialKey;
                }
            }
        }

        if ($source === 'local' && (! isset($options['path']) || ! is_string($options['path']) || trim($options['path']) === '')) {
            $options['path'] = $prompter->ask('Runtime path');
        }
    }

    private function cleanupStaleTemporaryDirectories(): void
    {
        $result = (new TempDirectoryJanitor(
            TempDirectoryJanitor::defaultPrefixes(),
            TempDirectoryJanitor::defaultMaxAgeSeconds()
        ))->cleanup();

        foreach ($result['failed'] as $warning) {
            fwrite(STDERR, sprintf("[warn] %s\n", $warning));
        }
    }
}
