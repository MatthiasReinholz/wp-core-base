<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class DependencyAuthoringSupport
{
    public function __construct(
        private readonly Config $config,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function findDependencyForAdoption(array $options): array
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

    public function assertAddAllowed(string $kind, string $source, string $management): void
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

    public function assertDoesNotAlreadyExist(string $kind, string $source, string $slug, bool $force, ?string $provider): void
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
    public function resolvedPremiumProviderFromOptions(string $source, array $options): ?string
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
    public function dependencyMatchesIdentity(array $dependency, string $kind, string $source, string $slug, ?string $provider): bool
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

    /**
     * @param array<string, mixed> $options
     */
    public function resolveManagement(array $options, string $source): string
    {
        $management = $this->nullableString($options['management'] ?? null);

        if ($management !== null) {
            return $management;
        }

        return $source === 'local' ? 'local' : 'managed';
    }

    /**
     * @param array<string, mixed> $options
     */
    public function resolveSlug(array $options): string
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

    public function resolvePath(string $kind, string $slug, mixed $path): string
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
    public function defaultExtraLabels(string $kind, string $slug): array
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
    public function defaultPolicy(string $management, string $source): array
    {
        $policy = [
            'class' => match (true) {
                $management === 'managed' && $source === 'wordpress.org' => 'managed-upstream',
                $management === 'managed' && in_array($source, ['github-release', 'gitlab-release', 'generic-json'], true) => 'managed-private',
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
    public function nextStepsForDependency(array $dependency): array
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
    public function requiredString(array $options, string $key): string
    {
        $value = $this->nullableString($options[$key] ?? null);

        if ($value === null) {
            throw new RuntimeException(sprintf('--%s is required.', $key));
        }

        return $value;
    }

    public function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
