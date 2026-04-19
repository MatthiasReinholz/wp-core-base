<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

interface AutomationClient extends AutomationPullRequestReader
{
    public function getDefaultBranch(): string;

    /**
     * @param array<string, array{color:string, description:string}> $definitions
     */
    public function ensureLabels(array $definitions): void;

    /**
     * @return list<array<string, mixed>>
     */
    public function listOpenIssues(?string $label = null): array;

    /**
     * @param list<string> $labels
     * @return array<string, mixed>
     */
    public function createIssue(string $title, string $body, array $labels = []): array;

    /**
     * @return array<string, mixed>
     */
    public function updateIssue(int $number, string $title, string $body): array;

    public function closeIssue(int $number, ?string $comment = null): void;

    /**
     * @return array<string, mixed>
     */
    public function createPullRequest(string $title, string $head, string $base, string $body, bool $draft): array;

    /**
     * @return array<string, mixed>
     */
    public function updatePullRequest(int $number, string $title, string $body): array;

    public function closePullRequest(int $number, ?string $comment = null): void;

    /**
     * @param list<string> $labels
     */
    public function setIssueLabels(int $number, array $labels): void;

    /**
     * @param list<string> $labels
     */
    public function setPullRequestLabels(int $number, array $labels): void;

    public function convertToDraft(int $number): void;

    public function markReadyForReview(int $number): void;
}
