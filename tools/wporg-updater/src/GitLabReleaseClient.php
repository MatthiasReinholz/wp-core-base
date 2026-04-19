<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use DateTimeImmutable;
use RuntimeException;

final class GitLabReleaseClient
{
    public function __construct(
        private readonly HttpClient $httpClient,
        private readonly string $apiBase = 'https://gitlab.com/api/v4',
    ) {
    }

    /**
     * @param array<string, mixed> $dependency
     * @return list<array<string, mixed>>
     */
    public function fetchStableReleases(array $dependency): array
    {
        $project = $this->project($dependency);
        $headers = $this->headers($dependency);
        $apiBase = $this->apiBase($dependency);
        $releases = [];
        $page = 1;

        do {
            try {
                $chunk = $this->requestJson(
                    $apiBase,
                    sprintf('/projects/%s/releases?per_page=100&page=%d', $this->projectPath($project), $page),
                    $headers
                );
            } catch (HttpStatusRuntimeException $exception) {
                if (! isset($headers['PRIVATE-TOKEN']) && $exception->status() === 404) {
                    throw new RuntimeException(sprintf(
                        'GitLab releases for %s were not accessible without authentication. If the project is private, set source_config.gitlab_token_env. If it is public, verify source_config.gitlab_project.',
                        $project
                    ), previous: $exception);
                }

                throw $exception;
            }

            if (! array_is_list($chunk)) {
                throw new RuntimeException(sprintf('GitLab releases payload for %s was not a list.', $project));
            }

            foreach ($chunk as $release) {
                if (! is_array($release) || (bool) ($release['upcoming_release'] ?? false)) {
                    continue;
                }

                $tagName = (string) ($release['tag_name'] ?? '');

                if ($this->isPreReleaseTag($tagName)) {
                    continue;
                }

                $release['normalized_version'] = $this->normalizeVersion($tagName, $dependency);
                $releases[] = $release;
            }

            $page++;
        } while (count($chunk) === 100);

        if ($releases === []) {
            throw new RuntimeException(sprintf('No published stable GitLab releases were found for %s.', $project));
        }

        usort($releases, static function (array $left, array $right): int {
            return version_compare((string) $right['normalized_version'], (string) $left['normalized_version']);
        });

        return $releases;
    }

    public function latestVersion(array $release, array $dependency): string
    {
        $normalized = $release['normalized_version'] ?? null;

        if (is_string($normalized) && $normalized !== '') {
            return $normalized;
        }

        return $this->normalizeVersion((string) ($release['tag_name'] ?? ''), $dependency);
    }

    public function latestReleaseAt(array $release): string
    {
        foreach (['released_at', 'created_at'] as $field) {
            $value = $release[$field] ?? null;

            if (is_string($value) && $value !== '') {
                return (new DateTimeImmutable($value))->format(DATE_ATOM);
            }
        }

        throw new RuntimeException('GitLab release did not include released_at or created_at.');
    }

    /**
     * @param array<string, mixed> $dependency
     */
    public function releaseUrl(array $release, array $dependency): string
    {
        $tag = (string) ($release['tag_name'] ?? '');

        if ($tag === '') {
            return $this->projectUrl($dependency);
        }

        return rtrim($this->projectUrl($dependency), '/') . '/-/releases/' . rawurlencode($tag);
    }

    /**
     * @param array<string, mixed> $dependency
     */
    public function issuesUrl(array $dependency): string
    {
        return rtrim($this->projectUrl($dependency), '/') . '/-/issues';
    }

    public function releaseNotesMarkdown(array $release, string $targetVersion): string
    {
        $body = trim((string) ($release['description'] ?? ''));

        if ($body !== '') {
            return $body;
        }

        return sprintf('_Release notes unavailable for version %s._', $targetVersion);
    }

