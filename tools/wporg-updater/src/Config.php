<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class Config
{
    private const MANAGED_KINDS = ['plugin', 'theme', 'mu-plugin-package'];
    private const RUNTIME_KINDS = ['plugin', 'theme', 'mu-plugin-package', 'mu-plugin-file', 'runtime-file', 'runtime-directory'];
    private const ALL_KINDS = ['plugin', 'theme', 'mu-plugin-package', 'mu-plugin-file', 'runtime-file', 'runtime-directory'];

    /**
     * @param list<array<string, mixed>> $dependencies
     * @param array{content_root:string, plugins_root:string, themes_root:string, mu_plugins_root:string} $paths
     * @param array{mode:string, enabled:bool} $core
     * @param array{stage_dir:string, manifest_mode:string, validation_mode:string, ownership_roots:list<string>, staged_kinds:list<string>, validated_kinds:list<string>, forbidden_paths:list<string>, forbidden_files:list<string>, allow_runtime_paths:list<string>, strip_paths:list<string>, strip_files:list<string>, managed_sanitize_paths:list<string>, managed_sanitize_files:list<string>} $runtime
     * @param array{api_base:string} $github
     * @param array{base_branch:?string, dry_run:bool, managed_kinds:list<string>} $automation
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

        return self::fromArray($repoRoot, $data, $resolvedManifest);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(string $repoRoot, array $data, ?string $manifestPath = null): self
    {
        $resolvedManifest = $manifestPath ?? $repoRoot . '/.wp-core-base/manifest.php';

        $profile = self::string($data['profile'] ?? 'full-core', 'profile');

        if (! in_array($profile, ['full-core', 'content-only'], true)) {
            throw new RuntimeException('Manifest profile must be either "full-core" or "content-only".');
        }

        $defaultPaths = self::defaultPaths($profile);
        $paths = self::normalizePaths($data['paths'] ?? [], $defaultPaths);
        $core = self::normalizeCore($data['core'] ?? [], $profile);
        $runtime = self::normalizeRuntime($data['runtime'] ?? [], $paths);
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

    public static function runtimeKinds(): array
    {
        return self::RUNTIME_KINDS;
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

    public function manifestMode(): string
    {
        return $this->runtime['manifest_mode'];
    }

    public function isStrictManifestMode(): bool
    {
        return $this->manifestMode() === 'strict';
    }

    public function isRelaxedManifestMode(): bool
    {
        return $this->manifestMode() === 'relaxed';
    }

    public function validationMode(): string
    {
        return $this->runtime['validation_mode'];
    }

    public function isSourceCleanValidationMode(): bool
    {
        return $this->validationMode() === 'source-clean';
    }

    public function isStagedCleanValidationMode(): bool
    {
        return $this->validationMode() === 'staged-clean';
    }

    public function ownershipRoots(): array
    {
        return $this->runtime['ownership_roots'];
    }

    public function managedKinds(): array
    {
        return $this->automation['managed_kinds'];
    }

    public function stagedKinds(): array
    {
        return $this->runtime['staged_kinds'];
    }

    public function validatedKinds(): array
    {
        return $this->runtime['validated_kinds'];
    }

    public function stripPaths(): array
    {
        return $this->runtime['strip_paths'];
    }

    public function stripFiles(): array
    {
        return $this->runtime['strip_files'];
    }

    public function managedSanitizePaths(): array
    {
        return $this->runtime['managed_sanitize_paths'];
    }

    public function managedSanitizeFiles(): array
    {
        return $this->runtime['managed_sanitize_files'];
    }

    public function isKindManagedEnabled(string $kind): bool
    {
        return in_array($kind, $this->automation['managed_kinds'], true);
    }

    public function isKindStaged(string $kind): bool
    {
        return in_array($kind, $this->runtime['staged_kinds'], true);
    }

    public function isKindValidated(string $kind): bool
    {
        return in_array($kind, $this->runtime['validated_kinds'], true);
    }

    public function isFileKind(string $kind): bool
    {
        return in_array($kind, ['mu-plugin-file', 'runtime-file'], true);
    }

    public function isDirectoryKind(string $kind): bool
    {
        return ! $this->isFileKind($kind);
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
        return array_values(array_filter($this->dependencies, fn (array $dependency): bool => $this->shouldManageDependency($dependency)));
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
    public function stagedDependencies(): array
    {
        return array_values(array_filter($this->runtimeDependencies(), fn (array $dependency): bool => $this->shouldStageDependency($dependency)));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function validatedDependencies(): array
    {
        return array_values(array_filter($this->runtimeDependencies(), fn (array $dependency): bool => $this->shouldValidateDependency($dependency)));
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

    public function shouldManageDependency(array $dependency): bool
    {
        return $dependency['management'] === 'managed' && $this->isKindManagedEnabled((string) $dependency['kind']);
    }

    public function shouldStageDependency(array $dependency): bool
    {
        return in_array($dependency['management'], ['managed', 'local'], true)
            && $this->isKindStaged((string) $dependency['kind']);
    }

    public function shouldValidateDependency(array $dependency): bool
    {
        return in_array($dependency['management'], ['managed', 'local'], true)
            && $this->isKindValidated((string) $dependency['kind']);
    }

    public function dependencyStripPaths(array $dependency): array
    {
        return (array) ($dependency['policy']['strip_paths'] ?? []);
    }

    public function dependencyStripFiles(array $dependency): array
    {
        return (array) ($dependency['policy']['strip_files'] ?? []);
    }

    public function dependencySanitizePaths(array $dependency): array
    {
        return (array) ($dependency['policy']['sanitize_paths'] ?? []);
    }

    public function dependencySanitizeFiles(array $dependency): array
    {
        return (array) ($dependency['policy']['sanitize_files'] ?? []);
    }

    public function shouldAllowStripOnStage(array $dependency): bool
    {
        return $dependency['management'] === 'local'
            && ($this->dependencyStripPaths($dependency) !== [] || $this->dependencyStripFiles($dependency) !== []);
    }

    /**
     * @return array{0:list<string>,1:list<string>}
     */
    public function managedSanitizeRules(array $dependency): array
    {
        if ($dependency['management'] !== 'managed') {
            return [[], []];
        }

        return [
            array_values(array_unique(array_merge($this->managedSanitizePaths(), $this->dependencySanitizePaths($dependency)))),
            array_values(array_unique(array_merge($this->managedSanitizeFiles(), $this->dependencySanitizeFiles($dependency)))),
        ];
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
            'mu-plugin-package', 'mu-plugin-file' => $this->paths['mu_plugins_root'],
            'runtime-file', 'runtime-directory' => $this->paths['content_root'],
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
        $paths = [
            'content_root' => self::normalizedRelativePath($value['content_root'] ?? $defaults['content_root'], 'paths.content_root'),
            'plugins_root' => self::normalizedRelativePath($value['plugins_root'] ?? $defaults['plugins_root'], 'paths.plugins_root'),
            'themes_root' => self::normalizedRelativePath($value['themes_root'] ?? $defaults['themes_root'], 'paths.themes_root'),
            'mu_plugins_root' => self::normalizedRelativePath($value['mu_plugins_root'] ?? $defaults['mu_plugins_root'], 'paths.mu_plugins_root'),
        ];

        foreach (['plugins_root', 'themes_root', 'mu_plugins_root'] as $key) {
            if (! self::pathStartsWith($paths[$key], $paths['content_root'])) {
                throw new RuntimeException(sprintf('%s must live under paths.content_root.', $key));
            }
        }

        return $paths;
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
     * @param array{content_root:string, plugins_root:string, themes_root:string, mu_plugins_root:string} $paths
     * @return array{stage_dir:string, manifest_mode:string, validation_mode:string, ownership_roots:list<string>, staged_kinds:list<string>, validated_kinds:list<string>, forbidden_paths:list<string>, forbidden_files:list<string>, allow_runtime_paths:list<string>, strip_paths:list<string>, strip_files:list<string>, managed_sanitize_paths:list<string>, managed_sanitize_files:list<string>}
     */
    private static function normalizeRuntime(array $value, array $paths): array
    {
        $ownershipRoots = self::normalizedPathList(
            $value['ownership_roots'] ?? [
                $paths['plugins_root'],
                $paths['themes_root'],
                $paths['mu_plugins_root'],
            ],
            'runtime.ownership_roots'
        );

        foreach ($ownershipRoots as $ownershipRoot) {
            if (! self::pathStartsWith($ownershipRoot, $paths['content_root'])) {
                throw new RuntimeException(sprintf('runtime.ownership_roots entry %s must live under paths.content_root.', $ownershipRoot));
            }
        }

        return [
            'stage_dir' => self::normalizedRelativePath($value['stage_dir'] ?? '.wp-core-base/build/runtime', 'runtime.stage_dir'),
            'manifest_mode' => self::enumValue($value['manifest_mode'] ?? 'strict', 'runtime.manifest_mode', ['strict', 'relaxed']),
            'validation_mode' => self::enumValue($value['validation_mode'] ?? 'source-clean', 'runtime.validation_mode', ['source-clean', 'staged-clean']),
            'ownership_roots' => array_values(array_unique($ownershipRoots)),
            'staged_kinds' => self::kindList($value['staged_kinds'] ?? self::RUNTIME_KINDS, 'runtime.staged_kinds'),
            'validated_kinds' => self::kindList($value['validated_kinds'] ?? self::RUNTIME_KINDS, 'runtime.validated_kinds'),
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
            'strip_paths' => self::normalizedPathList($value['strip_paths'] ?? [], 'runtime.strip_paths'),
            'strip_files' => self::stringList($value['strip_files'] ?? [], 'runtime.strip_files'),
            'managed_sanitize_paths' => self::normalizedPathList(
                $value['managed_sanitize_paths'] ?? [
                    $paths['plugins_root'] . '/.github',
                    $paths['plugins_root'] . '/.gitlab',
                    $paths['plugins_root'] . '/.circleci',
                    $paths['plugins_root'] . '/.wordpress-org',
                    $paths['plugins_root'] . '/node_modules',
                    $paths['plugins_root'] . '/docs',
                    $paths['plugins_root'] . '/doc',
                    $paths['plugins_root'] . '/tests',
                    $paths['plugins_root'] . '/test',
                    $paths['plugins_root'] . '/__tests__',
                    $paths['plugins_root'] . '/examples',
                    $paths['plugins_root'] . '/example',
                    $paths['plugins_root'] . '/demo',
                    $paths['plugins_root'] . '/screenshots',
                    $paths['themes_root'] . '/.github',
                    $paths['themes_root'] . '/.gitlab',
                    $paths['themes_root'] . '/.circleci',
                    $paths['themes_root'] . '/.wordpress-org',
                    $paths['themes_root'] . '/node_modules',
                    $paths['themes_root'] . '/docs',
                    $paths['themes_root'] . '/doc',
                    $paths['themes_root'] . '/tests',
                    $paths['themes_root'] . '/test',
                    $paths['themes_root'] . '/__tests__',
                    $paths['themes_root'] . '/examples',
                    $paths['themes_root'] . '/example',
                    $paths['themes_root'] . '/demo',
                    $paths['themes_root'] . '/screenshots',
                    $paths['mu_plugins_root'] . '/.github',
                    $paths['mu_plugins_root'] . '/.gitlab',
                    $paths['mu_plugins_root'] . '/.circleci',
                    $paths['mu_plugins_root'] . '/.wordpress-org',
                    $paths['mu_plugins_root'] . '/node_modules',
                    $paths['mu_plugins_root'] . '/docs',
                    $paths['mu_plugins_root'] . '/doc',
                    $paths['mu_plugins_root'] . '/tests',
                    $paths['mu_plugins_root'] . '/test',
                    $paths['mu_plugins_root'] . '/__tests__',
                    $paths['mu_plugins_root'] . '/examples',
                    $paths['mu_plugins_root'] . '/example',
                    $paths['mu_plugins_root'] . '/demo',
                    $paths['mu_plugins_root'] . '/screenshots',
                ],
                'runtime.managed_sanitize_paths'
            ),
            'managed_sanitize_files' => self::stringList(
                $value['managed_sanitize_files'] ?? [
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
                'runtime.managed_sanitize_files'
            ),
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
     * @return array{base_branch:?string, dry_run:bool, managed_kinds:list<string>}
     */
    private static function normalizeAutomation(array $value): array
    {
        return [
            'base_branch' => self::nullableString($value['base_branch'] ?? null),
            'dry_run' => (bool) ($value['dry_run'] ?? (bool) getenv('WPORG_UPDATE_DRY_RUN')),
            'managed_kinds' => self::kindList($value['managed_kinds'] ?? self::MANAGED_KINDS, 'automation.managed_kinds'),
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
            $kind = self::enumValue($dependency['kind'] ?? null, sprintf('dependencies[%d].kind', (int) $index), self::ALL_KINDS);
            $management = self::enumValue($dependency['management'] ?? null, sprintf('dependencies[%d].management', (int) $index), ['managed', 'local', 'ignored']);
            $source = self::enumValue(
                $dependency['source'] ?? null,
                sprintf('dependencies[%d].source', (int) $index),
                ['wordpress.org', 'github-release', 'acf-pro', 'role-editor-pro', 'freemius-premium', 'local']
            );
            $path = self::normalizedRelativePath($dependency['path'] ?? null, sprintf('dependencies[%s].path', $slug));
            $mainFile = self::nullableNormalizedRelativePath($dependency['main_file'] ?? null, sprintf('dependencies[%s].main_file', $slug));
            $name = self::string($dependency['name'] ?? $slug, sprintf('dependencies[%s].name', $slug));
            $version = self::nullableString($dependency['version'] ?? null);
            $checksum = self::nullableString($dependency['checksum'] ?? null);
            $archiveSubdir = self::nullableString($dependency['archive_subdir'] ?? '') ?? '';
            $extraLabels = LabelHelper::normalizeList(
                self::stringList($dependency['extra_labels'] ?? [], sprintf('dependencies[%s].extra_labels', $slug))
            );
            $sourceConfig = is_array($dependency['source_config'] ?? null) ? $dependency['source_config'] : [];
            $policy = is_array($dependency['policy'] ?? null) ? $dependency['policy'] : [];

            if (in_array($kind, ['plugin', 'theme', 'mu-plugin-package'], true) && $mainFile === null) {
                throw new RuntimeException(sprintf('Dependency %s must define main_file for kind %s.', $slug, $kind));
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

            if ($source === 'wordpress.org' && ! in_array($kind, ['plugin', 'theme'], true)) {
                throw new RuntimeException(sprintf('WordPress.org source is only supported for plugin and theme dependencies (%s).', $slug));
            }

            if (in_array($source, ['acf-pro', 'role-editor-pro', 'freemius-premium'], true) && $kind !== 'plugin') {
                throw new RuntimeException(sprintf('Premium source %s is currently supported only for plugin dependencies (%s).', $source, $slug));
            }

            if ($kind === 'runtime-directory' && $management === 'managed') {
                throw new RuntimeException(sprintf('runtime-directory entries may not be updater-managed today (%s).', $slug));
            }

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

            $stripPaths = self::normalizedPathList(
                $policy['strip_paths'] ?? [],
                sprintf('dependencies[%s].policy.strip_paths', $slug)
            );
            $stripFiles = self::stringList(
                $policy['strip_files'] ?? [],
                sprintf('dependencies[%s].policy.strip_files', $slug)
            );
            $sanitizePaths = self::normalizedPathList(
                $policy['sanitize_paths'] ?? [],
                sprintf('dependencies[%s].policy.sanitize_paths', $slug)
            );
            $sanitizeFiles = self::stringList(
                $policy['sanitize_files'] ?? [],
                sprintf('dependencies[%s].policy.sanitize_files', $slug)
            );

            if (($stripPaths !== [] || $stripFiles !== []) && $management !== 'local') {
                throw new RuntimeException(sprintf('Strip-on-stage rules are only supported for local dependencies (%s).', $slug));
            }

            if (($sanitizePaths !== [] || $sanitizeFiles !== []) && $management !== 'managed') {
                throw new RuntimeException(sprintf('Sanitize-on-sync rules are only supported for managed dependencies (%s).', $slug));
            }

            $githubRepository = self::nullableString($sourceConfig['github_repository'] ?? null);
            $githubReleaseAssetPattern = self::nullableString($sourceConfig['github_release_asset_pattern'] ?? null);
            $githubTokenEnv = self::nullableString($sourceConfig['github_token_env'] ?? null);
            $credentialKey = self::nullableString($sourceConfig['credential_key'] ?? null);
            $providerProductId = isset($sourceConfig['provider_product_id']) && $sourceConfig['provider_product_id'] !== ''
                ? (int) $sourceConfig['provider_product_id']
                : null;

            if ($source === 'github-release' && $githubRepository === null) {
                throw new RuntimeException(sprintf('GitHub release dependency %s must define source_config.github_repository.', $slug));
            }

            if ($source === 'freemius-premium' && $providerProductId !== null && $providerProductId <= 0) {
                throw new RuntimeException(sprintf('Freemius premium dependency %s must use a positive source_config.provider_product_id.', $slug));
            }

            $expectedPrefix = self::rootForKindFromPaths($kind, $paths);

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
                    'credential_key' => $credentialKey,
                    'provider_product_id' => $providerProductId,
                ],
                'policy' => [
                    'class' => $policyClass,
                    'allow_runtime_paths' => self::normalizedPathList(
                        $policy['allow_runtime_paths'] ?? [],
                        sprintf('dependencies[%s].policy.allow_runtime_paths', $slug)
                    ),
                    'strip_paths' => $stripPaths,
                    'strip_files' => $stripFiles,
                    'sanitize_paths' => $sanitizePaths,
                    'sanitize_files' => $sanitizeFiles,
                ],
                'component_key' => sprintf('%s:%s:%s', $kind, $source, $slug),
            ];
        }

        return $dependencies;
    }

    private static function rootForKindFromPaths(string $kind, array $paths): string
    {
        return match ($kind) {
            'plugin' => $paths['plugins_root'],
            'theme' => $paths['themes_root'],
            'mu-plugin-package', 'mu-plugin-file' => $paths['mu_plugins_root'],
            'runtime-file', 'runtime-directory' => $paths['content_root'],
            default => throw new RuntimeException(sprintf('Unsupported dependency kind %s.', $kind)),
        };
    }

    private static function defaultPolicyClass(string $management, string $source): string
    {
        return match (true) {
            $management === 'managed' && $source === 'wordpress.org' => 'managed-upstream',
            $management === 'managed' && $source === 'github-release' => 'managed-private',
            $management === 'managed' && in_array($source, ['acf-pro', 'role-editor-pro', 'freemius-premium'], true) => 'managed-premium',
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

    private static function enumValue(mixed $value, string $key, array $allowed): string
    {
        $normalized = self::string($value, $key);

        if (! in_array($normalized, $allowed, true)) {
            throw new RuntimeException(sprintf(
                'Config value "%s" must be one of: %s.',
                $key,
                implode(', ', $allowed)
            ));
        }

        return $normalized;
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

    /**
     * @param mixed $value
     * @return list<string>
     */
    private static function kindList(mixed $value, string $key): array
    {
        $items = self::stringList($value, $key);

        foreach ($items as $kind) {
            if (! in_array($kind, self::ALL_KINDS, true)) {
                throw new RuntimeException(sprintf('Config value "%s" contains invalid kind %s.', $key, $kind));
            }
        }

        return array_values(array_unique($items));
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

    private static function nullableNormalizedRelativePath(mixed $value, string $key): ?string
    {
        if ($value === null) {
            return null;
        }

        return self::normalizedRelativePath($value, $key);
    }

    private static function pathStartsWith(string $path, string $prefix): bool
    {
        return $path === $prefix || str_starts_with($path, $prefix . '/');
    }
}
