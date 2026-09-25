<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

/** Optional capability. Existing adapters retain historical support unless they opt out. */
interface HistoricalVersionSource
{
    /** @param array<string,mixed> $dependency */
    public function supportsHistoricalVersions(array $dependency): bool;
}
