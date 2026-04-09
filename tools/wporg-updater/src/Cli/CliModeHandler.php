<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater\Cli;

interface CliModeHandler
{
    public function supports(string $mode): bool;

    /**
     * @param array<string, mixed> $options
     */
    public function handle(string $mode, array $options): int;
}
