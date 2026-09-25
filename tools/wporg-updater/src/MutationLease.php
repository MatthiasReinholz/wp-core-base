<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use Closure;

/** Keeps a repository lock alive until the owning operation leaves its scope. */
final class MutationLease
{
    private bool $released = false;

    public function __construct(private readonly Closure $release)
    {
    }

    public function close(): void
    {
        if (! $this->released) {
            $this->released = true;
            ($this->release)();
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
