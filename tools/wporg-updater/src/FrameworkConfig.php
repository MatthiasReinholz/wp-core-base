<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class FrameworkConfig
{
    /**
     * @param array{mode:string, path:string, asset_name:string} $distribution
     * @param array{provider:string, reference:string, api_base:string} $releaseSource
     * @param array{wordpress_core:string, managed_components:list<array{name:string, version:string, kind:string}>} $baseline
     * @param array{managed_files:array<string, string>} $scaffold
     */
    public function __construct(
        public readonly string $repoRoot,
        public readonly string $path,
        public readonly string $repository,
        public readonly string $version,
        public readonly string $releaseChannel,
        public readonly array $distribution,
        public readonly array $releaseSource,
        public readonly array $baseline,
        public readonly array $scaffold,
    ) {
    }

    public static function load(string $repoRoot, ?string $path = null): self
    {
        $resolvedPath = $path ?? $repoRoot . '/.wp-core-base/framework.php';

        if (! is_file($resolvedPath)) {
            throw new RuntimeException(sprintf('Framework metadata file not found: %s', $resolvedPath));
        }

        $data = require $resolvedPath;

        if (! is_array($data)) {
            throw new RuntimeException('Framework metadata file must return an array.');
        }

        $distribution = self::normalizeDistribution($data['distribution'] ?? []);
        $releaseSource = self::normalizeReleaseSource($data);
        $baseline = self::normalizeBaseline($data['baseline'] ?? []);
        $scaffold = self::normalizeScaffold($data['scaffold'] ?? []);

        return new self(
            repoRoot: $repoRoot,
            path: $resolvedPath,
            repository: self::legacyRepositoryValue($data, $releaseSource),
            version: self::normalizeVersion((string) ($data['version'] ?? '')),
            releaseChannel: self::string($data['release_channel'] ?? 'stable', 'release_channel'),
            distribution: $distribution,
            releaseSource: $releaseSource,
            baseline: $baseline,
            scaffold: $scaffold,
        );
    }

    public function normalizedVersion(): string
    {
        return self::normalizeVersion($this->version);
    }

    public function distributionPath(): string
    {
        return $this->distribution['path'];
    }

    public function assetName(): string
    {
        return $this->distribution['asset_name'];
    }

    public function checksumAssetName(): string
    {
        return $this->assetName() . '.sha256';
    }

    public function checksumSignatureAssetName(): string
    {
        return $this->checksumAssetName() . '.sig';
    }

    public function releaseSourceProvider(): string
    {
        return $this->releaseSource['provider'];
    }

    public function releaseSourceReference(): string
    {
        return $this->releaseSource['reference'];
    }

    public function releaseSourceApiBase(): string
    {
        return $this->releaseSource['api_base'];
    }

    public function releaseSourceReferenceLabel(): string
    {
        return match ($this->releaseSourceProvider()) {
            'gitlab-release' => 'Source project',
            default => 'Source repository',
        };
    }

    public function releaseSourceKindLabel(): string
    {
        return match ($this->releaseSourceProvider()) {
            'gitlab-release' => 'GitLab release project',
            default => 'GitHub release repository',
        };
    }

    public function releaseSourceReferenceUrl(): string
    {
        return rtrim($this->releaseSourceWebBase(), '/') . '/' . implode('/', array_map(
            'rawurlencode',
            self::referenceSegments($this->releaseSourceReference())
        ));
    }

    public function releaseSourceIdentity(): string
    {
        return sprintf(
            '%s|%s|%s',
            $this->releaseSourceProvider(),
            rtrim($this->releaseSourceApiBase(), '/'),
            $this->releaseSourceReference()
        );
    }

    /**
     * @return array<string, string>
     */
    public function managedFiles(): array
    {
        return $this->scaffold['managed_files'];
    }

    /**
     * @param array<string, string> $managedFiles
     * @param list<array{name:string, version:string, kind:string}> $managedComponents
     * @param array{provider:string, reference:string, api_base:string}|null $releaseSource
     */
    public function withInstalledRelease(
        string $version,
        string $wordPressCoreVersion,
        array $managedComponents,
        array $managedFiles,
        ?array $releaseSource = null,
        ?string $distributionPath = null,
        ?string $repoRoot = null,
        ?string $path = null,
    ): self {
        return new self(
            repoRoot: $repoRoot ?? $this->repoRoot,
            path: $path ?? $this->path,
            repository: $this->repository,
            version: self::normalizeVersion($version),
            releaseChannel: $this->releaseChannel,
            distribution: [
                'mode' => $this->distribution['mode'],
                'path' => $distributionPath ?? $this->distribution['path'],
                'asset_name' => $this->distribution['asset_name'],
            ],
            releaseSource: $releaseSource ?? $this->releaseSource,
            baseline: [
                'wordpress_core' => $wordPressCoreVersion,
                'managed_components' => $managedComponents,
            ],
            scaffold: [
                'managed_files' => $managedFiles,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'repository' => $this->legacyRepositoryForSerialization(),
            'version' => $this->version,
            'release_channel' => $this->releaseChannel,
            'distribution' => $this->distribution,
            'release_source' => $this->releaseSource,
            'baseline' => $this->baseline,
            'scaffold' => $this->scaffold,
        ];
    }

    /**
     * @param array<string, mixed> $value
     * @return array{provider:string, reference:string, api_base:string}
     */
    private static function normalizeReleaseSource(array $value): array
    {
        $raw = $value['release_source'] ?? null;

        if ($raw === null) {
            $repository = self::string($value['repository'] ?? '', 'repository');

            return [
                'provider' => 'github-release',
                'reference' => $repository,
                'api_base' => 'https://api.github.com',
            ];
        }

        if (! is_array($raw)) {
            throw new RuntimeException('release_source must be an array.');
        }

        $provider = self::string($raw['provider'] ?? '', 'release_source.provider');

        if (! in_array($provider, ['github-release', 'gitlab-release'], true)) {
            throw new RuntimeException('release_source.provider must be "github-release" or "gitlab-release".');
        }

        $reference = self::string($raw['reference'] ?? '', 'release_source.reference');
        $apiBase = self::string(
            $raw['api_base'] ?? ($provider === 'gitlab-release' ? 'https://gitlab.com/api/v4' : 'https://api.github.com'),
            'release_source.api_base'
        );

        return [
            'provider' => $provider,
            'reference' => $reference,
            'api_base' => rtrim($apiBase, '/'),
        ];
    }

    /**
     * @param array<string, mixed> $value
     * @return array{mode:string, path:string, asset_name:string}
     */
    private static function normalizeDistribution(array $value): array
    {
        $mode = self::string($value['mode'] ?? 'vendor-snapshot', 'distribution.mode');

        if ($mode !== 'vendor-snapshot') {
            throw new RuntimeException('distribution.mode must be "vendor-snapshot".');
        }

        $path = self::relativePath($value['path'] ?? 'vendor/wp-core-base', 'distribution.path');
        $assetName = self::string($value['asset_name'] ?? 'wp-core-base-vendor-snapshot.zip', 'distribution.asset_name');

        return [
            'mode' => $mode,
            'path' => $path,
            'asset_name' => $assetName,
        ];
    }

    /**
     * @param array<string, mixed> $value
     * @return array{wordpress_core:string, managed_components:list<array{name:string, version:string, kind:string}>}
     */
    private static function normalizeBaseline(array $value): array
    {
        $components = [];

        foreach ((array) ($value['managed_components'] ?? []) as $component) {
            if (! is_array($component)) {
                throw new RuntimeException('baseline.managed_components entries must be arrays.');
            }

            $components[] = [
                'name' => self::string($component['name'] ?? '', 'baseline.managed_components.name'),
                'version' => self::string($component['version'] ?? '', 'baseline.managed_components.version'),
                'kind' => self::string($component['kind'] ?? '', 'baseline.managed_components.kind'),
            ];
        }

        return [
            'wordpress_core' => self::string($value['wordpress_core'] ?? '', 'baseline.wordpress_core'),
            'managed_components' => $components,
        ];
    }

    /**
     * @param array<string, mixed> $value
     * @return array{managed_files:array<string, string>}
     */
    private static function normalizeScaffold(array $value): array
    {
        $managedFiles = [];

        foreach ((array) ($value['managed_files'] ?? []) as $path => $checksum) {
            if (! is_string($path) || $path === '') {
                throw new RuntimeException('scaffold.managed_files keys must be non-empty strings.');
            }

            $managedFiles[self::relativePath($path, 'scaffold.managed_files')] = self::string(
                $checksum,
                sprintf('scaffold.managed_files[%s]', $path)
            );
        }

        ksort($managedFiles);

        return ['managed_files' => $managedFiles];
    }

    private static function normalizeVersion(string $version): string
    {
        $normalized = ltrim(trim($version), 'v');

        if ($normalized === '' || preg_match('/^\d+\.\d+\.\d+$/', $normalized) !== 1) {
            throw new RuntimeException(sprintf('Framework version must be SemVer (x.y.z). Received: %s', $version));
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $value
     * @param array{provider:string, reference:string, api_base:string} $releaseSource
     */
    private static function legacyRepositoryValue(array $value, array $releaseSource): string
    {
        $repository = $value['repository'] ?? null;

        if (is_string($repository) && trim($repository) !== '') {
            return trim($repository);
        }

        return $releaseSource['provider'] === 'github-release' ? $releaseSource['reference'] : '';
    }

    private function legacyRepositoryForSerialization(): string
    {
        if ($this->releaseSourceProvider() !== 'github-release') {
            return $this->repository;
        }

        return $this->releaseSourceReference();
    }

    private function releaseSourceWebBase(): string
    {
        $apiBase = rtrim($this->releaseSourceApiBase(), '/');

        if ($this->releaseSourceProvider() === 'gitlab-release') {
            if (str_ends_with($apiBase, '/api/v4')) {
                return substr($apiBase, 0, -strlen('/api/v4'));
            }

            return $apiBase;
        }

        if ($apiBase === 'https://api.github.com') {
            return 'https://github.com';
        }

        if (str_ends_with($apiBase, '/api/v3')) {
            return substr($apiBase, 0, -strlen('/api/v3'));
        }

        return $apiBase;
    }

    /**
     * @return list<string>
     */
    private static function referenceSegments(string $reference): array
    {
        $segments = array_values(array_filter(
            explode('/', trim($reference, '/')),
            static fn (string $segment): bool => $segment !== ''
        ));

        if ($segments === []) {
            throw new RuntimeException('release_source.reference must contain at least one non-empty path segment.');
        }

        return $segments;
    }

    private static function string(mixed $value, string $field): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException(sprintf('%s must be a non-empty string.', $field));
        }

        return trim($value);
    }

    private static function relativePath(mixed $value, string $field): string
    {
        if (! is_string($value)) {
            throw new RuntimeException(sprintf('%s must be a string.', $field));
        }

        $normalized = trim(str_replace('\\', '/', $value));

        if ($normalized === '.') {
            return '.';
        }

        $normalized = trim($normalized, '/');

        if ($normalized === '' || str_starts_with($normalized, '..') || str_contains($normalized, '/../')) {
            throw new RuntimeException(sprintf('%s must be a safe relative path.', $field));
        }

        return $normalized;
    }
}
