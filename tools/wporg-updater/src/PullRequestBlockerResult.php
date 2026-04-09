<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

final class PullRequestBlockerResult
{
    /**
     * @param list<string> $blockers
     * @param list<string> $warnings
     */
    public function __construct(
        public readonly string $status,
        public readonly int $exitCode,
        public readonly array $blockers,
        public readonly array $warnings,
        public readonly string $state,
        public readonly int $pullRequestNumber,
    ) {
    }

    /**
     * @return array{status:string, exit_code:int, blockers:list<string>, warnings:list<string>, state:string, pull_request_number:int}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'exit_code' => $this->exitCode,
            'blockers' => array_values($this->blockers),
            'warnings' => array_values($this->warnings),
            'state' => $this->state,
            'pull_request_number' => $this->pullRequestNumber,
        ];
    }
}
