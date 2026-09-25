<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class SyncExecutionResult
{
    /**
     * @param list<string> $fatalErrors
     * @param list<string> $advisoryWarnings
     * @param list<string> $dependencyWarnings
     * @param list<array{component_key:string,target_version:string,trust_state:string,trust_details:string}> $dependencyTrustStates
     */
    public function __construct(
        public readonly array $fatalErrors,
        public readonly array $dependencyWarnings,
        public readonly array $dependencyTrustStates,
        public readonly array $advisoryWarnings = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toSyncReport(): array
    {
        return SyncReport::build($this->fatalErrors, $this->dependencyWarnings, $this->dependencyTrustStates, $this->advisoryWarnings);
    }

    public function hasFatalErrors(): bool
    {
        return $this->fatalErrors !== [];
    }

    public function hasDependencyWarnings(): bool
    {
        return $this->dependencyWarnings !== [];
    }

    public function exitCode(bool $failOnSourceErrors): int
    {
        if ($this->hasFatalErrors()) {
            return 1;
        }
        return $failOnSourceErrors && $this->hasDependencyWarnings() ? SyncReport::EXIT_SOURCE_WARNINGS : 0;
    }

    public function throwOnFatalErrors(): void
    {
        if (! $this->hasFatalErrors()) {
            return;
        }

        throw new RuntimeException(implode("\n\n", $this->fatalErrors));
    }
}
