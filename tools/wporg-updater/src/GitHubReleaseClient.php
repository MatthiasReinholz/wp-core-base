<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use DateTimeImmutable;
use RuntimeException;

final class GitHubReleaseClient implements GitHubReleaseSource
{
    public function __construct(
        private readonly HttpClient $httpClient,
        private readonly string $apiBase = 'https://api.github.com',
    ) {
    }

    /**
     * @param array<string, mixed> $dependency
     * @return list<array<string, mixed>>
     */
    public function fetchStableReleases(array $dependency): array
    {
        $repository = $this->repository($dependency);
        $headers = $this->headers($dependency);
        $releases = [];
        $page = 1;

        do {
            try {
                $chunk = $this->requestJson(
                    sprintf('/repos/%s/releases?per_page=100&page=%d', $this->repositoryPath($repository), $page),
                    $headers
                );
            } catch (HttpStatusRuntimeException $exception) {
                if (! isset($headers['Authorization']) && $exception->status() === 404) {
                    throw new RuntimeException(sprintf(
                        'GitHub releases for %s were not accessible without authentication. If the repository is private, set source_config.github_token_env. If it is public, verify source_config.github_repository.',
                        $repository
                    ), previous: $exception);
                }

                throw $exception;
            }

            if (! array_is_list($chunk)) {
                throw new RuntimeException(sprintf('GitHub releases payload for %s was not a list.', $repository));
            }

            foreach ($chunk as $release) {
                if (! is_array($release)) {
                    continue;
                }

                if ((bool) ($release['draft'] ?? false) || (bool) ($release['prerelease'] ?? false)) {
                    continue;
                }

                $release['normalized_version'] = $this->normalizeVersion((string) ($release['tag_name'] ?? ''), $dependency);
                $releases[] = $release;
            }

            $page++;
        } while (count($chunk) === 100);

        if ($releases === []) {
            throw new RuntimeException(sprintf('No published stable GitHub releases were found for %s.', $repository));
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
        foreach (['published_at', 'created_at'] as $field) {
            $value = $release[$field] ?? null;

            if (is_string($value) && $value !== '') {
                return (new DateTimeImmutable($value))->format(DATE_ATOM);
            }
        }

        throw new RuntimeException('GitHub release did not include published_at or created_at.');
    }

    public function releaseUrl(array $release, string $repository): string
    {
        $releaseUrl = $release['html_url'] ?? null;

        if (is_string($releaseUrl) && $releaseUrl !== '') {
            return $releaseUrl;
        }

        return sprintf('https://github.com/%s/releases', $repository);
    }

    public function issuesUrl(string $repository): string
    {
        return sprintf('https://github.com/%s/issues', $repository);
    }

    public function releaseNotesMarkdown(array $release, string $targetVersion): string
    {
        $body = trim((string) ($release['body'] ?? ''));

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
        $selectedAsset = $this->selectReleaseAsset($release, $dependency);

        if ($selectedAsset !== null) {
            $apiUrl = (string) ($selectedAsset['url'] ?? '');

            if ($apiUrl === '') {
                throw new RuntimeException(sprintf(
                    'GitHub release asset metadata for %s is missing the API URL.',
                    $this->repository($dependency)
                ));
            }

            $this->downloadAssetFromApi($apiUrl, $dependency, $destination);
            return;
        }

        $zipballUrl = $release['zipball_url'] ?? null;

        if (! is_string($zipballUrl) || $zipballUrl === '') {
            throw new RuntimeException(sprintf(
                'GitHub release for %s did not include a zipball_url.',
                $this->repository($dependency)
            ));
        }

        $this->httpClient->downloadToFileWithOptions($zipballUrl, $destination, $this->headers($dependency), [
            'allowed_redirect_hosts' => $this->allowedDownloadHosts(),
            'strip_auth_on_cross_origin_redirect' => true,
            'max_download_bytes' => 512 * 1024 * 1024,
        ]);
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
    public function repository(array $dependency): string
    {
        $repository = $dependency['source_config']['github_repository'] ?? null;

        if (! is_string($repository) || $repository === '') {
            throw new RuntimeException(sprintf('Dependency %s is missing source_config.github_repository.', (string) ($dependency['slug'] ?? 'unknown')));
        }

        return $repository;
    }

    /**
     * @param array<string, mixed> $dependency
     * @return array<string, string>
     */
    private function headers(array $dependency): array
    {
        $headers = [
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
        ];

        $tokenEnv = $dependency['source_config']['github_token_env'] ?? null;

        if (! is_string($tokenEnv) || $tokenEnv === '') {
            return $headers;
        }

        $token = getenv($tokenEnv);

        if (! is_string($token) || $token === '') {
            throw new RuntimeException(sprintf(
                'Dependency %s requires the %s environment variable for GitHub release access.',
                (string) $dependency['slug'],
                $tokenEnv
            ));
        }

        $headers['Authorization'] = 'Bearer ' . $token;
        return $headers;
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, mixed>|list<mixed>
     */
    private function requestJson(string $path, array $headers): array
    {
        $response = $this->httpClient->requestWithOptions(
            'GET',
            rtrim($this->apiBase, '/') . $path,
            $headers,
            null,
            null,
            false,
            ['max_body_bytes' => 5 * 1024 * 1024]
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new HttpStatusRuntimeException($response['status'], sprintf(
                'GitHub source API GET %s failed with status %d: %s',
                $path,
                $response['status'],
                $response['body']
            ));
        }

        $decoded = json_decode($response['body'], true);

        if (! is_array($decoded)) {
            throw new RuntimeException(sprintf('GitHub source API GET %s returned invalid JSON.', $path));
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $release
     * @param array<string, mixed> $dependency
     * @return array<string, mixed>|null
     */
    private function selectReleaseAsset(array $release, array $dependency): ?array
    {
        $assetPattern = $dependency['source_config']['github_release_asset_pattern'] ?? null;

        if (! is_string($assetPattern) || $assetPattern === '') {
            return null;
        }

        $assets = $release['assets'] ?? null;

        if (! is_array($assets)) {
            throw new RuntimeException(sprintf(
                'GitHub release for %s did not include an assets list.',
                $this->repository($dependency)
            ));
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

        throw new RuntimeException(sprintf(
            'No GitHub release asset matching "%s" was found for %s.',
            $assetPattern,
            $this->repository($dependency)
        ));
    }

    /**
     * @param array<string, mixed> $dependency
     */
    private function downloadAssetFromApi(string $assetApiUrl, array $dependency, string $destination): void
    {
        $headers = $this->headers($dependency);
        $headers['Accept'] = 'application/octet-stream';

        $this->httpClient->downloadToFileWithOptions($assetApiUrl, $destination, $headers, [
            'allowed_redirect_hosts' => $this->allowedDownloadHosts(),
            'strip_auth_on_cross_origin_redirect' => true,
            'max_download_bytes' => 512 * 1024 * 1024,
        ]);
    }

    private function repositoryPath(string $repository): string
    {
        [$owner, $repo] = array_pad(explode('/', $repository, 2), 2, null);

        if (! is_string($owner) || $owner === '' || ! is_string($repo) || $repo === '') {
            throw new RuntimeException(sprintf('Invalid GitHub repository identifier: %s', $repository));
        }

        return rawurlencode($owner) . '/' . rawurlencode($repo);
    }

    /**
     * @return list<string>
     */
    private function allowedDownloadHosts(): array
    {
        $apiHost = strtolower((string) parse_url($this->apiBase, PHP_URL_HOST));

        if ($apiHost === 'api.github.com') {
            return [
                'api.github.com',
                'github.com',
                'codeload.github.com',
                'objects.githubusercontent.com',
                'release-assets.githubusercontent.com',
            ];
        }

        return $apiHost === '' ? [] : [$apiHost];
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
            'GitHub release tag "%s" for %s does not contain a parseable version.',
            $tagName,
            $this->repository($dependency)
        ));
    }
}