    public function markdownToText(string $markdown): string
    {
        $text = preg_replace('/\[([^\]]+)\]\([^)]+\)/', '$1', $markdown) ?? $markdown;
        $text = preg_replace('/[`*_>#-]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return trim(html_entity_decode($text, ENT_QUOTES | ENT_HTML5));
    }

    /**
     * @param array<string, mixed> $dependency
     */
    public function downloadReleaseToFile(array $release, array $dependency, string $destination): void
    {
        $asset = $this->selectReleaseAsset($release, $dependency);
        $assetUrl = $this->assetDownloadUrl($asset);

        if ($assetUrl === null) {
            throw new RuntimeException(sprintf(
                'GitLab release asset metadata for %s is missing a downloadable URL.',
                $this->project($dependency)
            ));
        }

        $this->httpClient->downloadToFileWithOptions($assetUrl, $destination, $this->headers($dependency), [
            'allowed_redirect_hosts' => $this->allowedDownloadHosts($dependency),
            'strip_auth_on_cross_origin_redirect' => true,
            'max_download_bytes' => 512 * 1024 * 1024,
        ]);
    }

    /**
     * @param array<string, mixed> $dependency
     */
    public function assetNameForRelease(array $release, array $dependency): string
    {
        $asset = $this->selectReleaseAsset($release, $dependency);
        $name = $asset['name'] ?? null;

        if (! is_string($name) || $name === '') {
            throw new RuntimeException(sprintf(
                'GitLab release asset metadata for %s is missing an asset name.',
                $this->project($dependency)
            ));
        }

        return $name;
    }

    /**
     * @param array<string, mixed> $dependency
     */
    public function checksumSha256ForAssetPattern(array $release, array $dependency, string $checksumAssetPattern, string $expectedAssetName): ?string
    {
        $checksumAsset = $this->findReleaseAsset($release, $checksumAssetPattern);

        if ($checksumAsset === null) {
            return null;
        }

        $assetUrl = $this->assetDownloadUrl($checksumAsset);

        if ($assetUrl === null) {
            return null;
        }

        $checksumContents = $this->httpClient->getWithOptions($assetUrl, $this->headers($dependency), [
            'allowed_redirect_hosts' => $this->allowedDownloadHosts($dependency),
            'strip_auth_on_cross_origin_redirect' => true,
            'max_body_bytes' => 1024 * 1024,
        ]);

        return FileChecksum::extractSha256ForAsset($checksumContents, $expectedAssetName);
    }

    /**
     * @param array<string, mixed> $dependency
     */
    public function project(array $dependency): string
    {
        $project = $dependency['source_config']['gitlab_project'] ?? null;

        if (! is_string($project) || $project === '') {
            throw new RuntimeException(sprintf('Dependency %s is missing source_config.gitlab_project.', (string) ($dependency['slug'] ?? 'unknown')));
        }

        return $project;
    }

    /**
     * @param array<string, mixed> $dependency
     */
    public function archiveSubdir(array $dependency): string
    {
        return trim((string) ($dependency['archive_subdir'] ?? ''), '/');
    }

    /**
     * @param array<string, mixed> $dependency
     */
    public function projectUrl(array $dependency): string
    {
        $project = $this->project($dependency);
        $segments = array_values(array_filter(explode('/', trim($project, '/')), static fn (string $segment): bool => $segment !== ''));

        if ($segments === []) {
            return $this->webBase($dependency);
        }

        return rtrim($this->webBase($dependency), '/') . '/' . implode('/', array_map('rawurlencode', $segments));
    }

    /**
     * @param array<string, mixed> $dependency
     * @return array<string, string>
     */
    private function headers(array $dependency): array
    {
        $headers = [
            'Accept' => 'application/json',
        ];

        $tokenEnv = $dependency['source_config']['gitlab_token_env'] ?? null;

        if (! is_string($tokenEnv) || $tokenEnv === '') {
            return $headers;
        }

        $token = getenv($tokenEnv);

        if (! is_string($token) || $token === '') {
            throw new RuntimeException(sprintf(
                'Dependency %s requires the %s environment variable for GitLab release access.',
                (string) $dependency['slug'],
                $tokenEnv
            ));
        }

        $headers[$tokenEnv === 'CI_JOB_TOKEN' ? 'JOB-TOKEN' : 'PRIVATE-TOKEN'] = $token;

        return $headers;
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, mixed>|list<mixed>
     */
    private function requestJson(string $apiBase, string $path, array $headers): array
    {
        $response = $this->httpClient->requestWithOptions(
            'GET',
            rtrim($apiBase, '/') . $path,
            $headers,
            null,
            null,
            false,
            ['max_body_bytes' => 5 * 1024 * 1024]
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new HttpStatusRuntimeException($response['status'], sprintf(
                'GitLab source API GET %s failed with status %d: %s',
                $path,
                $response['status'],
                OutputRedactor::redactHttpBody($response['body'])
            ));
        }

        $decoded = json_decode($response['body'], true);

        if (! is_array($decoded)) {
            throw new RuntimeException(sprintf('GitLab source API GET %s returned invalid JSON.', $path));
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $release
     * @param array<string, mixed> $dependency
     * @return array<string, mixed>
     */
    private function selectReleaseAsset(array $release, array $dependency): array
    {
        $assetPattern = $dependency['source_config']['gitlab_release_asset_pattern'] ?? null;

        if (! is_string($assetPattern) || $assetPattern === '') {
            throw new RuntimeException(sprintf(
                '%s must define source_config.gitlab_release_asset_pattern for GitLab release downloads.',
                (string) ($dependency['component_key'] ?? $this->project($dependency))
            ));
        }

        $asset = $this->findReleaseAsset($release, $assetPattern);

        if ($asset !== null) {
            return $asset;
        }

        throw new RuntimeException(sprintf(
            'No GitLab release asset matching "%s" was found for %s.',
            $assetPattern,
            $this->project($dependency)
        ));
    }

    /**
     * @param array<string, mixed> $release
     * @return array<string, mixed>|null
     */
    private function findReleaseAsset(array $release, string $assetPattern): ?array
    {
        if ($assetPattern === '') {
            return null;
        }

        $assets = $release['assets']['links'] ?? null;

        if (! is_array($assets)) {
            return null;
        }

        foreach ($assets as $asset) {
            if (! is_array($asset)) {
                continue;
            }

            $name = $asset['name'] ?? null;

            if (is_string($name) && $name !== '' && fnmatch($assetPattern, $name)) {
                return $asset;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $asset
     */
    private function assetDownloadUrl(array $asset): ?string
    {
        foreach (['direct_asset_url', 'url'] as $field) {
            $value = $asset[$field] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function projectPath(string $project): string
    {
        return rawurlencode($project);
    }

    /**
     * @param array<string, mixed> $dependency
     * @return list<string>
     */
    private function allowedDownloadHosts(array $dependency): array
    {
        $apiHost = strtolower((string) parse_url($this->apiBase($dependency), PHP_URL_HOST));
        $webHost = strtolower((string) parse_url($this->webBase($dependency), PHP_URL_HOST));
        $hosts = array_values(array_filter([$apiHost, $webHost], static fn (string $host): bool => $host !== ''));

        return array_values(array_unique($hosts));
    }

    /**
     * @param array<string, mixed> $dependency
     */
    private function apiBase(array $dependency): string
    {
        $override = $dependency['source_config']['gitlab_api_base'] ?? null;

        if (is_string($override) && trim($override) !== '') {
            return trim($override);
        }

        return $this->apiBase;
    }

    /**
     * @param array<string, mixed> $dependency
     */
    private function webBase(array $dependency): string
    {
        $apiBase = rtrim($this->apiBase($dependency), '/');

        if (preg_match('#^(https?://.+?)/api(?:/v\\d+)?$#', $apiBase, $matches) === 1) {
            return $matches[1];
        }

        return preg_replace('#/api(?:/v\\d+)?$#', '', $apiBase) ?? $apiBase;
    }

    /**
     * @param array<string, mixed> $dependency
     */
    private function normalizeVersion(string $tagName, array $dependency): string
    {
        if (preg_match('/\d+(?:\.\d+)+/', $tagName, $matches) === 1) {
            return $matches[0];
        }

        $trimmed = ltrim($tagName, "vV");

        if ($trimmed !== '' && preg_match('/^\d[\dA-Za-z.\-_]*$/', $trimmed) === 1) {
            return $trimmed;
        }

        throw new RuntimeException(sprintf(
            'GitLab release tag "%s" for %s does not contain a parseable version.',
            $tagName,
            $this->project($dependency)
        ));
    }

    private function isPreReleaseTag(string $tagName): bool
    {
        return preg_match('/(?:^|[^0-9])v?\d+(?:\.\d+)+(?:-[0-9A-Za-z.-]+)\b/', $tagName) === 1;
    }
}
