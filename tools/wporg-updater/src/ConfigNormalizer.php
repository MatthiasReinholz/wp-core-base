<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class ConfigNormalizer
{
    private const RUNTIME_KINDS = ['plugin', 'theme', 'mu-plugin-package', 'mu-plugin-file', 'runtime-file', 'runtime-directory'];
    private const ALL_KINDS = ['plugin', 'theme', 'mu-plugin-package', 'mu-plugin-file', 'runtime-file', 'runtime-directory'];
    public const DEFAULT_FORBIDDEN_PATHS = [
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
    ];
    public const DEFAULT_FORBIDDEN_FILES = [
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
    ];
    public const DEFAULT_MANAGED_SANITIZE_PATH_SUFFIXES = [
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
    ];
    public const DEFAULT_MANAGED_SANITIZE_FILES = self::DEFAULT_FORBIDDEN_FILES;

    /**
     * @param array<string, mixed> $data
     * @return array{
     *   profile:string,
     *   paths:array{content_root:string, plugins_root:string, themes_root:string, mu_plugins_root:string},
     *   core:array{mode:string, enabled:bool},
     *   runtime:array{stage_dir:string, manifest_mode:string, validation_mode:string, ownership_roots:list<string>, staged_kinds:list<string>, validated_kinds:list<string>, forbidden_paths:list<string>, forbidden_files:list<string>, allow_runtime_paths:list<string>, strip_paths:list<string>, strip_files:list<string>, managed_sanitize_paths:list<string>, managed_sanitize_files:list<string>},
     *   github:array{api_base:string},
     *   automation:array{base_branch:?string, dry_run:bool, managed_kinds:list<string>},
     *   security:array{managed_release_min_age_hours:int, github_release_verification:string},
     *   dependencies:list<array<string, mixed>>
     * }
     */
    public static function normalize(array $data): array
    {
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
        $security = self::normalizeSecurity($data['security'] ?? []);
        $dependencies = self::normalizeDependencies($data['dependencies'] ?? [], $paths);
        self::assertProfileCoreCompatibility($profile, $core);
        ConfigPathRules::assertSafeStageDirectory($runtime['stage_dir'], $paths, $runtime['ownership_roots']);
        self::assertDependencyPathConsistency($dependencies, $runtime['manifest_mode']);

        return [
            'profile' => $profile,
            'paths' => $paths,
            'core' => $core,
            'runtime' => $runtime,
            'github' => $github,
            'automation' => $automation,
            'security' => $security,
            'dependencies' => $dependencies,
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
            'content_root' => ConfigPathRules::normalizedRelativePath($value['content_root'] ?? $defaults['content_root'], 'paths.content_root'),
            'plugins_root' => ConfigPathRules::normalizedRelativePath($value['plugins_root'] ?? $defaults['plugins_root'], 'paths.plugins_root'),
            'themes_root' => ConfigPathRules::normalizedRelativePath($value['themes_root'] ?? $defaults['themes_root'], 'paths.themes_root'),
            'mu_plugins_root' => ConfigPathRules::normalizedRelativePath($value['mu_plugins_root'] ?? $defaults['mu_plugins_root'], 'paths.mu_plugins_root'),
        ];

        foreach (['plugins_root', 'themes_root', 'mu_plugins_root'] as $key) {
            if (! ConfigPathRules::pathStartsWith($paths[$key], $paths['content_root'])) {
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
            if (! ConfigPathRules::pathStartsWith($ownershipRoot, $paths['content_root'])) {
                throw new RuntimeException(sprintf('runtime.ownership_roots entry %s must live under paths.content_root.', $ownershipRoot));
            }
        }

        $allowRuntimePaths = self::normalizedPathList($value['allow_runtime_paths'] ?? [], 'runtime.allow_runtime_paths');
        ConfigPathRules::assertSafeRuntimeAllowPaths($allowRuntimePaths, $paths, $ownershipRoots);

        return [
            'stage_dir' => ConfigPathRules::normalizedRelativePath($value['stage_dir'] ?? '.wp-core-base/build/runtime', 'runtime.stage_dir'),
            'manifest_mode' => self::enumValue($value['manifest_mode'] ?? 'strict', 'runtime.manifest_mode', ['strict', 'relaxed']),
            'validation_mode' => self::enumValue($value['validation_mode'] ?? 'source-clean', 'runtime.validation_mode', ['source-clean', 'staged-clean']),
            'ownership_roots' => array_values(array_unique($ownershipRoots)),
            'staged_kinds' => self::kindList($value['staged_kinds'] ?? self::RUNTIME_KINDS, 'runtime.staged_kinds'),
            'validated_kinds' => self::kindList($value['validated_kinds'] ?? self::RUNTIME_KINDS, 'runtime.validated_kinds'),
            'forbidden_paths' => self::stringList(
                $value['forbidden_paths'] ?? self::DEFAULT_FORBIDDEN_PATHS,
                'runtime.forbidden_paths'
            ),
            'forbidden_files' => self::stringList(
                $value['forbidden_files'] ?? self::DEFAULT_FORBIDDEN_FILES,
                'runtime.forbidden_files'
            ),
            'allow_runtime_paths' => $allowRuntimePaths,
            'strip_paths' => self::normalizedPathList($value['strip_paths'] ?? [], 'runtime.strip_paths'),
            'strip_files' => self::stringList($value['strip_files'] ?? [], 'runtime.strip_files'),
            'managed_sanitize_paths' => self::normalizedPathList(
                $value['managed_sanitize_paths'] ?? self::defaultManagedSanitizePaths($paths),
                'runtime.managed_sanitize_paths'
            ),
            'managed_sanitize_files' => self::stringList(
                $value['managed_sanitize_files'] ?? self::DEFAULT_MANAGED_SANITIZE_FILES,
                'runtime.managed_sanitize_files'
            ),
        ];
    }

    /**
     * @param array{content_root:string, plugins_root:string, themes_root:string, mu_plugins_root:string} $paths
     * @return list<string>
     */
    private static function defaultManagedSanitizePaths(array $paths): array
    {
        $entries = [];

        foreach ([$paths['plugins_root'], $paths['themes_root'], $paths['mu_plugins_root']] as $root) {
            foreach (self::DEFAULT_MANAGED_SANITIZE_PATH_SUFFIXES as $suffix) {
                $entries[] = $root . '/' . $suffix;
            }
        }

        return $entries;
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
            'managed_kinds' => self::kindList($value['managed_kinds'] ?? ['plugin', 'theme', 'mu-plugin-package'], 'automation.managed_kinds'),
        ];
    }

    /**
     * @param array<string, mixed> $value
     * @return array{managed_release_min_age_hours:int, github_release_verification:string}
     */
    private static function normalizeSecurity(array $value): array
    {
        return [
            'managed_release_min_age_hours' => self::nonNegativeInt(
                $value['managed_release_min_age_hours'] ?? 0,
                'security.managed_release_min_age_hours'
            ),
            'github_release_verification' => self::enumValue(
                $value['github_release_verification'] ?? 'checksum-sidecar-optional',
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
            $path = ConfigPathRules::normalizedRelativePath($dependency['path'] ?? null, sprintf('dependencies[%s].path', $slug));
            $mainFile = ConfigPathRules::nullableNormalizedRelativePath($dependency['main_file'] ?? null, sprintf('dependencies[%s].main_file', $slug));
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
                ? (int) $sourceConfig['provider_product_id']
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

            if ($source === 'generic-json' && $genericJsonUrl !== null && ! self::isHttpsUrl($genericJsonUrl)) {
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

            if (! ConfigPathRules::pathStartsWith($path, $expectedPrefix)) {
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

    /**
     * @param list<array<string, mixed>> $dependencies
     */
    private static function assertDependencyPathConsistency(array $dependencies, string $manifestMode): void
    {
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

        $dependencyPaths = array_values(array_map(
            static fn (array $dependency): string => (string) $dependency['path'],
            $dependencies
        ));
        sort($dependencyPaths);

        for ($index = 0, $count = count($dependencyPaths); $index < $count; $index++) {
            for ($cursor = $index + 1; $cursor < $count; $cursor++) {
                $left = $dependencyPaths[$index];
                $right = $dependencyPaths[$cursor];

                if (! ConfigPathRules::pathStartsWith($left, $right) && ! ConfigPathRules::pathStartsWith($right, $left)) {
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

    /**
     * @param array{content_root:string, plugins_root:string, themes_root:string, mu_plugins_root:string} $paths
     */
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
        return array_map(static fn (string $path): string => ConfigPathRules::normalizedRelativePath($path, $key), self::stringList($value, $key));
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
}
