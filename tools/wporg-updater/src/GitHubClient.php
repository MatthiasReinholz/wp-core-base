<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class GitHubClient
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
        $definitions = LabelHelper::normalizeDefinitions($definitions);

        if ($this->dryRun) {
            fwrite(STDOUT, "[dry-run] Ensuring GitHub labels\n");
            return;
        }

        foreach ($definitions as $name => $definition) {
            $encodedName = rawurlencode($name);

            try {
                $this->requestJson('GET', '/repos/' . $this->repository . '/labels/' . $encodedName);
                $this->requestJson('PATCH', '/repos/' . $this->repository . '/labels/' . $encodedName, [
                    'new_name' => $name,
                    'color' => $definition['color'],
                    'description' => $definition['description'],
                ]);
            } catch (RuntimeException) {
                $this->requestJson('POST', '/repos/' . $this->repository . '/labels', [
                    'name' => $name,
                    'color' => $definition['color'],
                    'description' => $definition['description'],
                ]);
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listOpenPullRequests(): array
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
     * @return array<string, mixed>
     */
    public function getPullRequest(int $number): array
    {
        return $this->requestJson('GET', sprintf('/repos/%s/pulls/%d', $this->repository, $number));
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
        $response = $this->httpClient->request(
            $method,
            rtrim($this->apiBase, '/') . $path,
            $this->headers($graphql),
            $payload,
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException(sprintf('GitHub API %s %s failed with status %d: %s', $method, $path, $response['status'], $response['body']));
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
