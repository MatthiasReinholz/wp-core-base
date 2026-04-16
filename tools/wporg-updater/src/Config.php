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
     * @param array{managed_release_min_age_hours:int, github_release_verification:string} $security
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
        public readonly array $security,
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
        $normalized = ConfigNormalizer::normalize($data);

        return new self(
            repoRoot: $repoRoot,
            manifestPath: $resolvedManifest,
            profile: $normalized['profile'],
            paths: $normalized['paths'],
            core: $normalized['core'],
            runtime: $normalized['runtime'],
            github: $normalized['github'],
            automation: $normalized['automation'],
            security: $normalized['security'],
            dependencies: $normalized['dependencies'],
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
        if ($outputOverride !== '') {
            $normalizedOverride = ConfigPathRules::normalizeStageOutputOverride($outputOverride);
            ConfigPathRules::assertSafeStageDirectory($normalizedOverride, $this->paths, $this->runtime['ownership_roots']);

            return $this->repoRoot . '/' . $normalizedOverride;
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
            return (string) ($dependency['source'] === 'github-release'
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
        return new self(
            repoRoot: $this->repoRoot,
            manifestPath: $this->manifestPath,
            profile: $this->profile,
            paths: $this->paths,
            core: $this->core,
            runtime: $this->runtime,
            github: $this->github,
            automation: $this->automation,
            security: $this->security,
            dependencies: $dependencies,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ConfigSerializer::toArray($this);
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

}
