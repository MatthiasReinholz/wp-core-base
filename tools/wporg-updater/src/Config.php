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
     * @param array{api_base:string} $gitlab
     * @param array{provider:string, api_base:string, base_branch:?string, dry_run:bool, managed_kinds:list<string>} $automation
     * @param array{managed_release_min_age_hours:int, github_release_verification:string, extensions?:array<string,mixed>} $security
     * @param array<string,mixed> $extensions
     */
    public function __construct(
        public readonly string $repoRoot,
        public readonly string $manifestPath,
        public readonly string $profile,
        public readonly array $paths,
        public readonly array $core,
        public readonly array $runtime,
        public readonly array $github,
        public readonly array $gitlab,
        public readonly array $automation,
        public readonly array $security,
        public readonly array $dependencies,
        public readonly array $extensions = [],
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
        $gitlab = self::normalizeGitlab($data['gitlab'] ?? []);
        $automation = self::normalizeAutomation($data['automation'] ?? [], $github, $gitlab);
        $security = self::normalizeSecurity(self::arraySection($data, 'security'));
        $extensions = self::normalizeExtensions(array_key_exists('extensions', $data) ? $data['extensions'] : [], 'extensions');
        $dependencies = self::normalizeDependencies($data['dependencies'] ?? [], $paths);
        self::assertProfileCoreCompatibility($profile, $core);
        self::assertDependencyPathsSafe($repoRoot, $dependencies);
        self::assertSafeStageDirectory($runtime['stage_dir'], $paths, array_merge($runtime['ownership_roots'], array_column($dependencies, 'path')), $repoRoot);
        self::assertDependencyPathConsistency($dependencies, $runtime['manifest_mode']);

        return new self(
            repoRoot: $repoRoot,
            manifestPath: $resolvedManifest,
            profile: $profile,
            paths: $paths,
            core: $core,
            runtime: $runtime,
            github: $github,
            gitlab: $gitlab,
            automation: $automation,
            security: $security,
            dependencies: $dependencies,
            extensions: $extensions,
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

    public function automationProvider(): string
    {
        return $this->automation['provider'];
    }

    public function automationApiBase(): string
    {
        return $this->automation['api_base'];
    }

    public function dryRun(): bool
    {
        return $this->automation['dry_run'];
    }

    public function githubApiBase(): string
    {
        return $this->github['api_base'];
    }

    public function gitlabApiBase(): string
    {
        return $this->gitlab['api_base'];
    }

    public function managedReleaseMinAgeHours(): int
    {
        return $this->security['managed_release_min_age_hours'];
    }

    public function githubReleaseVerificationMode(): string
    {
        return $this->security['github_release_verification'];
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

        $rootPath = $this->rootForKind((string) $dependency['kind']);
        $sanitizePaths = [];

        foreach ($this->managedSanitizePaths() as $sanitizePath) {
            if ($sanitizePath === $rootPath) {
                $sanitizePaths[] = '';
                continue;
            }

            if (str_starts_with($sanitizePath, $rootPath . '/')) {
                $sanitizePaths[] = substr($sanitizePath, strlen($rootPath) + 1);
            }
        }

        return [
            array_values(array_unique(array_merge(
                $this->expandNestedManagedSanitizePaths($sanitizePaths),
                $this->dependencySanitizePaths($dependency)
            ))),
            array_values(array_unique(array_merge($this->managedSanitizeFiles(), $this->dependencySanitizeFiles($dependency)))),
        ];
    }

    public function stageDir(string $outputOverride = ''): string
    {
        $relativePath = $outputOverride !== ''
            ? ConfigPathRules::normalizeStageOutputOverride($outputOverride)
            : ConfigPathRules::normalizedRelativePath($this->runtime['stage_dir'], 'runtime.stage_dir');
        ConfigPathRules::assertSafeStageDirectory($relativePath, $this->paths, array_merge($this->runtime['ownership_roots'], array_column($this->dependencies, 'path')), $this->repoRoot);
        return rtrim($this->repoRoot, '/') . '/' . $relativePath;
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
        $matches = [];

        foreach ($this->dependencies as $dependency) {
            if (PremiumSourceResolver::matchesComponentKey($dependency, $key)) {
                $matches[] = $dependency;
            }
        }

        if (count($matches) === 1) {
            return $matches[0];
        }

        if (count($matches) > 1) {
            throw new RuntimeException(sprintf(
                'Dependency key %s is ambiguous after premium-provider key migration. Use the provider-aware component key instead.',
                $key
            ));
        }

        throw new RuntimeException(sprintf('Dependency not found for key %s.', $key));
    }

    /**
     * @param array<string, mixed> $dependency
     */
    public function dependencyMinReleaseAgeHours(array $dependency): int
    {
        $override = $dependency['source_config']['min_release_age_hours'] ?? null;

        if (is_int($override)) {
            return $override;
        }

        return $this->managedReleaseMinAgeHours();
    }

    /**
     * @param array<string, mixed> $dependency
     */
    public function dependencyVerificationMode(array $dependency): string
    {
        $override = $dependency['source_config']['verification_mode'] ?? 'inherit';

        if (! is_string($override) || $override === '' || $override === 'inherit') {
            return (string) (in_array((string) $dependency['source'], ['github-release', 'gitlab-release'], true)
                ? $this->githubReleaseVerificationMode()
                : 'none');
        }

        return $override;
    }

    /**
     * @param list<array<string, mixed>> $dependencies
     */
    public function withDependencies(array $dependencies): self
    {
        self::assertDependencyPathsSafe($this->repoRoot, $dependencies);
        self::assertDependencyPathConsistency($dependencies, $this->runtime['manifest_mode']);
        return new self(
            repoRoot: $this->repoRoot,
            manifestPath: $this->manifestPath,
            profile: $this->profile,
            paths: $this->paths,
            core: $this->core,
            runtime: $this->runtime,
            github: $this->github,
            gitlab: $this->gitlab,
            automation: $this->automation,
            security: $this->security,
            dependencies: $dependencies,
            extensions: $this->extensions,
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
            'gitlab' => $this->gitlab,
            'automation' => $this->automation,
            'security' => $this->security,
            ...($this->extensions !== [] ? ['extensions' => $this->extensions] : []),
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
     * @param array{mode:string, enabled:bool} $core
     */
    private static function assertProfileCoreCompatibility(string $profile, array $core): void
    {
        if ($profile === 'content-only' && $core['mode'] === 'managed' && $core['enabled']) {
            throw new RuntimeException('content-only profile may not manage WordPress core.');
        }
    }

    /**
     * @param array{content_root:string, plugins_root:string, themes_root:string, mu_plugins_root:string} $paths
     * @param list<string> $ownershipRoots
     */
    private static function assertSafeStageDirectory(string $stageDir, array $paths, array $ownershipRoots, string $repoRoot): void
    {
        ConfigPathRules::assertSafeStageDirectory($stageDir, $paths, $ownershipRoots, $repoRoot);
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

        $allowRuntimePaths = self::normalizedPathList($value['allow_runtime_paths'] ?? [], 'runtime.allow_runtime_paths');
        self::assertSafeRuntimeAllowPaths($allowRuntimePaths, $paths, $ownershipRoots);

        return [
            'stage_dir' => self::normalizedRelativePath($value['stage_dir'] ?? '.wp-core-base/build/runtime', 'runtime.stage_dir'),
            'manifest_mode' => self::enumValue($value['manifest_mode'] ?? 'strict', 'runtime.manifest_mode', ['strict', 'relaxed']),
            'validation_mode' => self::enumValue($value['validation_mode'] ?? 'source-clean', 'runtime.validation_mode', ['source-clean', 'staged-clean']),
            'ownership_roots' => array_values(array_unique($ownershipRoots)),
            'staged_kinds' => self::kindList($value['staged_kinds'] ?? self::RUNTIME_KINDS, 'runtime.staged_kinds'),
            'validated_kinds' => self::kindList($value['validated_kinds'] ?? self::RUNTIME_KINDS, 'runtime.validated_kinds'),
            'forbidden_paths' => self::stringList(
                $value['forbidden_paths'] ?? RuntimeHygieneDefaults::FORBIDDEN_PATHS,
                'runtime.forbidden_paths'
            ),
            'forbidden_files' => self::stringList(
                $value['forbidden_files'] ?? RuntimeHygieneDefaults::FORBIDDEN_FILES,
                'runtime.forbidden_files'
            ),
            'allow_runtime_paths' => $allowRuntimePaths,
            'strip_paths' => self::normalizedPathList($value['strip_paths'] ?? [], 'runtime.strip_paths'),
            'strip_files' => self::stringList($value['strip_files'] ?? [], 'runtime.strip_files'),
            'managed_sanitize_paths' => self::normalizedPathList(
                $value['managed_sanitize_paths'] ?? RuntimeHygieneDefaults::managedSanitizePaths($paths),
                'runtime.managed_sanitize_paths'
            ),
            'managed_sanitize_files' => self::stringList(
                $value['managed_sanitize_files'] ?? RuntimeHygieneDefaults::MANAGED_SANITIZE_FILES,
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
     * @return array{api_base:string}
     */
    private static function normalizeGitlab(array $value): array
    {
        return [
            'api_base' => self::string($value['api_base'] ?? (getenv('CI_API_V4_URL') ?: 'https://gitlab.com/api/v4'), 'gitlab.api_base'),
        ];
    }

    /**
     * @param array<string, mixed> $value
     * @param array{api_base:string} $github
     * @param array{api_base:string} $gitlab
     * @return array{provider:string, api_base:string, base_branch:?string, dry_run:bool, managed_kinds:list<string>}
     */
    private static function normalizeAutomation(array $value, array $github, array $gitlab): array
    {
        $provider = self::enumValue($value['provider'] ?? 'github', 'automation.provider', ['github', 'gitlab']);
        $defaultApiBase = match ($provider) {
            'github' => $github['api_base'],
            'gitlab' => $gitlab['api_base'],
            default => throw new RuntimeException(sprintf('Unsupported automation provider: %s', $provider)),
        };

        return [
            'provider' => $provider,
            'api_base' => self::string($value['api_base'] ?? $defaultApiBase, 'automation.api_base'),
            'base_branch' => self::nullableString($value['base_branch'] ?? null),
            'dry_run' => (bool) ($value['dry_run'] ?? (bool) getenv('WPORG_UPDATE_DRY_RUN')),
            'managed_kinds' => self::kindList($value['managed_kinds'] ?? self::MANAGED_KINDS, 'automation.managed_kinds'),
        ];
    }

    /**
     * @param array<string, mixed> $value
     * @return array{managed_release_min_age_hours:int, github_release_verification:string, extensions?:array<string,mixed>}
     */
    private static function normalizeSecurity(array $value): array
    {
        self::assertKnownKeys($value, ['managed_release_min_age_hours', 'github_release_verification', 'extensions'], 'security');
        $extensions = self::normalizeExtensions(array_key_exists('extensions', $value) ? $value['extensions'] : [], 'security.extensions');
        return [
            ...($extensions !== [] ? ['extensions' => $extensions] : []),
            'managed_release_min_age_hours' => self::nonNegativeInt(
                array_key_exists('managed_release_min_age_hours', $value) ? $value['managed_release_min_age_hours'] : 0,
                'security.managed_release_min_age_hours'
            ),
            'github_release_verification' => self::enumValue(
                array_key_exists('github_release_verification', $value) ? $value['github_release_verification'] : 'checksum-sidecar-optional',
                'security.github_release_verification',
                ['none', 'checksum-sidecar-optional', 'checksum-sidecar-required']
            ),
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
                PremiumSourceResolver::allowedSources()
            );
            $path = self::normalizedRelativePath($dependency['path'] ?? null, sprintf('dependencies[%s].path', $slug));
            $mainFile = self::nullableNormalizedRelativePath($dependency['main_file'] ?? null, sprintf('dependencies[%s].main_file', $slug));
            $name = self::string($dependency['name'] ?? $slug, sprintf('dependencies[%s].name', $slug));
            $version = self::nullableString($dependency['version'] ?? null);
            $checksum = self::nullableString($dependency['checksum'] ?? null);
            $archiveSubdir = self::nullableString($dependency['archive_subdir'] ?? '') ?? '';
            if ($archiveSubdir !== '') {
                $archiveSubdir = ConfigPathRules::normalizedRelativePath($archiveSubdir, sprintf('dependencies[%s].archive_subdir', $slug));
            }
            $extraLabels = LabelHelper::normalizeList(
                self::stringList($dependency['extra_labels'] ?? [], sprintf('dependencies[%s].extra_labels', $slug))
            );
            $sourceConfig = self::arraySection($dependency, 'source_config');
            self::assertKnownKeys($sourceConfig, [
                'github_repository', 'github_release_asset_pattern', 'github_token_env',
                'gitlab_project', 'gitlab_release_asset_pattern', 'gitlab_token_env', 'gitlab_api_base',
                'generic_json_url', 'min_release_age_hours', 'verification_mode', 'checksum_asset_pattern',
                'credential_key', 'provider', 'provider_product_id', 'extensions',
            ], sprintf('dependencies[%s].source_config', $slug));
            $sourceExtensions = self::normalizeExtensions(array_key_exists('extensions', $sourceConfig) ? $sourceConfig['extensions'] : [], sprintf('dependencies[%s].source_config.extensions', $slug));
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

            if (PremiumSourceResolver::isPremiumSource($source) && $kind !== 'plugin') {
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
            $gitlabProject = self::nullableString($sourceConfig['gitlab_project'] ?? null);
            $gitlabReleaseAssetPattern = self::nullableString($sourceConfig['gitlab_release_asset_pattern'] ?? null);
            $gitlabTokenEnv = self::nullableString($sourceConfig['gitlab_token_env'] ?? null);
            $gitlabApiBase = self::nullableString($sourceConfig['gitlab_api_base'] ?? null);
            $genericJsonUrl = self::nullableString($sourceConfig['generic_json_url'] ?? null);
            $minReleaseAgeHours = isset($sourceConfig['min_release_age_hours']) && $sourceConfig['min_release_age_hours'] !== ''
                ? self::nonNegativeInt($sourceConfig['min_release_age_hours'], sprintf('dependencies[%s].source_config.min_release_age_hours', $slug))
                : null;
            $verificationMode = self::nullableString($sourceConfig['verification_mode'] ?? null) ?? 'inherit';
            $checksumAssetPattern = self::nullableString($sourceConfig['checksum_asset_pattern'] ?? null);
            $credentialKey = self::nullableString($sourceConfig['credential_key'] ?? null);
            $provider = self::nullableString($sourceConfig['provider'] ?? null);
            $providerProductId = isset($sourceConfig['provider_product_id']) && $sourceConfig['provider_product_id'] !== ''
                ? self::nonNegativeInt($sourceConfig['provider_product_id'], sprintf('dependencies[%s].source_config.provider_product_id', $slug))
                : null;

            $normalizedSourceConfig = PremiumSourceResolver::normalizeSourceConfig($source, $sourceConfig);
            $provider = PremiumSourceResolver::providerFor($source, $normalizedSourceConfig);

            if ($source === 'github-release' && $githubRepository === null) {
                throw new RuntimeException(sprintf('GitHub release dependency %s must define source_config.github_repository.', $slug));
            }

            if ($source === 'github-release' && $githubReleaseAssetPattern === null && $verificationMode !== 'none') {
                throw new RuntimeException(sprintf(
                    'GitHub release dependency %s must define source_config.github_release_asset_pattern unless source_config.verification_mode is explicitly set to none.',
                    $slug
                ));
            }

            if ($source === 'gitlab-release' && $gitlabProject === null) {
                throw new RuntimeException(sprintf('GitLab release dependency %s must define source_config.gitlab_project.', $slug));
            }

            if ($source === 'gitlab-release' && $gitlabReleaseAssetPattern === null) {
                throw new RuntimeException(sprintf(
                    'GitLab release dependency %s must define source_config.gitlab_release_asset_pattern.',
                    $slug
                ));
            }

            if ($source === 'generic-json' && ! in_array($kind, ['plugin', 'theme'], true)) {
                throw new RuntimeException(sprintf('Generic JSON source is only supported for plugin and theme dependencies (%s).', $slug));
            }

            if ($source === 'generic-json' && $genericJsonUrl === null) {
                throw new RuntimeException(sprintf('Generic JSON dependency %s must define source_config.generic_json_url.', $slug));
            }

            if ($source === 'generic-json' && ! self::isHttpsUrl($genericJsonUrl)) {
                throw new RuntimeException(sprintf('Generic JSON dependency %s must use an HTTPS source_config.generic_json_url.', $slug));
            }

            if (! in_array($verificationMode, ['inherit', 'none', 'checksum-sidecar-optional', 'checksum-sidecar-required'], true)) {
                throw new RuntimeException(sprintf(
                    'Dependency %s must use source_config.verification_mode of inherit, none, checksum-sidecar-optional, or checksum-sidecar-required.',
                    $slug
                ));
            }

            if ($source === 'generic-json' && ! in_array($verificationMode, ['inherit', 'none'], true)) {
                throw new RuntimeException(sprintf(
                    'Generic JSON dependency %s may only use source_config.verification_mode of inherit or none.',
                    $slug
                ));
            }

            if ($source === 'generic-json' && $checksumAssetPattern !== null) {
                throw new RuntimeException(sprintf(
                    'Generic JSON dependency %s may not define source_config.checksum_asset_pattern.',
                    $slug
                ));
            }

            if ($providerProductId !== null && $providerProductId <= 0) {
                throw new RuntimeException(sprintf('Premium dependency %s must use a positive source_config.provider_product_id when it is set.', $slug));
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
                    ...($sourceExtensions !== [] ? ['extensions' => $sourceExtensions] : []),
                    'github_repository' => $githubRepository,
                    'github_release_asset_pattern' => $githubReleaseAssetPattern,
                    'github_token_env' => $githubTokenEnv,
                    'gitlab_project' => $gitlabProject,
                    'gitlab_release_asset_pattern' => $gitlabReleaseAssetPattern,
                    'gitlab_token_env' => $gitlabTokenEnv,
                    'gitlab_api_base' => $gitlabApiBase,
                    'generic_json_url' => $genericJsonUrl,
                    'min_release_age_hours' => $minReleaseAgeHours,
                    'verification_mode' => $verificationMode,
                    'checksum_asset_pattern' => $checksumAssetPattern,
                    'credential_key' => $credentialKey,
                    'provider' => $provider,
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
                'component_key' => PremiumSourceResolver::componentKey($kind, $source, $slug, [
                    'provider' => $provider,
                ]),
            ];
        }

        return $dependencies;
    }

    /** @param list<array<string,mixed>> $dependencies */
    private static function assertDependencyPathsSafe(string $repoRoot, array $dependencies): void
    {
        foreach ($dependencies as $dependency) {
            if ($dependency['management'] !== 'ignored') {
                ConfigPathRules::assertSafeDependencyPath($repoRoot, (string) $dependency['path']);
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $dependencies
     */
    private static function assertDependencyPathConsistency(array $dependencies, string $manifestMode): void
    {
        $identities = [];
        foreach ($dependencies as $dependency) {
            $identity = (string) $dependency['component_key'];
            if (isset($identities[$identity])) {
                throw new RuntimeException(sprintf('Duplicate dependency component_key %s is not allowed in any manifest mode.', $identity));
            }
            $identities[$identity] = true;
        }
        if ($manifestMode !== 'strict') {
            return;
        }

        $pathsByDependency = [];

        foreach ($dependencies as $dependency) {
            $path = (string) $dependency['path'];
            $pathsByDependency[$path][] = (string) $dependency['component_key'];
        }

        foreach ($pathsByDependency as $path => $componentKeys) {
            if (count($componentKeys) > 1) {
                throw new RuntimeException(sprintf(
                    'Strict manifest mode does not allow multiple dependency entries for the same runtime path %s: %s.',
                    $path,
                    implode(', ', $componentKeys)
                ));
            }
        }

        $dependencyPaths = array_map(
            static fn (array $dependency): string => (string) $dependency['path'],
            $dependencies
        );
        sort($dependencyPaths);

        for ($index = 0, $count = count($dependencyPaths); $index < $count; $index++) {
            for ($cursor = $index + 1; $cursor < $count; $cursor++) {
                $left = $dependencyPaths[$index];
                $right = $dependencyPaths[$cursor];

                if (! self::pathStartsWith($left, $right) && ! self::pathStartsWith($right, $left)) {
                    continue;
                }

                throw new RuntimeException(sprintf(
                    'Strict manifest mode does not allow overlapping dependency runtime paths: %s and %s.',
                    $left,
                    $right
                ));
            }
        }
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
            $management === 'managed' && $source === 'gitlab-release' => 'managed-private',
            $management === 'managed' && $source === 'generic-json' => 'managed-private',
            $management === 'managed' && PremiumSourceResolver::isPremiumSource($source) => 'managed-premium',
            $management === 'local' && $source === 'local' => 'local-owned',
            $management === 'ignored' && $source === 'local' => 'ignored',
            default => throw new RuntimeException(sprintf('Invalid management/source combination: %s/%s', $management, $source)),
        };
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private static function arraySection(array $data, string $key): array
    {
        if (! array_key_exists($key, $data)) {
            return [];
        }
        if (! is_array($data[$key])) {
            throw new RuntimeException(sprintf('Config value "%s" must be an array.', $key));
        }
        return $data[$key];
    }

    /** @param array<string,mixed> $value @param list<string> $allowed */
    private static function assertKnownKeys(array $value, array $allowed, string $section): void
    {
        foreach (array_keys($value) as $key) {
            if (! in_array($key, $allowed, true)) {
                throw new RuntimeException(sprintf('Unknown config value "%s.%s". Put custom data under %s.extensions.', $section, $key, $section));
            }
        }
    }

    /** @return array<string,mixed> */
    private static function normalizeExtensions(mixed $value, string $key): array
    {
        if (! is_array($value)) {
            throw new RuntimeException(sprintf('Config value "%s" must be an extension namespace map.', $key));
        }
        foreach ($value as $namespace => $extension) {
            if (! is_string($namespace) || trim($namespace) === '') {
                throw new RuntimeException(sprintf('Config value "%s" must use non-empty namespace keys.', $key));
            }
            self::assertExtensionValue($extension, $key . '.' . $namespace);
        }
        return $value;
    }

    private static function assertExtensionValue(mixed $value, string $key, int $depth = 0): void
    {
        if ($depth > 32 || (is_float($value) && ! is_finite($value))) {
            throw new RuntimeException(sprintf('Extension value "%s" exceeds supported nesting or contains a non-finite number.', $key));
        }
        if (is_array($value)) {
            foreach ($value as $name => $child) {
                self::assertExtensionValue($child, $key . '.' . $name, $depth + 1);
            }
            return;
        }
        if ($value !== null && ! is_scalar($value)) {
            throw new RuntimeException(sprintf('Extension value "%s" must contain only arrays, scalar values, or null.', $key));
        }
    }

    private static function isHttpsUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && trim((string) ($parts['host'] ?? '')) !== '';
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

    private static function nonNegativeInt(mixed $value, string $key): int
    {
        if (is_int($value)) {
            $normalized = $value;
        } elseif (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
            $normalized = (int) trim($value);
        } else {
            throw new RuntimeException(sprintf('Config value "%s" must be a non-negative integer.', $key));
        }

        if ($normalized < 0) {
            throw new RuntimeException(sprintf('Config value "%s" must be a non-negative integer.', $key));
        }

        return $normalized;
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
        return ConfigPathRules::normalizedRelativePath($value, $key);
    }

    private static function nullableNormalizedRelativePath(mixed $value, string $key): ?string
    {
        if ($value === null) {
            return null;
        }

        return self::normalizedRelativePath($value, $key);
    }

    /**
     * Promote single-segment legacy sanitize rules such as `docs` to also match nested occurrences.
     *
     * @param list<string> $sanitizePaths
     * @return list<string>
     */
    private function expandNestedManagedSanitizePaths(array $sanitizePaths): array
    {
        $expanded = $sanitizePaths;

        foreach ($sanitizePaths as $sanitizePath) {
            if (
                $sanitizePath === ''
                || str_contains($sanitizePath, '/')
                || str_contains($sanitizePath, '*')
                || str_contains($sanitizePath, '?')
            ) {
                continue;
            }

            $expanded[] = '**/' . $sanitizePath;
        }

        return array_values(array_unique($expanded));
    }

    private static function pathStartsWith(string $path, string $prefix): bool
    {
        return ConfigPathRules::pathStartsWith($path, $prefix);
    }

    /**
     * @param list<string> $allowRuntimePaths
     * @param array{content_root:string, plugins_root:string, themes_root:string, mu_plugins_root:string} $paths
     * @param list<string> $ownershipRoots
     */
    private static function assertSafeRuntimeAllowPaths(array $allowRuntimePaths, array $paths, array $ownershipRoots): void
    {
        ConfigPathRules::assertSafeRuntimeAllowPaths($allowRuntimePaths, $paths, $ownershipRoots);
    }
}
