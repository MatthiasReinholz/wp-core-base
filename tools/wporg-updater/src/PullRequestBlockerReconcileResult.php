<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

final class PullRequestBlockerReconcileResult
{
    /**
     * @param list<PullRequestBlockerResult> $results
     * @param list<string> $warnings
     */
    public function __construct(
        public readonly string $status,
        public readonly int $exitCode,
        public readonly array $results,
        public readonly array $warnings,
    ) {
    }

    /**
     * @return array{status:string, exit_code:int, results:list<array{status:string, exit_code:int, blockers:list<string>, warnings:list<string>, state:string, pull_request_number:int}>, warnings:list<string>}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'exit_code' => $this->exitCode,
            'results' => array_map(
                static fn (PullRequestBlockerResult $result): array => $result->toArray(),
                $this->results
            ),
            'warnings' => $this->warnings,
        ];
    }
}
