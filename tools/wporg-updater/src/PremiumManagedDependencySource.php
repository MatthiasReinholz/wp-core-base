<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

interface PremiumManagedDependencySource extends ManagedDependencySource
{
    /**
     * @param array<string, mixed> $dependency
     */
    public function validateCredentialConfiguration(array $dependency): void;
}
