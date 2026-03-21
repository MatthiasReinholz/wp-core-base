<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class Config
{
    /**
     * @param list<array<string, mixed>> $dependencies
     * @param array{content_root:string, plugins_root:string, themes_root:string, mu_plugins_root:string} $paths
     * @param array{mode:string, enabled:bool} $core
     * @param array{stage_dir:string, forbidden_paths:list<string>, forbidden_files:list<string>, allow_runtime_paths:list<string>} $runtime
     * @param array{api_base:string} $github
     * @param array{base_branch:?string, dry_run:bool} $automation
     */
    public function __construct(
        public readonly string $repoRoot,
        public readonly string $manifestPath,
        public readonly string $profile,
        public readonly array $paths,
        public readonly array $core,
        public readonly array $runtime,
        public readonly array $github,
        public readonly array $automation,
        public readonly array $dependencies,
    ) {
    }

    public static function load(string $repoRoot, ?string $manifestPath = null): self
    {
        $resolvedManifest = $manifestPath ?? $repoRoot . '/.wp-core-base/manifest.php';
        $legacyConfig = $repoRoot . '/.github/wporg-updates.php';

        if (! is_file($resolvedManifest)) {
            if (is_file($legacyConfig)) {
                throw new RuntimeException(
                    'Legacy config detected at .github/wporg-updates.php, but the framework now requires .wp-core-base/manifest.php. ' .
                    'Migrate to the new manifest format and remove the legacy file.'
                );
            }

            throw new RuntimeException(sprintf('Manifest file not found: %s', $resolvedManifest));
        }

        $data = require $resolvedManifest;

        if (! is_array($data)) {
            throw new RuntimeException('Manifest file must return an array.');
        }

        $profile = self::string($data['profile'] ?? 'full-core', 'profile');

        if (! in_array($profile, ['full-core', 'content-only'], true)) {
            throw new RuntimeException('Manifest profile must be either "full-core" or "content-only".');
        }

        $defaultPaths = self::defaultPaths($profile);
        $paths = self::normalizePaths($data['paths'] ?? [], $defaultPaths);
        $core = self::normalizeCore($data['core'] ?? [], $profile);
        $runtime = self::normalizeRuntime($data['runtime'] ?? [], $profile);
        $github = self::normalizeGithub($data['github'] ?? []);
        $automation = self::normalizeAutomation($data['automation'] ?? []);
        $dependencies = self::normalizeDependencies($data['dependencies'] ?? [], $paths);

        return new self(
            repoRoot: $repoRoot,
            manifestPath: $resolvedManifest,
            profile: $profile,
            paths: $paths,
            core: $core,
            runtime: $runtime,
            github: $github,
            automation: $automation,
            dependencies: $dependencies,
        );
    }

    public function baseBranch(): ?string
    {
        return $this->automation['base_branch'];
    }

    public function dryRun(): bool
    {
        return $this->automation['dry_run'];
    }

    public function githubApiBase(): string
    {
        return $this->github['api_base'];
    }

    public function coreEnabled(): bool
    {
        return $this->core['enabled'];
    }

    public function coreManaged(): bool
    {
        return $this->core['mode'] === 'managed' && $this->core['enabled'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function dependencies(): array
    {
        return $this->dependencies;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function managedDependencies(): array
    {
        return array_values(array_filter($this->dependencies, static function (array $dependency): bool {
            return $dependency['management'] === 'managed';
        }));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function runtimeDependencies(): array
    {
        return array_values(array_filter($this->dependencies, static function (array $dependency): bool {
            return in_array($dependency['management'], ['managed', 'local'], true);
        }));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function ignoredDependencies(): array
    {
        return array_values(array_filter($this->dependencies, static function (array $dependency): bool {
            return $dependency['management'] === 'ignored';
        }));
    }

    public function stageDir(string $outputOverride = ''): string
    {
        if ($outputOverride !== '') {
            return $this->repoRoot . '/' . ltrim($outputOverride, '/');
        }

        return $this->repoRoot . '/' . ltrim($this->runtime['stage_dir'], '/');
    }

    public function rootForKind(string $kind): string
    {
        return match ($kind) {
            'plugin' => $this->paths['plugins_root'],
            'theme' => $this->paths['themes_root'],
            'mu-plugin-package' => $this->paths['mu_plugins_root'],
            default => throw new RuntimeException(sprintf('Unknown dependency kind: %s', $kind)),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function dependencyByKey(string $key): array
    {
        foreach ($this->dependencies as $dependency) {
            if ($dependency['component_key'] === $key) {
                return $dependency;
            }
        }

        throw new RuntimeException(sprintf('Dependency not found for key %s.', $key));
    }

    /**
     * @param list<array<string, mixed>> $dependencies
     */
    public function withDependencies(array $dependencies): self
    {
        return new self(
            repoRoot: $this->repoRoot,
            manifestPath: $this->manifestPath,
            profile: $this->profile,
            paths: $this->paths,
            core: $this->core,
            runtime: $this->runtime,
            github: $this->github,
            automation: $this->automation,
            dependencies: $dependencies,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'profile' => $this->profile,
            'paths' => $this->paths,
            'core' => $this->core,
            'runtime' => $this->runtime,
            'github' => $this->github,
            'automation' => $this->automation,
            'dependencies' => array_map(static function (array $dependency): array {
                return [
                    'name' => $dependency['name'],
                    'slug' => $dependency['slug'],
                    'kind' => $dependency['kind'],
                    'management' => $dependency['management'],
                    'source' => $dependency['source'],
                    'path' => $dependency['path'],
                    'main_file' => $dependency['main_file'],
                    'version' => $dependency['version'],
                    'checksum' => $dependency['checksum'],
                    'archive_subdir' => $dependency['archive_subdir'],
                    'extra_labels' => $dependency['extra_labels'],
                    'source_config' => $dependency['source_config'],
                    'policy' => $dependency['policy'],
                ];
            }, $this->dependencies),
        ];
    }

    /**
     * @return array{content_root:string, plugins_root:string, themes_root:string, mu_plugins_root:string}
     */
    private static function defaultPaths(string $profile): array
    {
        if ($profile === 'content-only') {
            return [
                'content_root' => 'cms',
                'plugins_root' => 'cms/plugins',
                'themes_root' => 'cms/themes',
                'mu_plugins_root' => 'cms/mu-plugins',
            ];
        }

        return [
            'content_root' => 'wp-content',
            'plugins_root' => 'wp-content/plugins',
            'themes_root' => 'wp-content/themes',
            'mu_plugins_root' => 'wp-content/mu-plugins',
        ];
    }

    /**
     * @param array<string, mixed> $value
     * @param array{content_root:string, plugins_root:string, themes_root:string, mu_plugins_root:string} $defaults
     * @return array{content_root:string, plugins_root:string, themes_root:string, mu_plugins_root:string}
     */
    private static function normalizePaths(array $value, array $defaults): array
    {
        return [
            'content_root' => self::normalizedRelativePath($value['content_root'] ?? $defaults['content_root'], 'paths.content_root'),
            'plugins_root' => self::normalizedRelativePath($value['plugins_root'] ?? $defaults['plugins_root'], 'paths.plugins_root'),
            'themes_root' => self::normalizedRelativePath($value['themes_root'] ?? $defaults['themes_root'], 'paths.themes_root'),
            'mu_plugins_root' => self::normalizedRelativePath($value['mu_plugins_root'] ?? $defaults['mu_plugins_root'], 'paths.mu_plugins_root'),
        ];
    }

    /**
     * @param array<string, mixed> $value
     * @return array{mode:string, enabled:bool}
     */
    private static function normalizeCore(array $value, string $profile): array
    {
        $defaultMode = $profile === 'content-only' ? 'external' : 'managed';
        $mode = self::string($value['mode'] ?? $defaultMode, 'core.mode');

        if (! in_array($mode, ['managed', 'external'], true)) {
            throw new RuntimeException('core.mode must be either "managed" or "external".');
        }

        $enabled = (bool) ($value['enabled'] ?? ($mode === 'managed'));

        return [
            'mode' => $mode,
            'enabled' => $enabled,
        ];
    }

    /**
     * @param array<string, mixed> $value
     * @return array{stage_dir:string, forbidden_paths:list<string>, forbidden_files:list<string>, allow_runtime_paths:list<string>}
     */
    private static function normalizeRuntime(array $value, string $profile): array
    {
        $defaultStageDir = '.wp-core-base/build/runtime';

        return [
            'stage_dir' => self::normalizedRelativePath($value['stage_dir'] ?? $defaultStageDir, 'runtime.stage_dir'),
            'forbidden_paths' => self::stringList(
                $value['forbidden_paths'] ?? [
                    '.git',
                    '.github',
                    '.gitlab',
                    '.circleci',
                    '.wordpress-org',
                    'node_modules',
                    'docs',
                    'doc',
                    'tests',
                    'test',
                    '__tests__',
                    'examples',
                    'example',
                    'demo',
                    'screenshots',
                ],
                'runtime.forbidden_paths'
            ),
            'forbidden_files' => self::stringList(
                $value['forbidden_files'] ?? [
                    'README*',
                    'CHANGELOG*',
                    '.gitignore',
                    '.gitattributes',
                    'phpunit.xml*',
                    'composer.json',
                    'composer.lock',
                    'package.json',
                    'package-lock.json',
                    'pnpm-lock.yaml',
                    'yarn.lock',
                ],
                'runtime.forbidden_files'
            ),
            'allow_runtime_paths' => self::normalizedPathList($value['allow_runtime_paths'] ?? [], 'runtime.allow_runtime_paths'),
        ];
    }

    /**
     * @param array<string, mixed> $value
     * @return array{api_base:string}
     */
    private static function normalizeGithub(array $value): array
    {
        return [
            'api_base' => self::string($value['api_base'] ?? (getenv('GITHUB_API_URL') ?: 'https://api.github.com'), 'github.api_base'),
        ];
    }

    /**
     * @param array<string, mixed> $value
     * @return array{base_branch:?string, dry_run:bool}
     */
    private static function normalizeAutomation(array $value): array
    {
        return [
            'base_branch' => self::nullableString($value['base_branch'] ?? null),
            'dry_run' => (bool) ($value['dry_run'] ?? (bool) getenv('WPORG_UPDATE_DRY_RUN')),
        ];
    }

    /**
     * @param mixed $value
     * @param array{content_root:string, plugins_root:string, themes_root:string, mu_plugins_root:string} $paths
     * @return list<array<string, mixed>>
     */
    private static function normalizeDependencies(mixed $value, array $paths): array
    {
        if (! is_array($value)) {
            throw new RuntimeException('Manifest dependencies must be an array.');
        }

        $dependencies = [];

        foreach ($value as $index => $dependency) {
            if (! is_array($dependency)) {
                throw new RuntimeException(sprintf('Dependency entry %d must be an array.', (int) $index));
            }

            $slug = self::string($dependency['slug'] ?? null, sprintf('dependencies[%d].slug', (int) $index));
            $kind = self::string($dependency['kind'] ?? null, sprintf('dependencies[%d].kind', (int) $index));
            $management = self::string($dependency['management'] ?? null, sprintf('dependencies[%d].management', (int) $index));
            $source = self::string($dependency['source'] ?? null, sprintf('dependencies[%d].source', (int) $index));

            if (! in_array($kind, ['plugin', 'theme', 'mu-plugin-package'], true)) {
                throw new RuntimeException(sprintf('Dependency %s has invalid kind %s.', $slug, $kind));
            }

            if (! in_array($management, ['managed', 'local', 'ignored'], true)) {
                throw new RuntimeException(sprintf('Dependency %s has invalid management %s.', $slug, $management));
            }

            if (! in_array($source, ['wordpress.org', 'github-release', 'local'], true)) {
                throw new RuntimeException(sprintf('Dependency %s has invalid source %s.', $slug, $source));
            }

            $path = self::normalizedRelativePath($dependency['path'] ?? null, sprintf('dependencies[%s].path', $slug));
            $mainFile = self::normalizedRelativePath($dependency['main_file'] ?? null, sprintf('dependencies[%s].main_file', $slug));

            $name = self::string($dependency['name'] ?? $slug, sprintf('dependencies[%s].name', $slug));
            $version = self::nullableString($dependency['version'] ?? null);
            $checksum = self::nullableString($dependency['checksum'] ?? null);
            $archiveSubdir = self::nullableString($dependency['archive_subdir'] ?? '') ?? '';
            $extraLabels = self::stringList($dependency['extra_labels'] ?? [], sprintf('dependencies[%s].extra_labels', $slug));

            $sourceConfig = is_array($dependency['source_config'] ?? null) ? $dependency['source_config'] : [];
            $policy = is_array($dependency['policy'] ?? null) ? $dependency['policy'] : [];

            $policyClass = self::string(
                $policy['class'] ?? self::defaultPolicyClass($management, $source),
                sprintf('dependencies[%s].policy.class', $slug)
            );

            if ($policyClass !== self::defaultPolicyClass($management, $source)) {
                throw new RuntimeException(sprintf(
                    'Dependency %s policy.class must be %s for %s/%s.',
                    $slug,
                    self::defaultPolicyClass($management, $source),
                    $management,
                    $source
                ));
            }

            if ($management === 'managed' && ($version === null || $checksum === null)) {
                throw new RuntimeException(sprintf('Managed dependency %s must define version and checksum.', $slug));
            }

            if ($management !== 'managed' && $source !== 'local' && $management !== 'ignored') {
                throw new RuntimeException(sprintf('Only managed dependencies may use remote source %s (%s).', $source, $slug));
            }

            if ($management === 'ignored' && $source !== 'local') {
                throw new RuntimeException(sprintf('Ignored dependency %s must use source "local".', $slug));
            }

            $githubRepository = self::nullableString($sourceConfig['github_repository'] ?? null);
            $githubReleaseAssetPattern = self::nullableString($sourceConfig['github_release_asset_pattern'] ?? null);
            $githubTokenEnv = self::nullableString($sourceConfig['github_token_env'] ?? null);

            if ($source === 'github-release' && $githubRepository === null) {
                throw new RuntimeException(sprintf('GitHub release dependency %s must define source_config.github_repository.', $slug));
            }

            $expectedPrefix = self::normalizedRelativePath($paths[match ($kind) {
                'plugin' => 'plugins_root',
                'theme' => 'themes_root',
                'mu-plugin-package' => 'mu_plugins_root',
            }], 'internal.expected_prefix');

            if (! self::pathStartsWith($path, $expectedPrefix)) {
                throw new RuntimeException(sprintf(
                    'Dependency %s path %s must live under %s.',
                    $slug,
                    $path,
                    $expectedPrefix
                ));
            }

            $dependencies[] = [
                'name' => $name,
                'slug' => $slug,
                'kind' => $kind,
                'management' => $management,
                'source' => $source,
                'path' => $path,
                'main_file' => $mainFile,
                'version' => $version,
                'checksum' => $checksum,
                'archive_subdir' => trim($archiveSubdir, '/'),
                'extra_labels' => $extraLabels,
                'source_config' => [
                    'github_repository' => $githubRepository,
                    'github_release_asset_pattern' => $githubReleaseAssetPattern,
                    'github_token_env' => $githubTokenEnv,
                ],
                'policy' => [
                    'class' => $policyClass,
                    'allow_runtime_paths' => self::normalizedPathList(
                        $policy['allow_runtime_paths'] ?? [],
                        sprintf('dependencies[%s].policy.allow_runtime_paths', $slug)
                    ),
                ],
                'component_key' => sprintf('%s:%s:%s', $kind, $source, $slug),
            ];
        }

        return $dependencies;
    }

    private static function defaultPolicyClass(string $management, string $source): string
    {
        return match (true) {
            $management === 'managed' && $source === 'wordpress.org' => 'managed-upstream',
            $management === 'managed' && $source === 'github-release' => 'managed-private',
            $management === 'local' && $source === 'local' => 'local-owned',
            $management === 'ignored' && $source === 'local' => 'ignored',
            default => throw new RuntimeException(sprintf('Invalid management/source combination: %s/%s', $management, $source)),
        };
    }

    private static function string(mixed $value, string $key): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException(sprintf('Config value "%s" must be a non-empty string.', $key));
        }

        return trim($value);
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new RuntimeException('Nullable string config value must be null or a string.');
        }

        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private static function stringList(mixed $value, string $key): array
    {
        if (! is_array($value)) {
            throw new RuntimeException(sprintf('Config value "%s" must be an array of strings.', $key));
        }

        return array_values(array_map(static function (mixed $item) use ($key): string {
            if (! is_string($item) || trim($item) === '') {
                throw new RuntimeException(sprintf('Config value "%s" must contain only non-empty strings.', $key));
            }

            return trim($item);
        }, $value));
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private static function normalizedPathList(mixed $value, string $key): array
    {
        return array_map(static fn (string $path): string => self::normalizedRelativePath($path, $key), self::stringList($value, $key));
    }

    private static function normalizedRelativePath(mixed $value, string $key): string
    {
        $path = self::string($value, $key);
        $normalized = str_replace('\\', '/', $path);
        $normalized = trim($normalized, '/');

        if ($normalized === '' || str_contains($normalized, '../') || str_starts_with($normalized, '..')) {
            throw new RuntimeException(sprintf('Config value "%s" must be a safe relative path.', $key));
        }

        return $normalized;
    }

    private static function pathStartsWith(string $path, string $prefix): bool
    {
        return $path === $prefix || str_starts_with($path, $prefix . '/');
    }
}
