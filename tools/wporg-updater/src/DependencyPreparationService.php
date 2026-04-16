<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;
use ZipArchive;

final class DependencyPreparationService
{
    public function __construct(
        private readonly Config $config,
        private readonly DependencyMetadataResolver $metadataResolver,
        private readonly RuntimeInspector $runtimeInspector,
        private readonly ManagedSourceRegistry $managedSourceRegistry,
        private readonly DependencyAuthoringSupport $support,
    ) {
    }

    /**
     * @param array<string, mixed> $rawEntry
     * @param array<string, mixed> $options
     * @return array{entry:array<string,mixed>,prepared_source_path:string,cleanup_root:string,sanitize_paths:list<string>,sanitize_files:list<string>,source_reference:string,would_replace:bool}
     */
    public function prepareManagedDependency(array $rawEntry, array $options, ?string $requestedVersion, bool $replace, bool $privateGitHub): array
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
            $repository = $this->support->requiredString($options, 'github-repository');
            $tokenEnv = $this->support->nullableString($options['github-token-env'] ?? null);
            $defaultTokenEnv = DependencyAuthoringService::defaultGitHubTokenEnv((string) $rawEntry['slug'], $repository);

            $rawEntry['source_config']['github_repository'] = $repository;
            $rawEntry['source_config']['github_release_asset_pattern'] = $this->support->nullableString($options['github-release-asset-pattern'] ?? null);
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
            $rawEntry['source_config']['credential_key'] = $this->support->nullableString($options['credential-key'] ?? null);
            $provider = $this->support->nullableString($options['provider'] ?? null);

            if ((string) $rawEntry['source'] === 'premium') {
                if ($provider === null) {
                    throw new RuntimeException('--provider is required when --source=premium.');
                }

                $rawEntry['source_config']['provider'] = $provider;
            }

            $providerProductId = $this->support->nullableString($options['provider-product-id'] ?? null);

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
            $this->support->nullableString($rawEntry['main_file'] ?? null)
        );

        $resolved = $this->metadataResolver->resolveFromAbsolutePath(
            $sourcePath,
            (string) $rawEntry['kind'],
            is_string($displayName) ? $displayName : (string) $rawEntry['name'],
            $this->support->nullableString($rawEntry['main_file'] ?? null),
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
    public function resolveLocalDependency(array $rawEntry, ?string $name, ?string $mainFile, ?string $version): array
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
     * @param array<string, mixed> $dependency
     */
    public function resolveCurrentInstalledVersion(array $dependency): string
    {
        $currentVersion = $this->support->nullableString($dependency['version'] ?? null);

        if ($currentVersion === null) {
            $resolved = $this->resolveLocalDependency(
                $dependency,
                $this->support->nullableString($dependency['name'] ?? null),
                $this->support->nullableString($dependency['main_file'] ?? null),
                null
            );
            $currentVersion = $this->support->nullableString($resolved['version'] ?? null);
        }

        if ($currentVersion === null) {
            throw new RuntimeException(sprintf(
                'Could not determine the current installed version for %s. Re-run with --version explicitly.',
                $dependency['component_key']
            ));
        }

        return $currentVersion;
    }

    private function looksLikeGitHubAuthFailure(RuntimeException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'status 401')
            || str_contains($message, 'status 403')
            || str_contains($message, 'status 404');
    }
}
