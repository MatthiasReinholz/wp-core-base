<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class GitHubClient implements GitHubAutomationClient
{
    public function __construct(
        private readonly HttpClient $httpClient,
        private readonly string $repository,
        private readonly string $token,
        private readonly string $apiBase = 'https://api.github.com',
        private readonly bool $dryRun = false,
    ) {
    }

    public static function fromEnvironment(HttpClient $httpClient, string $apiBase, bool $dryRun = false): self
    {
        $repository = getenv('GITHUB_REPOSITORY');
        $token = getenv('GITHUB_TOKEN');

        if (! is_string($repository) || $repository === '') {
            throw new RuntimeException('GITHUB_REPOSITORY is required.');
        }

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('GITHUB_TOKEN is required.');
        }

        return new self($httpClient, $repository, $token, $apiBase, $dryRun);
    }

    public function getDefaultBranch(): string
    {
        $data = $this->requestJson('GET', '/repos/' . $this->repository);

        $defaultBranch = $data['default_branch'] ?? null;

        if (! is_string($defaultBranch) || $defaultBranch === '') {
            throw new RuntimeException('GitHub repository payload did not include default_branch.');
        }

        return $defaultBranch;
    }

    /**
     * @param array<string, array{color:string, description:string}> $definitions
     */
    public function ensureLabels(array $definitions): void
    {
        (new GitHubLabelSynchronizer($this->repository, $this->dryRun))
            ->ensureLabels($definitions, fn (string $method, string $path, ?array $payload = null): array => $this->requestJson($method, $path, $payload));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listOpenPullRequests(?string $label = null): array
    {
        if (is_string($label) && $label !== '') {
            try {
                return $this->listOpenPullRequestsByLabel($label);
            } catch (RuntimeException) {
                return array_values(array_filter(
                    $this->listAllOpenPullRequests(),
                    static fn (array $pullRequest): bool => self::pullRequestHasLabel($pullRequest, $label)
                ));
            }
        }

        return $this->listAllOpenPullRequests();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listAllOpenPullRequests(): array
    {
        $page = 1;
        $pullRequests = [];

        do {
            $chunk = $this->requestJson(
                'GET',
                sprintf('/repos/%s/pulls?state=open&per_page=100&page=%d', $this->repository, $page)
            );

            if (! array_is_list($chunk)) {
                throw new RuntimeException('GitHub returned a non-list payload for pull request listing.');
            }

            foreach ($chunk as $pullRequest) {
                if (is_array($pullRequest)) {
                    $pullRequests[] = $pullRequest;
                }
            }

            $page++;
        } while (count($chunk) === 100);

        return $pullRequests;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listOpenPullRequestsByLabel(string $label): array
    {
        $page = 1;
        $pullRequestNumbers = [];

        do {
            $chunk = $this->requestJson(
                'GET',
                sprintf(
                    '/repos/%s/issues?state=open&per_page=100&labels=%s&page=%d',
                    $this->repository,
                    rawurlencode($label),
                    $page
                )
            );

            if (! array_is_list($chunk)) {
                throw new RuntimeException('GitHub returned a non-list payload for labeled pull request listing.');
            }

            foreach ($chunk as $candidate) {
                if (! is_array($candidate) || ! isset($candidate['pull_request'])) {
                    continue;
                }

                $number = $candidate['number'] ?? null;

                if (is_int($number) && $number > 0) {
                    $pullRequestNumbers[$number] = true;
                }
            }

            $page++;
        } while (count($chunk) === 100);

        $pullRequests = [];

        foreach (array_keys($pullRequestNumbers) as $number) {
            $pullRequest = $this->getPullRequest((int) $number);

            if (self::pullRequestHasLabel($pullRequest, $label)) {
                $pullRequests[] = $pullRequest;
            }
        }

        return $pullRequests;
    }

    /**
     * @param array<string, mixed> $pullRequest
     */
    private static function pullRequestHasLabel(array $pullRequest, string $label): bool
    {
        foreach ((array) ($pullRequest['labels'] ?? []) as $entry) {
            if ((string) ($entry['name'] ?? '') === $label) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPullRequest(int $number): array
    {
        return $this->requestJson('GET', sprintf('/repos/%s/pulls/%d', $this->repository, $number));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listOpenIssues(?string $label = null): array
    {
        $page = 1;
        $issues = [];

        do {
            $query = sprintf('/repos/%s/issues?state=open&per_page=100&page=%d', $this->repository, $page);

            if (is_string($label) && $label !== '') {
                $query .= '&labels=' . rawurlencode($label);
            }

            $chunk = $this->requestJson('GET', $query);

            if (! array_is_list($chunk)) {
                throw new RuntimeException('GitHub returned a non-list payload for issue listing.');
            }

            foreach ($chunk as $issue) {
                if (! is_array($issue) || isset($issue['pull_request'])) {
                    continue;
                }

                $issues[] = $issue;
            }

            $page++;
        } while (count($chunk) === 100);

        return $issues;
    }

    /**
     * @param list<string> $labels
     * @return array<string, mixed>
     */
    public function createIssue(string $title, string $body, array $labels = []): array
    {
        $labels = LabelHelper::normalizeList($labels);

        if ($this->dryRun) {
            fwrite(STDOUT, sprintf("[dry-run] Create issue (%s)\n", $title));
            return ['number' => 0, 'title' => $title];
        }

        return $this->requestJson('POST', '/repos/' . $this->repository . '/issues', [
            'title' => $title,
            'body' => $body,
            'labels' => $labels,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function updateIssue(int $number, string $title, string $body): array
    {
        if ($this->dryRun) {
            fwrite(STDOUT, sprintf("[dry-run] Update issue #%d (%s)\n", $number, $title));
            return ['number' => $number];
        }

        return $this->requestJson('PATCH', sprintf('/repos/%s/issues/%d', $this->repository, $number), [
            'title' => $title,
            'body' => $body,
        ]);
    }

    public function closeIssue(int $number, ?string $comment = null): void
    {
        if ($this->dryRun) {
            fwrite(STDOUT, sprintf("[dry-run] Close issue #%d\n", $number));

            if (is_string($comment) && $comment !== '') {
                fwrite(STDOUT, sprintf("[dry-run] Comment on issue #%d: %s\n", $number, $comment));
            }

            return;
        }

        if (is_string($comment) && $comment !== '') {
            $this->requestJson('POST', sprintf('/repos/%s/issues/%d/comments', $this->repository, $number), [
                'body' => $comment,
            ]);
        }

        $this->requestJson('PATCH', sprintf('/repos/%s/issues/%d', $this->repository, $number), [
            'state' => 'closed',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function createPullRequest(string $title, string $head, string $base, string $body, bool $draft): array
    {
        if ($this->dryRun) {
            fwrite(STDOUT, sprintf("[dry-run] Create PR %s -> %s (%s)\n", $head, $base, $title));
            return ['number' => 0, 'node_id' => '', 'draft' => $draft];
        }

        return $this->requestJson('POST', '/repos/' . $this->repository . '/pulls', [
            'title' => $title,
            'head' => $head,
            'base' => $base,
            'body' => $body,
            'draft' => $draft,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function updatePullRequest(int $number, string $title, string $body): array
    {
        if ($this->dryRun) {
            fwrite(STDOUT, sprintf("[dry-run] Update PR #%d (%s)\n", $number, $title));
            return ['number' => $number];
        }

        return $this->requestJson('PATCH', sprintf('/repos/%s/pulls/%d', $this->repository, $number), [
            'title' => $title,
            'body' => $body,
        ]);
    }

    public function closePullRequest(int $number, ?string $comment = null): void
    {
        if ($this->dryRun) {
            fwrite(STDOUT, sprintf("[dry-run] Close PR #%d\n", $number));

            if (is_string($comment) && $comment !== '') {
                fwrite(STDOUT, sprintf("[dry-run] Comment on PR #%d: %s\n", $number, $comment));
            }

            return;
        }

        if (is_string($comment) && $comment !== '') {
            $this->requestJson('POST', sprintf('/repos/%s/issues/%d/comments', $this->repository, $number), [
                'body' => $comment,
            ]);
        }

        $this->requestJson('PATCH', sprintf('/repos/%s/issues/%d', $this->repository, $number), [
            'state' => 'closed',
        ]);
    }

    /**
     * @param list<string> $labels
     */
    public function setLabels(int $issueNumber, array $labels): void
    {
        $labels = LabelHelper::normalizeList($labels);

        if ($this->dryRun) {
            fwrite(STDOUT, sprintf("[dry-run] Set labels on PR #%d: %s\n", $issueNumber, implode(', ', $labels)));
            return;
        }

        $this->requestJson('PUT', sprintf('/repos/%s/issues/%d/labels', $this->repository, $issueNumber), [
            'labels' => $labels,
        ]);
    }

    public function markReadyForReview(string $nodeId): void
    {
        if ($this->dryRun) {
            fwrite(STDOUT, sprintf("[dry-run] Mark PR %s ready for review\n", $nodeId));
            return;
        }

        $this->graphql('mutation($pullRequestId: ID!) { markPullRequestReadyForReview(input: {pullRequestId: $pullRequestId}) { pullRequest { number } } }', [
            'pullRequestId' => $nodeId,
        ]);
    }

    public function convertToDraft(string $nodeId): void
    {
        if ($this->dryRun) {
            fwrite(STDOUT, sprintf("[dry-run] Convert PR %s to draft\n", $nodeId));
            return;
        }

        $this->graphql('mutation($pullRequestId: ID!) { convertPullRequestToDraft(input: {pullRequestId: $pullRequestId}) { pullRequest { number } } }', [
            'pullRequestId' => $nodeId,
        ]);
    }

    /**
     * @param array<string, mixed> $variables
     */
    private function graphql(string $query, array $variables): void
    {
        $this->requestJson('POST', '/graphql', [
            'query' => $query,
            'variables' => $variables,
        ], true);
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>|list<mixed>
     */
    private function requestJson(string $method, string $path, ?array $payload = null, bool $graphql = false): array
    {
        try {
            $response = $this->httpClient->requestWithOptions(
                $method,
                rtrim($this->apiBase, '/') . $path,
                $this->headers($graphql),
                $payload,
                null,
                false,
                ['max_body_bytes' => HttpClient::DEFAULT_MAX_JSON_BODY_BYTES],
            );
        } catch (RuntimeException $exception) {
            if (str_contains($exception->getMessage(), 'exceeded the configured byte limit')) {
                throw new RuntimeException(sprintf(
                    'GitHub API %s %s exceeded the maximum JSON response size of %d bytes.',
                    $method,
                    $path,
                    HttpClient::DEFAULT_MAX_JSON_BODY_BYTES
                ), previous: $exception);
            }

            throw $exception;
        }

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new HttpStatusRuntimeException(
                $response['status'],
                sprintf('GitHub API %s %s failed with status %d: %s', $method, $path, $response['status'], $response['body'])
            );
        }

        if (strlen($response['body']) > HttpClient::DEFAULT_MAX_JSON_BODY_BYTES) {
            throw new RuntimeException(sprintf(
                'GitHub API %s %s exceeded the maximum JSON response size of %d bytes.',
                $method,
                $path,
                HttpClient::DEFAULT_MAX_JSON_BODY_BYTES
            ));
        }

        $decoded = json_decode($response['body'], true);

        if (! is_array($decoded)) {
            throw new RuntimeException(sprintf('GitHub API %s %s returned invalid JSON.', $method, $path));
        }

        if ($graphql && isset($decoded['errors'])) {
            throw new RuntimeException(sprintf('GitHub GraphQL error for %s: %s', $path, json_encode($decoded['errors'], JSON_THROW_ON_ERROR)));
        }

        return $decoded;
    }

    /**
     * @return array<string, string>
     */
    private function headers(bool $graphql): array
    {
        return [
            'Accept' => $graphql ? 'application/json' : 'application/vnd.github+json',
            'Authorization' => 'Bearer ' . $this->token,
            'X-GitHub-Api-Version' => '2022-11-28',
        ];
    }
}
