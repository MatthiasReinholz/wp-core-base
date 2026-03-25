<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class ManagedSourceRegistry
{
    /** @var array<string, ManagedDependencySource> */
    private array $sources = [];

    /**
     * @param ManagedDependencySource ...$sources
     */
    public function __construct(ManagedDependencySource ...$sources)
    {
        foreach ($sources as $source) {
            $this->sources[$source->key()] = $source;
        }
    }

    /**
     * @param array<string, mixed> $dependency
     */
    public function for(array $dependency): ManagedDependencySource
    {
        $key = $dependency['source'] ?? null;

        if (! is_string($key) || ! isset($this->sources[$key])) {
            throw new RuntimeException(sprintf(
                'Unsupported managed dependency source: %s',
                is_scalar($key) ? (string) $key : gettype($key)
            ));
        }

        return $this->sources[$key];
    }
}
