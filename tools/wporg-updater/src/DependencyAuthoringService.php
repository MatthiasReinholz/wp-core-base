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
        private readonly ManifestWriter $manifestWriter,
        private readonly WordPressOrgClient $wordPressOrgClient,
        private readonly GitHubReleaseClient $gitHubReleaseClient,
        private readonly HttpClient $httpClient,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function addDependency(array $options): array
    {
        $kind = $this->requiredString($options, 'kind');
        $source = $this->requiredString($options, 'source');
        $management = $this->resolveManagement($options, $source);
        $slug = $this->resolveSlug($options);
        $path = $this->resolvePath($kind, $slug, $options['path'] ?? null);
        $name = $this->nullableString($options['name'] ?? null);
        $version = $this->nullableString($options['version'] ?? null);
        $archiveSubdir = trim((string) ($options['archive-subdir'] ?? ''), '/');
        $mainFile = $this->nullableString($options['main-file'] ?? null);
        $privateGitHub = (bool) ($options['private'] ?? false);
        $replace = isset($options['replace']);
        $force = isset($options['force']);

        $this->assertAddAllowed($kind, $source, $management);
        $this->assertDoesNotAlreadyExist($kind, $source, $slug, $force);

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
            ],
            'policy' => $this->defaultPolicy($management, $source),
        ];

        if ($source === 'wordpress.org' || $source === 'github-release') {
            $rawEntry = $this->installManagedDependency(
                $rawEntry,
                $options,
                $version,
                $replace,
                $privateGitHub,
            );
        } else {
            $rawEntry = $this->resolveLocalDependency($rawEntry, $name, $mainFile, $version);
        }

        $nextConfig = $this->writeValidatedConfigWithDependency($rawEntry);
        $this->config = $nextConfig;

        $result = $nextConfig->dependencyByKey(sprintf('%s:%s:%s', $kind, $source, $slug));
        $result['next_steps'] = $this->nextStepsForDependency($result);

        return $result;
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
        $deletePath = isset($options['delete-path']);

        $dependencies = $this->config->dependencies();
        $removed = null;

        foreach ($dependencies as $index => $dependency) {
            if ($componentKey !== null && $dependency['component_key'] === $componentKey) {
                $removed = $dependency;
                unset($dependencies[$index]);
                break;
            }

            if ($componentKey === null && $slug !== null && $kind !== null && $dependency['slug'] === $slug && $dependency['kind'] === $kind) {
                $removed = $dependency;
                unset($dependencies[$index]);
                break;
            }
        }

        if ($removed === null) {
            throw new RuntimeException('No matching dependency entry was found to remove.');
        }

        $nextConfig = $this->config->withDependencies(array_values($dependencies));
        $this->manifestWriter->write($nextConfig);
        $this->config = Config::load($this->config->repoRoot, $this->config->manifestPath);

        if ($deletePath) {
            $this->runtimeInspector->clearPath($this->config->repoRoot . '/' . $removed['path']);
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
     * @param array<string, mixed> $rawEntry
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function installManagedDependency(array $rawEntry, array $options, ?string $requestedVersion, bool $replace, bool $privateGitHub): array
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

        try {
            if ($rawEntry['source'] === 'wordpress.org') {
                $info = $this->wordPressOrgClient->fetchComponentInfo($rawEntry['kind'], $rawEntry['slug']);
                $version = $requestedVersion ?? $this->wordPressOrgClient->latestVersion($rawEntry['kind'], $info);
                $downloadUrl = $this->wordPressOrgClient->downloadUrlForVersion($rawEntry['kind'], $info, $version);
                $this->httpClient->downloadToFile($downloadUrl, $archivePath);
                $displayName = $info['name'] ?? $rawEntry['name'];
            } else {
                $repository = $this->requiredString($options, 'github-repository');
                $tokenEnv = $this->nullableString($options['github-token-env'] ?? null);

                if ($privateGitHub && $tokenEnv === null) {
                    $tokenEnv = self::defaultGitHubTokenEnv($rawEntry['slug'], $repository);
                }

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

                $releases = $this->gitHubReleaseClient->fetchStableReleases($rawEntry);
                $selectedRelease = $this->selectGitHubRelease($releases, $rawEntry, $requestedVersion);
                $version = $this->gitHubReleaseClient->latestVersion($selectedRelease, $rawEntry);
                $this->gitHubReleaseClient->downloadReleaseToFile($selectedRelease, $rawEntry, $archivePath);
                $displayName = $rawEntry['name'];
            }

            $zip = new ZipArchive();

            if ($zip->open($archivePath) !== true) {
                throw new RuntimeException(sprintf('Failed to open dependency archive: %s', $archivePath));
            }

            ZipExtractor::extractValidated($zip, $extractPath);
            $zip->close();

            $sourcePath = $this->resolveExtractedDependencyPath(
                $extractPath,
                $rawEntry['archive_subdir'],
                $rawEntry['slug'],
                $rawEntry['kind'],
                $rawEntry['main_file']
            );

            $resolved = $this->metadataResolver->resolveFromAbsolutePath(
                $sourcePath,
                $rawEntry['kind'],
                is_string($displayName) ? $displayName : $rawEntry['name'],
                $rawEntry['main_file'],
                $version
            );

            $rawEntry['name'] = $resolved['name'];
            $rawEntry['main_file'] = $resolved['main_file'];
            $rawEntry['version'] = $resolved['version'] ?? $version;

            [$sanitizePaths, $sanitizeFiles] = $this->translatedManagedSanitizeRulesForPath($rawEntry['path'], $rawEntry);
            $this->runtimeInspector->stripPath($sourcePath, $sanitizePaths, $sanitizeFiles);
            $this->runtimeInspector->assertPathIsClean($sourcePath, (array) $rawEntry['policy']['allow_runtime_paths'], [], $sanitizePaths, $sanitizeFiles);

            $this->runtimeInspector->clearPath($destinationPath);
            $this->runtimeInspector->copyPath($sourcePath, $destinationPath);

            $rawEntry['checksum'] = $this->runtimeInspector->computeChecksum($destinationPath, [], $sanitizePaths, $sanitizeFiles);

            return $rawEntry;
        } finally {
            $this->runtimeInspector->clearPath($tempDir);
        }
    }

    /**
     * @param array<string, mixed> $rawEntry
     * @return array<string, mixed>
     */
    private function resolveLocalDependency(array $rawEntry, ?string $name, ?string $mainFile, ?string $version): array
    {
        $resolved = $this->metadataResolver->resolveFromExistingPath(
            $this->config,
            $rawEntry['path'],
            $rawEntry['kind'],
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

        foreach ($manifest['dependencies'] as $index => $dependency) {
            if (
                (string) ($dependency['kind'] ?? '') === (string) $entry['kind']
                && (string) ($dependency['source'] ?? '') === (string) $entry['source']
                && (string) ($dependency['slug'] ?? '') === (string) $entry['slug']
            ) {
                $manifest['dependencies'][$index] = $entry;
                $replaced = true;
                break;
            }
        }

        if (! $replaced) {
            $manifest['dependencies'][] = $entry;
        }

        $nextConfig = Config::fromArray($this->config->repoRoot, $manifest, $this->config->manifestPath);
        $this->manifestWriter->write($nextConfig);

        return Config::load($this->config->repoRoot, $this->config->manifestPath);
    }

    private function assertAddAllowed(string $kind, string $source, string $management): void
    {
        if ($source === 'wordpress.org' && ! in_array($kind, ['plugin', 'theme'], true)) {
            throw new RuntimeException('WordPress.org additions are only supported for plugin and theme kinds.');
        }

        if ($source === 'github-release' && ! in_array($kind, ['plugin', 'theme'], true)) {
            throw new RuntimeException('GitHub release additions are only supported for plugin and theme kinds.');
        }

        if ($management === 'ignored' && $source !== 'local') {
            throw new RuntimeException('Ignored entries must use source=local.');
        }
    }

    private function assertDoesNotAlreadyExist(string $kind, string $source, string $slug, bool $force): void
    {
        foreach ($this->config->dependencies() as $dependency) {
            if ($dependency['component_key'] !== sprintf('%s:%s:%s', $kind, $source, $slug)) {
                continue;
            }

            if ($force) {
                return;
            }

            throw new RuntimeException(sprintf('Dependency already exists: %s', $dependency['component_key']));
        }
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
     * @param list<array<string, mixed>> $releases
     * @return array<string, mixed>
     */
    private function selectGitHubRelease(array $releases, array $dependency, ?string $requestedVersion): array
    {
        if ($requestedVersion === null) {
            return $releases[0];
        }

        foreach ($releases as $release) {
            if ($this->gitHubReleaseClient->latestVersion($release, $dependency) === $requestedVersion) {
                return $release;
            }
        }

        throw new RuntimeException(sprintf(
            'No published stable GitHub release matching version %s was found for %s.',
            $requestedVersion,
            $dependency['slug']
        ));
    }

    private function resolveExtractedDependencyPath(
        string $extractPath,
        string $archiveSubdir,
        string $slug,
        string $kind,
        ?string $mainFile = null,
    ): string
    {
        $entries = array_values(array_filter(scandir($extractPath) ?: [], static fn (string $entry): bool => $entry !== '.' && $entry !== '..'));
        $candidateBases = [$extractPath];

        foreach ($entries as $entry) {
            $candidate = $extractPath . '/' . $entry;

            if (is_dir($candidate)) {
                $candidateBases[] = $candidate;
            }
        }

        $matches = [];

        foreach ($candidateBases as $candidateBase) {
            $candidatePath = $candidateBase;

            if ($archiveSubdir !== '') {
                $candidatePath .= '/' . $archiveSubdir;
            }

            if (! file_exists($candidatePath)) {
                continue;
            }

            try {
                if ($this->config->isFileKind($kind)) {
                    if (is_file($candidatePath)) {
                        $matches[] = $candidatePath;
                    }

                    continue;
                }

                if (! is_dir($candidatePath)) {
                    continue;
                }

                $this->metadataResolver->resolveMainFile($candidatePath, $kind, $mainFile);
                $matches[] = $candidatePath;
            } catch (RuntimeException) {
                continue;
            }
        }

        $matches = array_values(array_unique(array_filter($matches, static fn (string $match): bool => file_exists($match))));

        if (count($matches) === 1) {
            return $matches[0];
        }

        if ($matches === []) {
            throw new RuntimeException(sprintf('Could not locate the extracted dependency payload for %s.', $slug));
        }

        throw new RuntimeException(sprintf('Extracted archive for %s matched multiple candidate dependency payloads.', $slug));
    }

    /**
     * @param array<string, mixed> $dependency
     * @return array{0:list<string>,1:list<string>}
     */
    private function translatedManagedSanitizeRulesForPath(string $rootPath, array $dependency): array
    {
        $sanitizePaths = [];

        foreach ((array) $this->config->runtime['managed_sanitize_paths'] as $sanitizePath) {
            if ($sanitizePath === $rootPath) {
                $sanitizePaths[] = '';
                continue;
            }

            if (str_starts_with($sanitizePath, $rootPath . '/')) {
                $sanitizePaths[] = substr($sanitizePath, strlen($rootPath) + 1);
            }
        }

        return [
            array_values(array_unique(array_merge($sanitizePaths, (array) ($dependency['policy']['sanitize_paths'] ?? [])))),
            array_values(array_unique(array_merge($this->config->managedSanitizeFiles(), (array) ($dependency['policy']['sanitize_files'] ?? [])))),
        ];
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

        return $steps;
    }

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
}
