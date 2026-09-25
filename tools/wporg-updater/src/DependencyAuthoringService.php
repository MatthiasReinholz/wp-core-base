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
        $removedAbsolutePath = $this->config->repoRoot . '/' . $removed['path'];
        $stateManager = new ConfigMutationStateManager($this->manifestWriter, $this->runtimeInspector, $this->adminGovernanceExporter);
        $transaction = new DependencyMutationTransaction($this->config, $nextConfig, $stateManager, $this->runtimeInspector, $deletePath ? $removedAbsolutePath : null);
        try {
            if ($deletePath) {
                $transaction->beginRuntimeMutation();
                $this->runtimeInspector->clearPath($removedAbsolutePath);
            }
            $stateManager->persist($nextConfig, $this->config);
        } catch (\Throwable $exception) {
            $transaction->rollback($exception);
        }
        $this->config = $nextConfig;
        $transaction->commit();

        return [
            'removed' => $removed,
            'deleted_path' => $deletePath,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function dependencyList(bool $includeFreshness = false, bool $failOnSourceErrors = false): array
    {
        $items = [];

        foreach ($this->config->dependencies() as $dependency) {
            $item = [
                'component_key' => $dependency['component_key'],
                'name' => $dependency['name'],
                'slug' => $dependency['slug'],
                'kind' => $dependency['kind'],
                'management' => $dependency['management'],
                'source' => $dependency['source'],
                'path' => $dependency['path'],
                'version' => $dependency['version'],
            ];

            if ($includeFreshness) {
                $item['freshness'] = $this->dependencyFreshness($dependency, $failOnSourceErrors);
            }

            $items[] = $item;
        }

        return $items;
    }

    public function renderDependencyList(bool $includeFreshness = false, bool $failOnSourceErrors = false): string
    {
        $lines = [];
        $lines[] = 'Configured dependencies:';
        $lines[] = '';
        $dependencies = $this->dependencyList($includeFreshness, $failOnSourceErrors);

        foreach (['managed', 'local', 'ignored'] as $management) {
            $group = array_values(array_filter(
                $dependencies,
                static fn (array $dependency): bool => $dependency['management'] === $management
            ));

            if ($group === []) {
                continue;
            }

            $lines[] = strtoupper($management);

            foreach ($group as $dependency) {
                $line = sprintf(
                    '- %s | %s | %s | %s | %s',
                    $dependency['kind'],
                    $dependency['source'],
                    $dependency['slug'],
                    $dependency['path'],
                    $dependency['version'] ?? '-'
                );

                if ($includeFreshness) {
                    $freshness = is_array($dependency['freshness'] ?? null) ? $dependency['freshness'] : [];
                    $line .= sprintf(
                        ' | latest:%s | %s',
                        (string) ($freshness['latest_version'] ?? '-'),
                        (string) ($freshness['status'] ?? 'unknown')
                    );

                    if (isset($freshness['error'])) {
                        $line .= sprintf(' (%s)', (string) $freshness['error']);
                    }
                }

                $lines[] = $line;
            }

            $lines[] = '';
        }

        return rtrim(implode("\n", $lines)) . "\n";
    }

    /**
     * @param array<string, mixed> $dependency
     * @return array<string, mixed>|null
     */
    private function dependencyFreshness(array $dependency, bool $failOnSourceErrors): ?array
    {
        if ($dependency['management'] !== 'managed' || $dependency['source'] === 'local') {
            return null;
        }

        try {
            $catalog = $this->managedSourceRegistry->fetchCatalog($dependency);
        } catch (RuntimeException $exception) {
            if ($failOnSourceErrors) {
                throw $exception;
            }

            return [
                'status' => 'source-error',
                'latest_version' => null,
                'latest_release_at' => null,
                'error' => OutputRedactor::redact($exception->getMessage()),
            ];
        }

        $currentVersion = is_string($dependency['version'] ?? null) && $dependency['version'] !== ''
            ? (string) $dependency['version']
            : null;
        $latestVersion = is_string($catalog['latest_version'] ?? null) && $catalog['latest_version'] !== ''
            ? (string) $catalog['latest_version']
            : null;
        $latestReleaseAt = is_string($catalog['latest_release_at'] ?? null) && $catalog['latest_release_at'] !== ''
            ? (string) $catalog['latest_release_at']
            : null;

        if ($currentVersion === null || $latestVersion === null) {
            $status = 'unknown';
        } elseif (version_compare($currentVersion, $latestVersion, '<')) {
            $status = 'outdated';
        } elseif (version_compare($currentVersion, $latestVersion, '>')) {
            $status = 'ahead';
        } else {
            $status = 'current';
        }

        return [
            'status' => $status,
            'current_version' => $currentVersion,
            'latest_version' => $latestVersion,
            'latest_release_at' => $latestReleaseAt,
        ];
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

    public static function defaultGitLabTokenEnv(string $slug, ?string $project = null): string
    {
        $basis = $slug !== '' ? $slug : (is_string($project) ? basename($project) : 'dependency');
        $normalized = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $basis) ?? $basis);
        $normalized = trim(preg_replace('/_+/', '_', $normalized) ?? $normalized, '_');

        if ($normalized === '') {
            $normalized = 'DEPENDENCY';
        }

        return 'WP_CORE_BASE_GITLAB_TOKEN_' . $normalized;
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
        $archiveSubdir = (string) ($options['archive-subdir'] ?? '');
        if ($archiveSubdir !== '') {
            $archiveSubdir = ConfigPathRules::normalizedRelativePath($archiveSubdir, 'archive-subdir');
        }
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
                'gitlab_project' => null,
                'gitlab_release_asset_pattern' => null,
                'gitlab_token_env' => null,
                'gitlab_api_base' => null,
                'generic_json_url' => null,
                'credential_key' => null,
                'provider' => null,
                'provider_product_id' => null,
            ],
            'policy' => $this->defaultPolicy($management, $source),
        ];

        $preparedSourcePath = null;
        $workspace = null;
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
            $workspace = $managedPreparation['workspace'];
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
            'workspace' => $workspace,
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
        $stateManager = new ConfigMutationStateManager($this->manifestWriter, $this->runtimeInspector, $this->adminGovernanceExporter);
        $replaceRuntime = is_string($preparedSourcePath) && $preparedSourcePath !== '';
        try {
            $transaction = new DependencyMutationTransaction($this->config, $nextConfig, $stateManager, $this->runtimeInspector, $replaceRuntime ? $destinationAbsolutePath : null);
            try {
                if ($replaceRuntime) {
                    $transaction->beginRuntimeMutation();
                    $this->runtimeInspector->clearPath($destinationAbsolutePath);
                    $this->runtimeInspector->copyPath($preparedSourcePath, $destinationAbsolutePath);
                }
                $stateManager->persist($nextConfig, $this->config);
                $result = $nextConfig->dependencyByKey(PremiumSourceResolver::componentKey(
                    (string) $entry['kind'], (string) $entry['source'], (string) $entry['slug'],
                    is_array($entry['source_config'] ?? null) ? $entry['source_config'] : []
                ));
                $result['next_steps'] = $this->nextStepsForDependency($result);
            } catch (\Throwable $exception) {
                $transaction->rollback($exception);
            }
            $this->config = $nextConfig;
            $transaction->commit();
            return $result;
        } finally {
            $this->cleanupPreparedOperation($prepared);
        }
    }

    /**
     * @param array<string, mixed> $prepared
     */
    private function cleanupPreparedOperation(array $prepared): void
    {
        $workspace = $prepared['workspace'] ?? null;
        if ($workspace instanceof TempWorkspace) {
            $workspace->close();
        }
    }

    /**
     * @param array<string, mixed> $rawEntry
     * @param array<string, mixed> $options
     * @return array{entry:array<string,mixed>,prepared_source_path:string,workspace:TempWorkspace,sanitize_paths:list<string>,sanitize_files:list<string>,source_reference:string,would_replace:bool}
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

        $workspace = TempWorkspace::create($this->config->repoRoot, 'dependency-authoring');
        $tempDir = $workspace->path();
        $archivePath = $tempDir . '/payload.zip';
        $extractPath = $tempDir . '/extract';
        mkdir($extractPath, 0775, true);

        if ($rawEntry['source'] === 'github-release') {
            $repository = $this->requiredString($options, 'github-repository');
            $assetPattern = $this->nullableString($options['github-release-asset-pattern'] ?? null);
            $tokenEnv = $this->nullableString($options['github-token-env'] ?? null);
            $defaultTokenEnv = self::defaultGitHubTokenEnv((string) $rawEntry['slug'], $repository);

            $rawEntry['source_config']['github_repository'] = $repository;
            $rawEntry['source_config']['github_release_asset_pattern'] = $assetPattern;
            $rawEntry['source_config']['github_token_env'] = $tokenEnv;

            if ($assetPattern === null) {
                throw new RuntimeException(
                    'GitHub hosted-release authoring requires --github-release-asset-pattern. If you intentionally want the weaker zipball fallback, create or edit the manifest manually with source_config.verification_mode=none.'
                );
            }

            if ($tokenEnv !== null && getenv($tokenEnv) === false) {
                throw new RuntimeException(sprintf(
                    'Environment variable %s is required to add private GitHub dependency %s. Export it locally, then rerun.',
                    $tokenEnv,
                    $rawEntry['slug']
                ));
            }

            try {
                $catalog = $this->managedSourceRegistry->fetchCatalog($rawEntry);
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

                    $catalog = $this->managedSourceRegistry->fetchCatalog($rawEntry);
                } else {
                    throw $exception;
                }
            }
        } elseif ($rawEntry['source'] === 'gitlab-release') {
            $project = $this->requiredString($options, 'gitlab-project');
            $assetPattern = $this->nullableString($options['gitlab-release-asset-pattern'] ?? null);
            $tokenEnv = $this->nullableString($options['gitlab-token-env'] ?? null);
            $defaultTokenEnv = self::defaultGitLabTokenEnv((string) $rawEntry['slug'], $project);

            $rawEntry['source_config']['gitlab_project'] = $project;
            $rawEntry['source_config']['gitlab_release_asset_pattern'] = $assetPattern;
            $rawEntry['source_config']['gitlab_token_env'] = $tokenEnv;
            $rawEntry['source_config']['gitlab_api_base'] = $this->nullableString($options['gitlab-api-base'] ?? null);

            if ($assetPattern === null) {
                throw new RuntimeException('GitLab hosted-release authoring requires --gitlab-release-asset-pattern.');
            }

            if ($tokenEnv !== null && getenv($tokenEnv) === false) {
                throw new RuntimeException(sprintf(
                    'Environment variable %s is required to add private GitLab dependency %s. Export it locally, then rerun.',
                    $tokenEnv,
                    $rawEntry['slug']
                ));
            }

            try {
                $catalog = $this->managedSourceRegistry->fetchCatalog($rawEntry);
            } catch (RuntimeException $exception) {
                if ($tokenEnv === null && (($options['private'] ?? false) === true || $this->looksLikeGitLabAuthFailure($exception))) {
                    $rawEntry['source_config']['gitlab_token_env'] = $defaultTokenEnv;
                    $envValue = getenv($defaultTokenEnv);

                    if (! is_string($envValue) || $envValue === '') {
                        throw new RuntimeException(sprintf(
                            'GitLab release access for %s may require authentication. Export %s locally, or pass --gitlab-token-env=YOUR_TOKEN_ENV. If the project is public, verify that --gitlab-project is correct.',
                            $project,
                            $defaultTokenEnv
                        ), previous: $exception);
                    }

                    $catalog = $this->managedSourceRegistry->fetchCatalog($rawEntry);
                } else {
                    throw $exception;
                }
            }
        } elseif ($rawEntry['source'] === 'generic-json') {
            $rawEntry['source_config']['generic_json_url'] = $this->requiredString($options, 'generic-json-url');
            $catalog = $this->managedSourceRegistry->fetchCatalog($rawEntry);
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
                if (filter_var($providerProductId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                    throw new RuntimeException('provider-product-id must be a positive integer.');
                }
                $rawEntry['source_config']['provider_product_id'] = (int) $providerProductId;
            }

            $catalog = $this->managedSourceRegistry->fetchCatalog($rawEntry);
        }

        $source = $this->managedSourceRegistry->for($rawEntry);
        $version = $requestedVersion ?? (string) ($catalog['latest_version'] ?? '');

        if ($version === '') {
            throw new RuntimeException(sprintf('Could not resolve a version for %s.', $rawEntry['slug']));
        }

        $releaseData = $this->managedSourceRegistry->releaseDataForVersion(
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
            'workspace' => $workspace,
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

        $manifest['dependencies'] = $dependencies;

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

        if ($source === 'gitlab-release' && ! in_array($kind, ['plugin', 'theme'], true)) {
            throw new RuntimeException('GitLab release additions are only supported for plugin and theme kinds.');
        }

        if ($source === 'generic-json' && ! in_array($kind, ['plugin', 'theme'], true)) {
            throw new RuntimeException('Generic JSON additions are only supported for plugin and theme kinds.');
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
        $project = $this->nullableString($options['gitlab-project'] ?? null);
        $path = $this->nullableString($options['path'] ?? null);

        if ($slug !== null) {
            return $slug;
        }

        if ($repository !== null) {
            return basename($repository);
        }

        if ($project !== null) {
            return basename($project);
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
            return ConfigPathRules::normalizedRelativePath($provided, 'dependency path');
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
                $management === 'managed' && $source === 'gitlab-release' => 'managed-private',
                $management === 'managed' && $source === 'generic-json' => 'managed-private',
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

        $tokenEnv = $dependency['source_config']['github_token_env'] ?? $dependency['source_config']['gitlab_token_env'] ?? null;

        if (is_string($tokenEnv) && $tokenEnv !== '') {
            $steps[] = sprintf('Export %s locally before running authoring or sync commands.', $tokenEnv);
            $steps[] = sprintf('Add an automation secret or CI/CD variable named %s in the downstream repository.', $tokenEnv);
        }

        if (PremiumSourceResolver::isPremiumSource((string) $dependency['source'])) {
            $steps[] = sprintf(
                'Provide premium credentials for %s through %s locally and in repository automation.',
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

    private function looksLikeGitLabAuthFailure(RuntimeException $exception): bool
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

}
