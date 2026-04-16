<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

final class DependencyManifestMutator
{
    public function __construct(
        private readonly Config $config,
        private readonly DependencyAuthoringSupport $support,
    ) {
    }

    /**
     * @param array<string, mixed> $entry
     */
    public function writeValidatedConfigWithDependency(array $entry): Config
    {
        $manifest = $this->config->toArray();
        $replaced = false;
        $entryProvider = PremiumSourceResolver::providerForDependency($entry);

        foreach ($manifest['dependencies'] as $index => $dependency) {
            if ($this->support->dependencyMatchesIdentity($dependency, (string) $entry['kind'], (string) $entry['source'], (string) $entry['slug'], $entryProvider)) {
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
    public function writeConfigReplacingDependency(array $removedDependency, array $entry): Config
    {
        $manifest = $this->config->toArray();
        $dependencies = [];
        $replaced = false;
        $entryProvider = PremiumSourceResolver::providerForDependency($entry);

        foreach ($manifest['dependencies'] as $dependency) {
            if (
                $this->support->dependencyMatchesIdentity(
                    $dependency,
                    (string) $removedDependency['kind'],
                    (string) $removedDependency['source'],
                    (string) $removedDependency['slug'],
                    PremiumSourceResolver::providerForDependency($removedDependency)
                )
            ) {
                continue;
            }

            if ($this->support->dependencyMatchesIdentity($dependency, (string) $entry['kind'], (string) $entry['source'], (string) $entry['slug'], $entryProvider)) {
                $dependencies[] = $entry;
                $replaced = true;
                continue;
            }

            $dependencies[] = $dependency;
        }

        if (! $replaced) {
            $dependencies[] = $entry;
        }

        $manifest['dependencies'] = array_values($dependencies);

        return Config::fromArray($this->config->repoRoot, $manifest, $this->config->manifestPath);
    }
}
