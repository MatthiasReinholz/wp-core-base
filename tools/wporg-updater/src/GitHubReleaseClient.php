<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use DateTimeImmutable;
use RuntimeException;

final class GitHubReleaseClient
{
    public function __construct(
        private readonly HttpClient $httpClient,
        private readonly string $apiBase = 'https://api.github.com',
    ) {
    }

    /**
     * @param array<string, mixed> $pluginConfig
     * @return list<array<string, mixed>>
     */
    public function fetchStableReleases(array $pluginConfig): array
    {
        $repository = (string) $pluginConfig['github_repository'];
        $releases = [];
        $page = 1;

        do {
            $chunk = $this->requestJson(
                sprintf('/repos/%s/releases?per_page=100&page=%d', $this->repositoryPath($repository), $page),
                $this->headers($pluginConfig)
            );

            if (! array_is_list($chunk)) {
                throw new RuntimeException(sprintf('GitHub releases payload for %s was not a list.', $repository));
            }

            foreach ($chunk as $release) {
                if (is_array($release)) {
                    $releases[] = $release;
                }
            }

            $page++;
        } while (count($chunk) === 100);

        $stableReleases = array_values(array_filter($releases, static function (array $release): bool {
            return ! (bool) ($release['draft'] ?? false) && ! (bool) ($release['prerelease'] ?? false);
        }));

        if ($stableReleases === []) {
            throw new RuntimeException(sprintf('No published stable GitHub releases were found for %s.', $repository));
        }

        foreach ($stableReleases as &$release) {
            $release['normalized_version'] = $this->normalizeVersion((string) ($release['tag_name'] ?? ''), $pluginConfig);
        }
        unset($release);

        usort($stableReleases, static function (array $left, array $right): int {
            return version_compare((string) $right['normalized_version'], (string) $left['normalized_version']);
        });

        return $stableReleases;
    }

    /**
     * @param array<string, mixed> $pluginConfig
     * @return array<string, mixed>
     */
    public function fetchLatestStableRelease(array $pluginConfig): array
    {
        return $this->fetchStableReleases($pluginConfig)[0];
    }

    /**
     * @param array<string, mixed> $pluginConfig
     */
    public function latestVersion(array $release, array $pluginConfig): string
    {
        $normalized = $release['normalized_version'] ?? null;

        if (is_string($normalized) && $normalized !== '') {
            return $normalized;
        }

        return $this->normalizeVersion((string) ($release['tag_name'] ?? ''), $pluginConfig);
    }

    public function latestReleaseAt(array $release): string
    {
        foreach (['published_at', 'created_at'] as $field) {
            $value = $release[$field] ?? null;

            if (! is_string($value) || $value === '') {
                continue;
            }

            return (new DateTimeImmutable($value))->format(DATE_ATOM);
        }

        throw new RuntimeException('GitHub release did not include published_at or created_at.');
    }

    /**
     * @param array<string, mixed> $pluginConfig
     */
    public function downloadUrl(array $release, array $pluginConfig): string
    {
        $assetPattern = $pluginConfig['github_release_asset_pattern'] ?? null;

        if (is_string($assetPattern) && $assetPattern !== '') {
            $assets = $release['assets'] ?? null;

            if (! is_array($assets)) {
                throw new RuntimeException(sprintf(
                    'GitHub release for %s did not include an assets list.',
                    (string) $pluginConfig['slug']
                ));
            }

            foreach ($assets as $asset) {
                if (! is_array($asset)) {
                    continue;
                }

                $name = $asset['name'] ?? null;
                $downloadUrl = $asset['browser_download_url'] ?? null;

                if (! is_string($name) || ! is_string($downloadUrl) || $downloadUrl === '') {
                    continue;
                }

                if (fnmatch($assetPattern, $name)) {
                    return $downloadUrl;
                }
            }

            throw new RuntimeException(sprintf(
                'No GitHub release asset matching "%s" was found for %s.',
                $assetPattern,
                (string) $pluginConfig['github_repository']
            ));
        }

        $zipballUrl = $release['zipball_url'] ?? null;

        if (! is_string($zipballUrl) || $zipballUrl === '') {
            throw new RuntimeException(sprintf(
                'GitHub release for %s did not include a zipball_url.',
                (string) $pluginConfig['github_repository']
            ));
        }

        return $zipballUrl;
    }

    public function releaseUrl(array $release, string $repository): string
    {
        $releaseUrl = $release['html_url'] ?? null;

        if (is_string($releaseUrl) && $releaseUrl !== '') {
            return $releaseUrl;
        }

        return sprintf('https://github.com/%s/releases', $repository);
    }

    public function releasesUrl(string $repository): string
    {
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
     * @param array<string, mixed> $pluginConfig
     */
    public function archiveSubdir(array $pluginConfig): string
    {
        $subdir = $pluginConfig['github_archive_subdir'] ?? null;
        return is_string($subdir) ? trim($subdir, '/') : '';
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, mixed>|list<mixed>
     */
    private function requestJson(string $path, array $headers): array
    {
        $response = $this->httpClient->request(
            'GET',
            rtrim($this->apiBase, '/') . $path,
            $headers,
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException(sprintf(
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
     * @param array<string, mixed> $pluginConfig
     * @return array<string, string>
     */
    private function headers(array $pluginConfig): array
    {
        $headers = [
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
        ];

        $token = $pluginConfig['github_token'] ?? null;

        if (is_string($token) && $token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        return $headers;
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
     * @param array<string, mixed> $pluginConfig
     */
    private function normalizeVersion(string $tagName, array $pluginConfig): string
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
            (string) $pluginConfig['github_repository']
        ));
    }
}
