<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class DependencyAuthoringService
{
    public function __construct(
        private Config $config,
        private readonly DependencyMetadataResolver $metadataResolver,
        private readonly RuntimeInspector $runtimeInspector,
        private readonly ConfigWriter $manifestWriter,
        private readonly ManagedSourceRegistry $managedSourceRegistry,
        private readonly ?AdminGovernanceExporter $adminGovernanceExporter = null,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function addDependency(array $options): array
    {
        $prepared = $this->prepareAddOperation($options);

        return $this->applyPreparedOperation($prepared, fn (array $entry): Config => $this->writeValidatedConfigWithDependency($entry));
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function planAddDependency(array $options): array
    {
        $prepared = $this->prepareAddOperation($options);

        try {
            return $prepared['plan'];
        } finally {
            $this->cleanupPreparedOperation($prepared);
        }
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function adoptDependency(array $options): array
    {
        $existing = $this->findDependencyForAdoption($options);

        if ($existing['management'] !== 'local' || $existing['source'] !== 'local') {
            throw new RuntimeException(sprintf(
                'adopt-dependency currently supports only local-owned dependencies. Selected: %s',
                $existing['component_key']
            ));
        }

        $targetOptions = $options;
        $targetOptions['kind'] = $existing['kind'];
        $targetOptions['slug'] = $existing['slug'];
        $targetOptions['path'] = $existing['path'];
        $targetOptions['replace'] = true;

        $providedPath = $this->nullableString($options['path'] ?? null);

        if ($providedPath !== null && trim($providedPath, '/') !== trim((string) $existing['path'], '/')) {
            throw new RuntimeException('adopt-dependency currently preserves the existing runtime path. Omit --path or keep it identical.');
        }

        if (($options['preserve-version'] ?? false) === true && $this->nullableString($options['version'] ?? null) === null) {
            $targetOptions['version'] = $this->resolveCurrentInstalledVersion($existing);
        }

        $prepared = $this->prepareAddOperation($targetOptions);
        $prepared['plan']['operation'] = 'adopt-dependency';
        $prepared['plan']['adopted_from'] = $existing['component_key'];
        $prepared['plan']['preserve_version'] = ($options['preserve-version'] ?? false) === true;

        $result = $this->applyPreparedOperation(
            $prepared,
            fn (array $entry): Config => $this->writeConfigReplacingDependency($existing, $entry)
        );
        $result['adopted_from'] = $existing['component_key'];

        return $result;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function planAdoptDependency(array $options): array
    {
        $existing = $this->findDependencyForAdoption($options);

        if ($existing['management'] !== 'local' || $existing['source'] !== 'local') {
            throw new RuntimeException(sprintf(
                'adopt-dependency currently supports only local-owned dependencies. Selected: %s',
                $existing['component_key']
            ));
        }

        $targetOptions = $options;
        $targetOptions['kind'] = $existing['kind'];
        $targetOptions['slug'] = $existing['slug'];
        $targetOptions['path'] = $existing['path'];
        $targetOptions['replace'] = true;

        if (($options['preserve-version'] ?? false) === true && $this->nullableString($options['version'] ?? null) === null) {
            $targetOptions['version'] = $this->resolveCurrentInstalledVersion($existing);
        }

        $prepared = $this->prepareAddOperation($targetOptions);

        try {
            $prepared['plan']['operation'] = 'adopt-dependency';
            $prepared['plan']['adopted_from'] = $existing['component_key'];
            $prepared['plan']['preserve_version'] = ($options['preserve-version'] ?? false) === true;

            return $prepared['plan'];
        } finally {
            $this->cleanupPreparedOperation($prepared);
        }
    }

    /**
     * @param array<string, mixed> $options
     * @return array{removed:array<string,mixed>,deleted_path:bool}
     */
    public function removeDependency(array $options): array
    {
        $componentKey = $this->nullableString($options['component-key'] ?? null);
        $slug = $this->nullableString($options['slug'] ?? null);
        $kind = $this->nullableString($options['kind'] ?? null);
        $source = $this->nullableString($options['source'] ?? null);
        $deletePath = isset($options['delete-path']);

        $dependencies = $this->config->dependencies();
        $removed = null;
        $removedIndex = null;
        $matches = [];

        foreach ($dependencies as $index => $dependency) {
            if ($componentKey !== null && PremiumSourceResolver::matchesComponentKey($dependency, $componentKey)) {
                $removed = $dependency;
                $removedIndex = $index;
                break;
            }

            if (
                $componentKey === null
                && $slug !== null
                && $kind !== null
                && $dependency['slug'] === $slug
                && $dependency['kind'] === $kind
                && ($source === null || $dependency['source'] === $source)
            ) {
                $matches[] = [
                    'index' => $index,
                    'dependency' => $dependency,
                ];
            }
        }

        if ($removed === null && $matches !== []) {
            if (count($matches) > 1) {
                $keys = array_map(
                    static fn (array $match): string => (string) $match['dependency']['component_key'],
                    $matches
                );

                throw new RuntimeException(
                    'Multiple dependencies matched that slug/kind selector. Re-run with --source or --component-key. Matches: ' .
                    implode(', ', $keys)
                );
            }

            $removed = $matches[0]['dependency'];
            $removedIndex = $matches[0]['index'];
        }

        if ($removed === null) {
            throw new RuntimeException('No matching dependency entry was found to remove.');
        }

        unset($dependencies[$removedIndex]);
        $nextConfig = $this->config->withDependencies(array_values($dependencies));
        $stateManager = $this->configMutationStateManager();
        $trackedFileStates = $stateManager->snapshot($this->config, $nextConfig);
        $removedAbsolutePath = $this->config->repoRoot . '/' . $removed['path'];
        $backupRoot = null;
        $backupPath = null;

        if ($deletePath && (file_exists($removedAbsolutePath) || is_link($removedAbsolutePath))) {
            $backupRoot = sys_get_temp_dir() . '/wporg-remove-backup-' . bin2hex(random_bytes(6));
            mkdir($backupRoot, 0775, true);
            $backupPath = $backupRoot . '/' . basename($removedAbsolutePath);
            $this->runtimeInspector->copyPath($removedAbsolutePath, $backupPath);
            $this->runtimeInspector->clearPath($removedAbsolutePath);
        }

        try {
            $stateManager->persist($nextConfig, $this->config);
            $this->config = $nextConfig;
        } catch (\Throwable $exception) {
            if ($deletePath && $backupPath !== null && (file_exists($backupPath) || is_link($backupPath))) {
                $this->runtimeInspector->copyPath($backupPath, $removedAbsolutePath);
            }

            $stateManager->restore($trackedFileStates);
            $this->config = Config::load($this->config->repoRoot, $this->config->manifestPath);

            throw $exception;
        } finally {
            if ($backupRoot !== null) {
                $this->runtimeInspector->clearPath($backupRoot);
            }
        }

        return [
            'removed' => $removed,
            'deleted_path' => $deletePath,
        ];
    }

    public function renderDependencyList(): string
    {
        $lines = [];
        $lines[] = 'Configured dependencies:';
        $lines[] = '';

        foreach (['managed', 'local', 'ignored'] as $management) {
            $group = array_values(array_filter(
                $this->config->dependencies(),
                static fn (array $dependency): bool => $dependency['management'] === $management
            ));

            if ($group === []) {
                continue;
            }

            $lines[] = strtoupper($management);

            foreach ($group as $dependency) {
                $lines[] = sprintf(
                    '- %s | %s | %s | %s | %s',
                    $dependency['kind'],
                    $dependency['source'],
                    $dependency['slug'],
                    $dependency['path'],
                    $dependency['version'] ?? '-'
                );
            }

            $lines[] = '';
        }

        return rtrim(implode("\n", $lines)) . "\n";
    }

    public static function defaultGitHubTokenEnv(string $slug, ?string $repository = null): string
    {
        $basis = $slug !== '' ? $slug : (is_string($repository) ? basename($repository) : 'dependency');
        $normalized = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $basis) ?? $basis);
        $normalized = trim(preg_replace('/_+/', '_', $normalized) ?? $normalized, '_');

        if ($normalized === '') {
            $normalized = 'DEPENDENCY';
        }

        return 'WP_CORE_BASE_GITHUB_TOKEN_' . $normalized;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function prepareAddOperation(array $options): array
    {
        $kind = $this->requiredString($options, 'kind');
        $source = $this->requiredString($options, 'source');
        $management = $this->resolveManagement($options, $source);
        $slug = $this->resolveSlug($options);
        $provider = $this->resolvedPremiumProviderFromOptions($source, $options);
        $path = $this->resolvePath($kind, $slug, $options['path'] ?? null);
        $name = $this->nullableString($options['name'] ?? null);
        $version = $this->nullableString($options['version'] ?? null);
        $archiveSubdir = trim((string) ($options['archive-subdir'] ?? ''), '/');
        $mainFile = $this->nullableString($options['main-file'] ?? null);
        $privateGitHub = (bool) ($options['private'] ?? false);
        $replace = isset($options['replace']);
        $force = isset($options['force']);

        $this->assertAddAllowed($kind, $source, $management);
        $this->assertDoesNotAlreadyExist($kind, $source, $slug, $force, $provider);

        $rawEntry = [
            'name' => $name ?? $this->metadataResolver->displayNameFromPath($slug),
            'slug' => $slug,
            'kind' => $kind,
            'management' => $management,
            'source' => $source,
            'path' => $path,
            'main_file' => $mainFile,
            'version' => null,
            'checksum' => null,
            'archive_subdir' => $archiveSubdir,
            'extra_labels' => $this->defaultExtraLabels($kind, $slug),
            'source_config' => [
                'github_repository' => null,
                'github_release_asset_pattern' => null,
                'github_token_env' => null,
                'credential_key' => null,
                'provider' => null,
                'provider_product_id' => null,
            ],
            'policy' => $this->defaultPolicy($management, $source),
        ];

        $preparedSourcePath = null;
        $cleanupRoot = null;
        $sanitizePaths = [];
        $sanitizeFiles = [];
        $sourceReference = $source;
        $destinationAbsolutePath = $this->config->repoRoot . '/' . $rawEntry['path'];
        $wouldReplace = file_exists($destinationAbsolutePath) || is_link($destinationAbsolutePath);

        if ($source !== 'local') {
            $managedPreparation = $this->prepareManagedDependency(
                $rawEntry,
                $options,
                $version,
                $replace,
                $privateGitHub
            );

            $rawEntry = $managedPreparation['entry'];
            $preparedSourcePath = $managedPreparation['prepared_source_path'];
            $cleanupRoot = $managedPreparation['cleanup_root'];
            $sanitizePaths = $managedPreparation['sanitize_paths'];
            $sanitizeFiles = $managedPreparation['sanitize_files'];
            $sourceReference = $managedPreparation['source_reference'];
            $wouldReplace = $managedPreparation['would_replace'];
        } else {
            $rawEntry = $this->resolveLocalDependency($rawEntry, $name, $mainFile, $version);
        }

        return [
            'entry' => $rawEntry,
            'destination_absolute_path' => $destinationAbsolutePath,
            'replace' => $replace,
            'prepared_source_path' => $preparedSourcePath,
            'cleanup_root' => $cleanupRoot,
            'plan' => [
                'operation' => 'add-dependency',
                'component_key' => PremiumSourceResolver::componentKey($kind, $source, $slug, [
                    'provider' => $provider,
                ]),
                'source' => $source,
                'kind' => $kind,
                'slug' => $slug,
                'target_path' => $path,
                'selected_version' => $rawEntry['version'],
                'main_file' => $rawEntry['main_file'],
                'archive_subdir' => $archiveSubdir,
                'would_replace' => $wouldReplace,
                'sanitize_paths' => $sanitizePaths,
                'sanitize_files' => $sanitizeFiles,
                'source_reference' => $sourceReference,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $prepared
     * @param callable(array<string, mixed>): Config $manifestMutation
     * @return array<string, mixed>
     */
    private function applyPreparedOperation(array $prepared, callable $manifestMutation): array
    {
        $entry = $prepared['entry'];
        $destinationAbsolutePath = $prepared['destination_absolute_path'];
        $preparedSourcePath = $prepared['prepared_source_path'];
        $nextConfig = $manifestMutation($entry);
        $stateManager = $this->configMutationStateManager();
        $trackedFileStates = $stateManager->snapshot($this->config, $nextConfig);
        $backupRoot = null;
        $backupPath = null;
        $hadExistingDestination = file_exists($destinationAbsolutePath) || is_link($destinationAbsolutePath);

        try {
            if (is_string($preparedSourcePath) && $preparedSourcePath !== '') {
                if ($hadExistingDestination) {
                    $backupRoot = sys_get_temp_dir() . '/wporg-adopt-backup-' . bin2hex(random_bytes(6));
                    mkdir($backupRoot, 0775, true);
                    $backupPath = $backupRoot . '/' . basename($destinationAbsolutePath);
                    $this->runtimeInspector->copyPath($destinationAbsolutePath, $backupPath);
                }

                $this->runtimeInspector->clearPath($destinationAbsolutePath);
                $this->runtimeInspector->copyPath($preparedSourcePath, $destinationAbsolutePath);
            }

            $stateManager->persist($nextConfig, $this->config);
            $this->config = $nextConfig;

            $result = $nextConfig->dependencyByKey(PremiumSourceResolver::componentKey(
                (string) $entry['kind'],
                (string) $entry['source'],
                (string) $entry['slug'],
                is_array($entry['source_config'] ?? null) ? $entry['source_config'] : []
            ));
            $result['next_steps'] = $this->nextStepsForDependency($result);

            return $result;
        } catch (\Throwable $exception) {
            if (is_string($preparedSourcePath) && $preparedSourcePath !== '') {
                $this->runtimeInspector->clearPath($destinationAbsolutePath);

                if ($hadExistingDestination && $backupPath !== null && (file_exists($backupPath) || is_link($backupPath))) {
                    $this->runtimeInspector->copyPath($backupPath, $destinationAbsolutePath);
                }
            }

            $stateManager->restore($trackedFileStates);
            $this->config = Config::load($this->config->repoRoot, $this->config->manifestPath);

            throw $exception;
        } finally {
            if ($backupRoot !== null) {
                $this->runtimeInspector->clearPath($backupRoot);
            }

            $this->cleanupPreparedOperation($prepared);
        }
    }

    /**
     * @param array<string, mixed> $prepared
     */
    private function cleanupPreparedOperation(array $prepared): void
    {
        $cleanupRoot = $prepared['cleanup_root'] ?? null;

        if (is_string($cleanupRoot) && $cleanupRoot !== '') {
            $this->runtimeInspector->clearPath($cleanupRoot);
        }
    }

    /**
     * @param array<string, mixed> $rawEntry
     * @param array<string, mixed> $options
     * @return array{entry:array<string,mixed>,prepared_source_path:string,cleanup_root:string,sanitize_paths:list<string>,sanitize_files:list<string>,source_reference:string,would_replace:bool}
     */
    private function prepareManagedDependency(array $rawEntry, array $options, ?string $requestedVersion, bool $replace, bool $privateGitHub): array
    {
        return $this->preparationService()->prepareManagedDependency($rawEntry, $options, $requestedVersion, $replace, $privateGitHub);
    }

    /**
     * @param array<string, mixed> $rawEntry
     * @return array<string, mixed>
     */
    private function resolveLocalDependency(array $rawEntry, ?string $name, ?string $mainFile, ?string $version): array
    {
        return $this->preparationService()->resolveLocalDependency($rawEntry, $name, $mainFile, $version);
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function writeValidatedConfigWithDependency(array $entry): Config
    {
        return $this->manifestMutator()->writeValidatedConfigWithDependency($entry);
    }

    /**
     * @param array<string, mixed> $removedDependency
     * @param array<string, mixed> $entry
     */
    private function writeConfigReplacingDependency(array $removedDependency, array $entry): Config
    {
        return $this->manifestMutator()->writeConfigReplacingDependency($removedDependency, $entry);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function findDependencyForAdoption(array $options): array
    {
        return $this->support()->findDependencyForAdoption($options);
    }

    private function assertAddAllowed(string $kind, string $source, string $management): void
    {
        $this->support()->assertAddAllowed($kind, $source, $management);
    }

    private function assertDoesNotAlreadyExist(string $kind, string $source, string $slug, bool $force, ?string $provider): void
    {
        $this->support()->assertDoesNotAlreadyExist($kind, $source, $slug, $force, $provider);
    }

    /**
     * @param array<string, mixed> $dependency
     * @param array<string, mixed> $options
     */
    private function resolvedPremiumProviderFromOptions(string $source, array $options): ?string
    {
        return $this->support()->resolvedPremiumProviderFromOptions($source, $options);
    }

    /**
     * @param array<string, mixed> $dependency
     */
    private function dependencyMatchesIdentity(array $dependency, string $kind, string $source, string $slug, ?string $provider): bool
    {
        return $this->support()->dependencyMatchesIdentity($dependency, $kind, $source, $slug, $provider);
    }

    private function resolveManagement(array $options, string $source): string
    {
        return $this->support()->resolveManagement($options, $source);
    }

    private function resolveSlug(array $options): string
    {
        return $this->support()->resolveSlug($options);
    }

    private function resolvePath(string $kind, string $slug, mixed $path): string
    {
        return $this->support()->resolvePath($kind, $slug, $path);
    }

    /**
     * @return list<string>
     */
    private function defaultExtraLabels(string $kind, string $slug): array
    {
        return $this->support()->defaultExtraLabels($kind, $slug);
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultPolicy(string $management, string $source): array
    {
        return $this->support()->defaultPolicy($management, $source);
    }

    /**
     * @param array<string, mixed> $dependency
     * @return list<string>
     */
    private function nextStepsForDependency(array $dependency): array
    {
        return $this->support()->nextStepsForDependency($dependency);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function requiredString(array $options, string $key): string
    {
        return $this->support()->requiredString($options, $key);
    }

    private function nullableString(mixed $value): ?string
    {
        return $this->support()->nullableString($value);
    }

    /**
     * @param array<string, mixed> $dependency
     */
    private function resolveCurrentInstalledVersion(array $dependency): string
    {
        return $this->preparationService()->resolveCurrentInstalledVersion($dependency);
    }

    private function configMutationStateManager(): ConfigMutationStateManager
    {
        return new ConfigMutationStateManager(
            $this->manifestWriter,
            $this->runtimeInspector,
            $this->adminGovernanceExporter
        );
    }

    private function support(): DependencyAuthoringSupport
    {
        return new DependencyAuthoringSupport($this->config);
    }

    private function preparationService(): DependencyPreparationService
    {
        return new DependencyPreparationService(
            $this->config,
            $this->metadataResolver,
            $this->runtimeInspector,
            $this->managedSourceRegistry,
            $this->support(),
        );
    }

    private function manifestMutator(): DependencyManifestMutator
    {
        return new DependencyManifestMutator($this->config, $this->support());
    }
}
