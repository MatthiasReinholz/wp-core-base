<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;
use ZipArchive;

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

        $targetSource = $this->requiredString($options, 'source');
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
        $trackedFileStates = $this->captureFileStates($this->trackedConfigPaths($this->config, $nextConfig));
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
            $this->persistConfig($nextConfig);
            $this->config = $nextConfig;
        } catch (\Throwable $exception) {
            if ($deletePath && $backupPath !== null && (file_exists($backupPath) || is_link($backupPath))) {
                $this->runtimeInspector->copyPath($backupPath, $removedAbsolutePath);
            }

            $this->restoreFileStates($trackedFileStates);
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
        $trackedFileStates = $this->captureFileStates($this->trackedConfigPaths($this->config, $nextConfig));
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

            $this->persistConfig($nextConfig);
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

            $this->restoreFileStates($trackedFileStates);
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
        $destinationPath = $this->config->repoRoot . '/' . $rawEntry['path'];

        if ((file_exists($destinationPath) || is_link($destinationPath)) && ! $replace) {
            throw new RuntimeException(sprintf(
                'Target path already exists: %s. Re-run with --replace to overwrite it.',
                $rawEntry['path']
            ));
        }

        $tempDir = sys_get_temp_dir() . '/wporg-authoring-' . bin2hex(random_bytes(6));
        mkdir($tempDir, 0775, true);
        $archivePath = $tempDir . '/payload.zip';
        $extractPath = $tempDir . '/extract';
        mkdir($extractPath, 0775, true);

        if ($rawEntry['source'] === 'github-release') {
            $repository = $this->requiredString($options, 'github-repository');
            $tokenEnv = $this->nullableString($options['github-token-env'] ?? null);
            $defaultTokenEnv = self::defaultGitHubTokenEnv((string) $rawEntry['slug'], $repository);

            $rawEntry['source_config']['github_repository'] = $repository;
            $rawEntry['source_config']['github_release_asset_pattern'] = $this->nullableString($options['github-release-asset-pattern'] ?? null);
            $rawEntry['source_config']['github_token_env'] = $tokenEnv;

            if ($tokenEnv !== null && getenv($tokenEnv) === false) {
                throw new RuntimeException(sprintf(
                    'Environment variable %s is required to add private GitHub dependency %s. Export it locally, then rerun.',
                    $tokenEnv,
                    $rawEntry['slug']
                ));
            }

            try {
                $catalog = $this->managedSourceRegistry->for($rawEntry)->fetchCatalog($rawEntry);
            } catch (RuntimeException $exception) {
                if ($tokenEnv === null && ($privateGitHub || $this->looksLikeGitHubAuthFailure($exception))) {
                    $rawEntry['source_config']['github_token_env'] = $defaultTokenEnv;
                    $envValue = getenv($defaultTokenEnv);

                    if (! is_string($envValue) || $envValue === '') {
                        throw new RuntimeException(sprintf(
                            'GitHub release access for %s may require authentication. Export %s locally, or pass --github-token-env=YOUR_TOKEN_ENV. If the repository is public, verify that --github-repository is correct.',
                            $repository,
                            $defaultTokenEnv
                        ), previous: $exception);
                    }

                    $catalog = $this->managedSourceRegistry->for($rawEntry)->fetchCatalog($rawEntry);
                } else {
                    throw $exception;
                }
            }
        } else {
            $rawEntry['source_config']['credential_key'] = $this->nullableString($options['credential-key'] ?? null);
            $provider = $this->nullableString($options['provider'] ?? null);

            if ((string) $rawEntry['source'] === 'premium') {
                if ($provider === null) {
                    throw new RuntimeException('--provider is required when --source=premium.');
                }

                $rawEntry['source_config']['provider'] = $provider;
            }

            $providerProductId = $this->nullableString($options['provider-product-id'] ?? null);

            if ($providerProductId !== null) {
                $rawEntry['source_config']['provider_product_id'] = (int) $providerProductId;
            }

            $catalog = $this->managedSourceRegistry->for($rawEntry)->fetchCatalog($rawEntry);
        }

        $source = $this->managedSourceRegistry->for($rawEntry);
        $version = $requestedVersion ?? (string) ($catalog['latest_version'] ?? '');

        if ($version === '') {
            throw new RuntimeException(sprintf('Could not resolve a version for %s.', $rawEntry['slug']));
        }

        $releaseData = $source->releaseDataForVersion(
            $rawEntry,
            $catalog,
            $version,
            (string) ($catalog['latest_release_at'] ?? gmdate(DATE_ATOM))
        );
        $source->downloadReleaseToFile($rawEntry, $releaseData, $archivePath);
        $displayName = $rawEntry['name'];
        $sourceReference = (string) ($releaseData['source_reference'] ?? $rawEntry['source']);

        $zip = new ZipArchive();

        if ($zip->open($archivePath) !== true) {
            throw new RuntimeException(sprintf('Failed to open dependency archive: %s', $archivePath));
        }

        ZipExtractor::extractValidated($zip, $extractPath);
        $zip->close();

        $sourcePath = ExtractedPayloadLocator::locateForAuthoring(
            $extractPath,
            (string) $rawEntry['archive_subdir'],
            (string) $rawEntry['slug'],
            (string) $rawEntry['kind'],
            $this->metadataResolver,
            $this->nullableString($rawEntry['main_file'] ?? null)
        );

        $resolved = $this->metadataResolver->resolveFromAbsolutePath(
            $sourcePath,
            (string) $rawEntry['kind'],
            is_string($displayName) ? $displayName : (string) $rawEntry['name'],
            $this->nullableString($rawEntry['main_file'] ?? null),
            $version
        );

        $rawEntry['name'] = $resolved['name'];
        $rawEntry['main_file'] = $resolved['main_file'];
        $rawEntry['version'] = $resolved['version'] ?? $version;

        [$sanitizePaths, $sanitizeFiles] = $this->config->managedSanitizeRules($rawEntry);
        $this->runtimeInspector->stripPath($sourcePath, $sanitizePaths, $sanitizeFiles);
        $this->runtimeInspector->assertPathIsClean($sourcePath, (array) $rawEntry['policy']['allow_runtime_paths'], [], $sanitizePaths, $sanitizeFiles);
        $rawEntry['checksum'] = $this->runtimeInspector->computeChecksum($sourcePath, [], $sanitizePaths, $sanitizeFiles);

        return [
            'entry' => $rawEntry,
            'prepared_source_path' => $sourcePath,
            'cleanup_root' => $tempDir,
            'sanitize_paths' => $sanitizePaths,
            'sanitize_files' => $sanitizeFiles,
            'source_reference' => $sourceReference,
            'would_replace' => file_exists($destinationPath) || is_link($destinationPath),
        ];
    }

    /**
     * @param array<string, mixed> $rawEntry
     * @return array<string, mixed>
     */
    private function resolveLocalDependency(array $rawEntry, ?string $name, ?string $mainFile, ?string $version): array
    {
        $resolved = $this->metadataResolver->resolveFromExistingPath(
            $this->config,
            (string) $rawEntry['path'],
            (string) $rawEntry['kind'],
            $name,
            $mainFile,
            $version
        );

        $rawEntry['name'] = $resolved['name'];
        $rawEntry['main_file'] = $resolved['main_file'];
        $rawEntry['version'] = $resolved['version'];

        return $rawEntry;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function writeValidatedConfigWithDependency(array $entry): Config
    {
        $manifest = $this->config->toArray();
        $replaced = false;
        $entryProvider = PremiumSourceResolver::providerForDependency($entry);

        foreach ($manifest['dependencies'] as $index => $dependency) {
            if ($this->dependencyMatchesIdentity($dependency, (string) $entry['kind'], (string) $entry['source'], (string) $entry['slug'], $entryProvider)) {
                $manifest['dependencies'][$index] = $entry;
                $replaced = true;
                break;
            }
        }

        if (! $replaced) {
            $manifest['dependencies'][] = $entry;
        }

        return Config::fromArray($this->config->repoRoot, $manifest, $this->config->manifestPath);
    }

    /**
     * @param array<string, mixed> $removedDependency
     * @param array<string, mixed> $entry
     */
    private function writeConfigReplacingDependency(array $removedDependency, array $entry): Config
    {
        $manifest = $this->config->toArray();
        $dependencies = [];
        $replaced = false;
        $entryProvider = PremiumSourceResolver::providerForDependency($entry);

        foreach ($manifest['dependencies'] as $dependency) {
            if (
                $this->dependencyMatchesIdentity(
                    $dependency,
                    (string) $removedDependency['kind'],
                    (string) $removedDependency['source'],
                    (string) $removedDependency['slug'],
                    PremiumSourceResolver::providerForDependency($removedDependency)
                )
            ) {
                continue;
            }

            if ($this->dependencyMatchesIdentity($dependency, (string) $entry['kind'], (string) $entry['source'], (string) $entry['slug'], $entryProvider)) {
                $dependencies[] = $entry;
                $replaced = true;
                continue;
            }

            $dependencies[] = $dependency;
        }

        if (! $replaced) {
            $dependencies[] = $entry;
        }

        $manifest['dependencies'] = array_values($dependencies);

        return Config::fromArray($this->config->repoRoot, $manifest, $this->config->manifestPath);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function findDependencyForAdoption(array $options): array
    {
        $componentKey = $this->nullableString($options['component-key'] ?? null);
        $slug = $this->nullableString($options['slug'] ?? null);
        $kind = $this->nullableString($options['kind'] ?? null);
        $fromSource = $this->nullableString($options['from-source'] ?? null) ?? 'local';
        $matches = [];

        foreach ($this->config->dependencies() as $dependency) {
            if ($componentKey !== null && PremiumSourceResolver::matchesComponentKey($dependency, $componentKey)) {
                return $dependency;
            }

            if (
                $componentKey === null
                && $slug !== null
                && $kind !== null
                && $dependency['slug'] === $slug
                && $dependency['kind'] === $kind
                && $dependency['source'] === $fromSource
            ) {
                $matches[] = $dependency;
            }
        }

        if ($matches === []) {
            throw new RuntimeException('No matching dependency entry was found to adopt.');
        }

        if (count($matches) > 1) {
            throw new RuntimeException('Multiple dependencies matched that selector. Re-run with --component-key.');
        }

        return $matches[0];
    }

    private function assertAddAllowed(string $kind, string $source, string $management): void
    {
        if ($source === 'wordpress.org' && ! in_array($kind, ['plugin', 'theme'], true)) {
            throw new RuntimeException('WordPress.org additions are only supported for plugin and theme kinds.');
        }

        if ($source === 'github-release' && ! in_array($kind, ['plugin', 'theme'], true)) {
            throw new RuntimeException('GitHub release additions are only supported for plugin and theme kinds.');
        }

        if (PremiumSourceResolver::isPremiumSource($source) && $kind !== 'plugin') {
            throw new RuntimeException(sprintf('%s additions are only supported for plugin kind.', $source));
        }

        if ($management === 'ignored' && $source !== 'local') {
            throw new RuntimeException('Ignored entries must use source=local.');
        }
    }

    private function assertDoesNotAlreadyExist(string $kind, string $source, string $slug, bool $force, ?string $provider): void
    {
        foreach ($this->config->dependencies() as $dependency) {
            if (! $this->dependencyMatchesIdentity($dependency, $kind, $source, $slug, $provider)) {
                continue;
            }

            if ($force) {
                return;
            }

            throw new RuntimeException(sprintf('Dependency already exists: %s', $dependency['component_key']));
        }
    }

    /**
     * @param array<string, mixed> $dependency
     * @param array<string, mixed> $options
     */
    private function resolvedPremiumProviderFromOptions(string $source, array $options): ?string
    {
        if (! PremiumSourceResolver::isPremiumSource($source)) {
            return null;
        }

        if ($source === 'premium') {
            return $this->nullableString($options['provider'] ?? null);
        }

        return PremiumSourceResolver::providerFor($source);
    }

    /**
     * @param array<string, mixed> $dependency
     */
    private function dependencyMatchesIdentity(array $dependency, string $kind, string $source, string $slug, ?string $provider): bool
    {
        if ((string) ($dependency['kind'] ?? '') !== $kind || (string) ($dependency['slug'] ?? '') !== $slug) {
            return false;
        }

        $dependencySource = (string) ($dependency['source'] ?? '');

        if (! PremiumSourceResolver::isPremiumSource($dependencySource) || ! PremiumSourceResolver::isPremiumSource($source)) {
            return $dependencySource === $source;
        }

        if ($provider === null) {
            return false;
        }

        return PremiumSourceResolver::providerForDependency($dependency) === $provider;
    }

    private function resolveManagement(array $options, string $source): string
    {
        $management = $this->nullableString($options['management'] ?? null);

        if ($management !== null) {
            return $management;
        }

        return $source === 'local' ? 'local' : 'managed';
    }

    private function resolveSlug(array $options): string
    {
        $slug = $this->nullableString($options['slug'] ?? null);
        $repository = $this->nullableString($options['github-repository'] ?? null);
        $path = $this->nullableString($options['path'] ?? null);

        if ($slug !== null) {
            return $slug;
        }

        if ($repository !== null) {
            return basename($repository);
        }

        if ($path !== null) {
            $basename = basename($path);
            $stem = pathinfo($basename, PATHINFO_FILENAME);

            return $stem !== '' ? $stem : $basename;
        }

        throw new RuntimeException('A slug or path is required.');
    }

    private function resolvePath(string $kind, string $slug, mixed $path): string
    {
        $provided = $this->nullableString($path);

        if ($provided !== null) {
            return trim(str_replace('\\', '/', $provided), '/');
        }

        return $this->config->rootForKind($kind) . '/' . $slug;
    }

    /**
     * @return list<string>
     */
    private function defaultExtraLabels(string $kind, string $slug): array
    {
        $prefix = match ($kind) {
            'plugin' => 'plugin',
            'theme' => 'theme',
            'mu-plugin-package', 'mu-plugin-file' => 'mu-plugin',
            default => 'runtime',
        };

        return [LabelHelper::normalize($prefix . ':' . $slug)];
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultPolicy(string $management, string $source): array
    {
        $policy = [
            'class' => match (true) {
                $management === 'managed' && $source === 'wordpress.org' => 'managed-upstream',
                $management === 'managed' && $source === 'github-release' => 'managed-private',
                $management === 'managed' && PremiumSourceResolver::isPremiumSource($source) => 'managed-premium',
                $management === 'ignored' => 'ignored',
                default => 'local-owned',
            },
            'allow_runtime_paths' => [],
        ];

        if ($management === 'managed') {
            $policy['sanitize_paths'] = [];
            $policy['sanitize_files'] = [];
        } else {
            $policy['strip_paths'] = [];
            $policy['strip_files'] = [];
        }

        return $policy;
    }

    /**
     * @param array<string, mixed> $dependency
     * @return list<string>
     */
    private function nextStepsForDependency(array $dependency): array
    {
        $steps = [
            sprintf('Review the manifest entry for %s in %s.', $dependency['slug'], $this->config->manifestPath),
        ];

        $tokenEnv = $dependency['source_config']['github_token_env'] ?? null;

        if (is_string($tokenEnv) && $tokenEnv !== '') {
            $steps[] = sprintf('Export %s locally before running authoring or sync commands.', $tokenEnv);
            $steps[] = sprintf('Add a GitHub Actions secret named %s in the downstream repository.', $tokenEnv);
        }

        if (PremiumSourceResolver::isPremiumSource((string) $dependency['source'])) {
            $steps[] = sprintf(
                'Provide premium credentials for %s through %s locally and in GitHub Actions.',
                $dependency['component_key'],
                PremiumCredentialsStore::envName()
            );
        }

        return $steps;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function requiredString(array $options, string $key): string
    {
        $value = $this->nullableString($options[$key] ?? null);

        if ($value === null) {
            throw new RuntimeException(sprintf('--%s is required.', $key));
        }

        return $value;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function looksLikeGitHubAuthFailure(RuntimeException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'status 401')
            || str_contains($message, 'status 403')
            || str_contains($message, 'status 404');
    }

    /**
     * @param array<string, mixed> $dependency
     */
    private function resolveCurrentInstalledVersion(array $dependency): string
    {
        $currentVersion = $this->nullableString($dependency['version'] ?? null);

        if ($currentVersion === null) {
            $resolved = $this->resolveLocalDependency(
                $dependency,
                $this->nullableString($dependency['name'] ?? null),
                $this->nullableString($dependency['main_file'] ?? null),
                null
            );
            $currentVersion = $this->nullableString($resolved['version'] ?? null);
        }

        if ($currentVersion === null) {
            throw new RuntimeException(sprintf(
                'Could not determine the current installed version for %s. Re-run with --version explicitly.',
                $dependency['component_key']
            ));
        }

        return $currentVersion;
    }

    private function refreshAdminGovernance(Config $config): void
    {
        if ($this->adminGovernanceExporter !== null) {
            $this->adminGovernanceExporter->refresh($config);
        }
    }

    private function persistConfig(Config $config): void
    {
        $this->manifestWriter->write($config);
        $this->refreshAdminGovernance($config);
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

    /**
     * @param array<string, array{exists:bool, contents:?string}> $states
     */
    private function restoreFileStates(array $states): void
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
}
