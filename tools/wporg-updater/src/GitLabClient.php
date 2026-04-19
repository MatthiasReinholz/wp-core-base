<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class GitLabClient implements AutomationClient
{
    public function __construct(
        private readonly HttpClient $httpClient,
        private readonly string $project,
        private readonly string $token,
        private readonly string $apiBase = 'https://gitlab.com/api/v4',
        private readonly bool $dryRun = false,
    ) {
    }

    public static function fromEnvironment(HttpClient $httpClient, string $apiBase, bool $dryRun = false): self
    {
        $project = getenv('GITLAB_PROJECT_ID');

        if (! is_string($project) || $project === '') {
            $project = getenv('CI_PROJECT_ID');
        }

        if (! is_string($project) || $project === '') {
            $project = getenv('GITLAB_PROJECT_PATH');
        }

        if (! is_string($project) || $project === '') {
            $project = getenv('CI_PROJECT_PATH');
        }

        $token = getenv('GITLAB_TOKEN');

        if (! is_string($project) || $project === '') {
            throw new RuntimeException('GITLAB_PROJECT_ID, CI_PROJECT_ID, GITLAB_PROJECT_PATH, or CI_PROJECT_PATH is required.');
        }

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('GITLAB_TOKEN is required.');
        }

        return new self($httpClient, $project, $token, $apiBase, $dryRun);
    }

    public function getDefaultBranch(): string
    {
        $project = $this->requestJson('GET', $this->projectPath());
        $defaultBranch = $project['default_branch'] ?? null;

        if (! is_string($defaultBranch) || $defaultBranch === '') {
            throw new RuntimeException('GitLab project payload did not include default_branch.');
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
            fwrite(STDOUT, "[dry-run] Ensuring GitLab labels\n");
            return;
        }

        foreach ($definitions as $name => $definition) {
            $path = sprintf('%s/labels/%s', $this->projectPath(), rawurlencode($name));

            try {
                $this->requestJson('PUT', $path, [
                    'new_name' => $name,
                    'color' => '#' . ltrim($definition['color'], '#'),
                    'description' => $definition['description'],
                ]);
            } catch (HttpStatusRuntimeException $exception) {
                if ($exception->status() !== 404) {
                    throw $exception;
                }

                $this->requestJson('POST', $this->projectPath() . '/labels', [
                    'name' => $name,
                    'color' => '#' . ltrim($definition['color'], '#'),
                    'description' => $definition['description'],
                ]);
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listOpenPullRequests(?string $label = null): array
    {
        $page = 1;
        $pullRequests = [];

        do {
            $query = sprintf(
                '%s/merge_requests?state=opened&scope=all&per_page=100&page=%d&with_labels_details=true',
                $this->projectPath(),
                $page
            );

            if (is_string($label) && $label !== '') {
                $query .= '&labels=' . rawurlencode($label);
            }

            $chunk = $this->requestJson('GET', $query);

            if (! array_is_list($chunk)) {
                throw new RuntimeException('GitLab returned a non-list payload for merge request listing.');
            }

            foreach ($chunk as $pullRequest) {
                if (is_array($pullRequest)) {
                    $pullRequests[] = $this->normalizeMergeRequest($pullRequest);
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
        return $this->normalizeMergeRequest(
            $this->requestJson('GET', sprintf('%s/merge_requests/%d', $this->projectPath(), $number))
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listOpenIssues(?string $label = null): array
    {
        $page = 1;
        $issues = [];

        do {
            $query = sprintf('%s/issues?state=opened&per_page=100&page=%d', $this->projectPath(), $page);

            if (is_string($label) && $label !== '') {
                $query .= '&labels=' . rawurlencode($label);
            }

            $chunk = $this->requestJson('GET', $query);

            if (! array_is_list($chunk)) {
                throw new RuntimeException('GitLab returned a non-list payload for issue listing.');
            }

            foreach ($chunk as $issue) {
                if (is_array($issue)) {
                    $issues[] = $this->normalizeIssue($issue);
                }
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

        return $this->normalizeIssue($this->requestJson('POST', $this->projectPath() . '/issues', [
            'title' => $title,
            'description' => $body,
            'labels' => implode(',', $labels),
        ]));
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

        return $this->normalizeIssue($this->requestJson('PUT', sprintf('%s/issues/%d', $this->projectPath(), $number), [
            'title' => $title,
            'description' => $body,
        ]));
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
            $this->requestJson('POST', sprintf('%s/issues/%d/notes', $this->projectPath(), $number), [
                'body' => $comment,
            ]);
        }

        $this->requestJson('PUT', sprintf('%s/issues/%d', $this->projectPath(), $number), [
            'state_event' => 'close',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function createPullRequest(string $title, string $head, string $base, string $body, bool $draft): array
    {
        if ($this->dryRun) {
            fwrite(STDOUT, sprintf("[dry-run] Create MR %s -> %s (%s)\n", $head, $base, $title));
            return ['number' => 0, 'draft' => $draft];
        }

        return $this->normalizeMergeRequest($this->requestJson('POST', $this->projectPath() . '/merge_requests', [
            'title' => $this->draftTitle($title, $draft),
            'source_branch' => $head,
            'target_branch' => $base,
            'description' => $body,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function updatePullRequest(int $number, string $title, string $body): array
    {
        if ($this->dryRun) {
            fwrite(STDOUT, sprintf("[dry-run] Update MR #%d (%s)\n", $number, $title));
            return ['number' => $number];
        }

        $current = $this->getPullRequest($number);

        return $this->normalizeMergeRequest($this->requestJson('PUT', sprintf('%s/merge_requests/%d', $this->projectPath(), $number), [
            'title' => $this->draftTitle($title, (bool) ($current['draft'] ?? false)),
            'description' => $body,
        ]));
    }

    public function closePullRequest(int $number, ?string $comment = null): void
    {
        if ($this->dryRun) {
            fwrite(STDOUT, sprintf("[dry-run] Close MR #%d\n", $number));

            if (is_string($comment) && $comment !== '') {
                fwrite(STDOUT, sprintf("[dry-run] Comment on MR #%d: %s\n", $number, $comment));
            }

            return;
        }

        if (is_string($comment) && $comment !== '') {
            $this->requestJson('POST', sprintf('%s/merge_requests/%d/notes', $this->projectPath(), $number), [
                'body' => $comment,
            ]);
        }

        $this->requestJson('PUT', sprintf('%s/merge_requests/%d', $this->projectPath(), $number), [
            'state_event' => 'close',
        ]);
    }

    /**
     * @param list<string> $labels
     */
    public function setIssueLabels(int $number, array $labels): void
    {
        $labels = LabelHelper::normalizeList($labels);

        if ($this->dryRun) {
            fwrite(STDOUT, sprintf("[dry-run] Set labels on issue #%d: %s\n", $number, implode(', ', $labels)));
            return;
        }

        $this->requestJson('PUT', sprintf('%s/issues/%d', $this->projectPath(), $number), [
            'labels' => implode(',', $labels),
        ]);
    }

    /**
     * @param list<string> $labels
     */
    public function setPullRequestLabels(int $number, array $labels): void
    {
        $labels = LabelHelper::normalizeList($labels);

        if ($this->dryRun) {
            fwrite(STDOUT, sprintf("[dry-run] Set labels on MR #%d: %s\n", $number, implode(', ', $labels)));
            return;
        }

        $this->requestJson('PUT', sprintf('%s/merge_requests/%d', $this->projectPath(), $number), [
            'labels' => implode(',', $labels),
        ]);
    }

    public function convertToDraft(int $number): void
    {
        $pullRequest = $this->getPullRequest($number);
        $title = (string) ($pullRequest['title'] ?? '');

        if ($title === '' || (bool) ($pullRequest['draft'] ?? false)) {
            return;
        }

        if ($this->dryRun) {
            fwrite(STDOUT, sprintf("[dry-run] Convert MR #%d to draft\n", $number));
            return;
        }

        $this->requestJson('PUT', sprintf('%s/merge_requests/%d', $this->projectPath(), $number), [
            'title' => $this->draftTitle($title, true),
        ]);
    }

    public function markReadyForReview(int $number): void
    {
        $pullRequest = $this->getPullRequest($number);
        $title = (string) ($pullRequest['title'] ?? '');

        if ($title === '' || ! (bool) ($pullRequest['draft'] ?? false)) {
            return;
        }

        if ($this->dryRun) {
            fwrite(STDOUT, sprintf("[dry-run] Mark MR #%d ready for review\n", $number));
            return;
        }

        $this->requestJson('PUT', sprintf('%s/merge_requests/%d', $this->projectPath(), $number), [
            'title' => $this->draftTitle($title, false),
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizeMergeRequest(array $payload): array
    {
        $iid = (int) ($payload['iid'] ?? 0);

        if ($iid <= 0) {
            throw new RuntimeException('GitLab merge request payload did not include iid.');
        }

        $labels = [];

        foreach ((array) ($payload['labels'] ?? []) as $label) {
            if (is_array($label)) {
                $labels[] = ['name' => (string) ($label['name'] ?? '')];
                continue;
            }

            if (is_string($label) && $label !== '') {
                $labels[] = ['name' => $label];
            }
        }

        $title = (string) ($payload['title'] ?? '');
        $draft = (bool) ($payload['draft'] ?? false) || $this->isDraftTitle($title);
        $sourceRepository = $this->mergeRequestRepositoryIdentity($payload, 'source_project_id');
        $targetRepository = $this->mergeRequestRepositoryIdentity($payload, 'target_project_id');

        $payload['number'] = $iid;
        $payload['body'] = (string) ($payload['description'] ?? '');
        $payload['head'] = [
            'ref' => (string) ($payload['source_branch'] ?? ''),
            'repo' => ['full_name' => $sourceRepository],
        ];
        $payload['base'] = [
            'ref' => (string) ($payload['target_branch'] ?? ''),
            'repo' => ['full_name' => $targetRepository],
        ];
        $payload['labels'] = $labels;
        $payload['draft'] = $draft;
        $payload['title'] = $title;

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizeIssue(array $payload): array
    {
        $iid = (int) ($payload['iid'] ?? 0);

        if ($iid <= 0) {
            throw new RuntimeException('GitLab issue payload did not include iid.');
        }

        $payload['number'] = $iid;

        return $payload;
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>|list<mixed>
     */
    private function requestJson(string $method, string $path, ?array $payload = null): array
    {
        $response = $payload === null
            ? $this->httpClient->request(
                $method,
                rtrim($this->apiBase, '/') . $path,
                $this->headers()
            )
            : $this->httpClient->request(
                $method,
                rtrim($this->apiBase, '/') . $path,
                $this->headers(),
                null,
                http_build_query($payload)
            );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new HttpStatusRuntimeException(
                $response['status'],
                sprintf(
                    'GitLab API %s %s failed with status %d: %s',
                    $method,
                    $path,
                    $response['status'],
                    OutputRedactor::redactHttpBody($response['body'])
                )
            );
        }

        $decoded = json_decode($response['body'], true);

        if (! is_array($decoded)) {
            throw new RuntimeException(sprintf('GitLab API %s %s returned invalid JSON.', $method, $path));
        }

        return $decoded;
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Accept' => 'application/json',
            'Content-Type' => 'application/x-www-form-urlencoded',
            'PRIVATE-TOKEN' => $this->token,
        ];
    }

    private function projectPath(): string
    {
        return '/projects/' . rawurlencode($this->project);
    }

    private function draftTitle(string $title, bool $draft): string
    {
        $normalized = preg_replace('/^(?:Draft:\s*|\[Draft\]\s*)/i', '', trim($title)) ?? trim($title);

        return $draft ? 'Draft: ' . $normalized : $normalized;
    }

    private function isDraftTitle(string $title): bool
    {
        return preg_match('/^(?:Draft:\s*|\[Draft\]\s*)/i', trim($title)) === 1;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function mergeRequestRepositoryIdentity(array $payload, string $projectField): string
    {
        $projectId = $payload[$projectField] ?? null;

        if (is_int($projectId) || (is_string($projectId) && trim($projectId) !== '')) {
            return 'gitlab-project:' . strtolower(trim((string) $projectId));
        }

        $reference = $payload['references']['full'] ?? null;

        if (is_string($reference) && str_contains($reference, '!')) {
            return 'gitlab-project:' . strtolower(trim((string) strstr($reference, '!', true)));
        }

        return 'gitlab-project:' . strtolower(trim($this->project));
    }
}
