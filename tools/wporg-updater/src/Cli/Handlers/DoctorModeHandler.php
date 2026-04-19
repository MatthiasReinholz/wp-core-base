<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater\Cli\Handlers;

use Closure;
use WpOrgPluginUpdater\Cli\CliModeHandler;
use WpOrgPluginUpdater\EnvironmentDoctor;

final class DoctorModeHandler implements CliModeHandler
{
    public function __construct(
        private readonly string $repoRoot,
        private readonly bool $jsonOutput,
        private readonly Closure $emitJson,
    ) {
    }

    public function supports(string $mode): bool
    {
        return $mode === 'doctor';
    }

    /**
     * @param array<string, mixed> $options
     */
    public function handle(string $mode, array $options): int
    {
        $doctor = new EnvironmentDoctor($this->repoRoot, ! $this->jsonOutput);
        $providerOverride = isset($options['github']) ? 'github' : null;
        $requireAutomation = isset($options['automation']) || isset($options['github']);
        $exitCode = $doctor->run($requireAutomation, $providerOverride);

        if ($this->jsonOutput) {
            ($this->emitJson)($doctor->report(), $exitCode);
        }

        return $exitCode;
    }
}
