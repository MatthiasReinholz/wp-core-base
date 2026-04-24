<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater\Cli\Handlers;

use Closure;
use DateTimeImmutable;
use RuntimeException;
use WpOrgPluginUpdater\Cli\CliModeHandler;
use WpOrgPluginUpdater\CommandHelp;
use WpOrgPluginUpdater\Config;
use WpOrgPluginUpdater\GitHubReleaseClient;
use WpOrgPluginUpdater\GitLabReleaseClient;
use WpOrgPluginUpdater\HttpClient;

final class ReleaseAssetInspectionModeHandler implements CliModeHandler
{
    public function __construct(
        private readonly Config $config,
        private readonly HttpClient $httpClient,
        private readonly string $commandPrefix,
        private readonly string $phpCommandPrefix,
        private readonly bool $jsonOutput,
        private readonly Closure $emitJson,
    ) {
    }

    public function supports(string $mode): bool
    {
        return $mode === 'inspect-release-assets';
    }

    /**
     * @param array<string, mixed> $options
     */
    public function handle(string $mode, array $options): int
    {
        if (isset($options['help'])) {
            fwrite(STDOUT, CommandHelp::render($mode, $this->commandPrefix, $this->phpCommandPrefix));
            return 0;
        }

        $source = $this->requiredOption($options, 'source');

        $result = match ($source) {
            'github-release' => $this->inspectGitHubRelease($options),
            'gitlab-release' => $this->inspectGitLabRelease($options),
            default => throw new RuntimeException('inspect-release-assets supports --source=github-release or --source=gitlab-release.'),
        };

        if ($this->jsonOutput) {
            ($this->emitJson)([
                'status' => 'success',
                ...$result,
            ]);
        }

        fwrite(STDOUT, $this->renderText($result));
        return 0;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function inspectGitHubRelease(array $options): array
    {
        $repository = $this->requiredOption($options, 'github-repository');
        $archivePattern = $this->nullableOption($options, 'github-release-asset-pattern');
        $checksumPattern = $this->nullableOption($options, 'checksum-asset-pattern');
        $tokenEnv = $this->nullableOption($options, 'github-token-env');
        $client = new GitHubReleaseClient($this->httpClient, $this->config->githubApiBase());
        $dependency = [
            'slug' => basename($repository),
            'kind' => 'plugin',
            'source' => 'github-release',
            'archive_subdir' => '',
            'source_config' => [
                'github_repository' => $repository,
                'github_release_asset_pattern' => $archivePattern,
                'github_token_env' => $tokenEnv,
            ],
        ];
        $release = $this->selectRelease($client->fetchStableReleases($dependency), $dependency, $options, [$client, 'latestVersion']);
        $assets = $this->githubAssets($release, $archivePattern, $checksumPattern);
        $version = $client->latestVersion($release, $dependency);
        $releaseAt = $client->latestReleaseAt($release);

        return $this->inspectionResult(
            source: 'github-release',
            reference: $repository,
            version: $version,
            releaseAt: $releaseAt,
            tag: (string) ($release['tag_name'] ?? ''),
            url: $client->releaseUrl($release, $repository),
            archivePattern: $archivePattern,
            checksumPattern: $checksumPattern,
            assets: $assets
        );
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function inspectGitLabRelease(array $options): array
    {
        $project = $this->requiredOption($options, 'gitlab-project');
        $archivePattern = $this->nullableOption($options, 'gitlab-release-asset-pattern');
        $checksumPattern = $this->nullableOption($options, 'checksum-asset-pattern');
        $tokenEnv = $this->nullableOption($options, 'gitlab-token-env');
        $apiBase = $this->nullableOption($options, 'gitlab-api-base');
        $client = new GitLabReleaseClient($this->httpClient, $apiBase ?? $this->config->gitlabApiBase());
        $dependency = [
            'slug' => basename($project),
            'kind' => 'plugin',
            'source' => 'gitlab-release',
            'archive_subdir' => '',
            'source_config' => [
                'gitlab_project' => $project,
                'gitlab_release_asset_pattern' => $archivePattern,
                'gitlab_token_env' => $tokenEnv,
                'gitlab_api_base' => $apiBase,
            ],
        ];
        $release = $this->selectRelease($client->fetchStableReleases($dependency), $dependency, $options, [$client, 'latestVersion']);
        $assets = $this->gitlabAssets($release, $archivePattern, $checksumPattern);
        $version = $client->latestVersion($release, $dependency);
        $releaseAt = $client->latestReleaseAt($release);

        return $this->inspectionResult(
            source: 'gitlab-release',
            reference: $project,
            version: $version,
            releaseAt: $releaseAt,
            tag: (string) ($release['tag_name'] ?? ''),
            url: $client->releaseUrl($release, $dependency),
            archivePattern: $archivePattern,
            checksumPattern: $checksumPattern,
            assets: $assets
        );
    }

    /**
     * @param list<array<string, mixed>> $releases
     * @param array<string, mixed> $dependency
     * @param array<string, mixed> $options
     * @param callable(array<string,mixed>,array<string,mixed>):string $versionResolver
     * @return array<string, mixed>
     */
    private function selectRelease(array $releases, array $dependency, array $options, callable $versionResolver): array
    {
        $tag = $this->nullableOption($options, 'tag');
        $version = $this->nullableOption($options, 'version');

        if ($tag === null && $version === null) {
            return $releases[0];
        }

        foreach ($releases as $release) {
            if ($tag !== null && (string) ($release['tag_name'] ?? '') === $tag) {
                return $release;
            }

            if ($version !== null && $versionResolver($release, $dependency) === ltrim($version, 'vV')) {
                return $release;
            }
        }

        throw new RuntimeException(sprintf(
            'No hosted release matched %s.',
            $tag !== null ? '--tag=' . $tag : '--version=' . (string) $version
        ));
    }

    /**
     * @param array<string, mixed> $release
     * @return list<array<string, mixed>>
     */
    private function githubAssets(array $release, ?string $archivePattern, ?string $checksumPattern): array
    {
        $assets = $release['assets'] ?? [];

        if (! is_array($assets)) {
            return [];
        }

        $result = [];

        foreach ($assets as $asset) {
            if (! is_array($asset)) {
                continue;
            }

            $name = (string) ($asset['name'] ?? '');

            if ($name === '') {
                continue;
            }

            $result[] = [
                'name' => $name,
                'api_url' => (string) ($asset['url'] ?? ''),
                'download_url' => (string) ($asset['browser_download_url'] ?? ''),
                'content_type' => (string) ($asset['content_type'] ?? ''),
                'size' => isset($asset['size']) ? (int) $asset['size'] : null,
                'matches_archive_pattern' => $archivePattern !== null && fnmatch($archivePattern, $name),
                'matches_checksum_pattern' => $checksumPattern !== null && fnmatch($checksumPattern, $name),
            ];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $release
     * @return list<array<string, mixed>>
     */
    private function gitlabAssets(array $release, ?string $archivePattern, ?string $checksumPattern): array
    {
        $assets = $release['assets']['links'] ?? [];

        if (! is_array($assets)) {
            return [];
        }

        $result = [];

        foreach ($assets as $asset) {
            if (! is_array($asset)) {
                continue;
            }

            $name = (string) ($asset['name'] ?? '');

            if ($name === '') {
                continue;
            }

            $result[] = [
                'name' => $name,
                'api_url' => '',
                'download_url' => (string) ($asset['direct_asset_url'] ?? $asset['url'] ?? ''),
                'content_type' => (string) ($asset['link_type'] ?? ''),
                'size' => null,
                'matches_archive_pattern' => $archivePattern !== null && fnmatch($archivePattern, $name),
                'matches_checksum_pattern' => $checksumPattern !== null && fnmatch($checksumPattern, $name),
            ];
        }

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $assets
     * @return array<string, mixed>
     */
    private function inspectionResult(
        string $source,
        string $reference,
        string $version,
        string $releaseAt,
        string $tag,
        string $url,
        ?string $archivePattern,
        ?string $checksumPattern,
        array $assets,
    ): array {
        $archiveMatches = array_values(array_filter(
            $assets,
            static fn (array $asset): bool => ($asset['matches_archive_pattern'] ?? false) === true
        ));
        $checksumMatches = array_values(array_filter(
            $assets,
            static fn (array $asset): bool => ($asset['matches_checksum_pattern'] ?? false) === true
        ));
        $warnings = [];

        if ($archivePattern === null) {
            $warnings[] = 'No release asset pattern was provided; packaged ZIP selection cannot be verified.';
        } elseif ($archiveMatches === []) {
            $warnings[] = sprintf('No asset matched archive pattern "%s".', $archivePattern);
        }

        if ($checksumPattern === null) {
            $warnings[] = 'No checksum asset pattern was provided; checksum-sidecar verification cannot be confirmed.';
        } elseif ($checksumMatches === []) {
            $warnings[] = sprintf('No asset matched checksum pattern "%s".', $checksumPattern);
        }

        return [
            'source' => $source,
            'reference' => $reference,
            'version' => $version,
            'tag' => $tag,
            'release_at' => $releaseAt,
            'release_url' => $url,
            'archive_asset_pattern' => $archivePattern,
            'checksum_asset_pattern' => $checksumPattern,
            'verification_mode_default' => $this->config->githubReleaseVerificationMode(),
            'min_release_age_hours_default' => $this->config->managedReleaseMinAgeHours(),
            'min_release_age_satisfied' => $this->releaseAgeSatisfied($releaseAt, $this->config->managedReleaseMinAgeHours()),
            'archive_asset' => $archiveMatches[0] ?? null,
            'checksum_asset' => $checksumMatches[0] ?? null,
            'assets' => $assets,
            'warnings' => $warnings,
        ];
    }

    private function releaseAgeSatisfied(string $releaseAt, int $minAgeHours): bool
    {
        if ($minAgeHours <= 0) {
            return true;
        }

        $releaseTime = (new DateTimeImmutable($releaseAt))->getTimestamp();
        return time() - $releaseTime >= $minAgeHours * 3600;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function renderText(array $result): string
    {
        $lines = [
            'Release asset inspection:',
            sprintf('Source: %s', (string) $result['source']),
            sprintf('Reference: %s', (string) $result['reference']),
            sprintf('Version: %s', (string) $result['version']),
            sprintf('Tag: %s', (string) $result['tag']),
            sprintf('Release: %s', (string) $result['release_url']),
            sprintf('Archive pattern: %s', (string) ($result['archive_asset_pattern'] ?? '(not provided)')),
            sprintf('Checksum pattern: %s', (string) ($result['checksum_asset_pattern'] ?? '(not provided)')),
            '',
            'Assets:',
        ];

        foreach ((array) $result['assets'] as $asset) {
            $lines[] = sprintf(
                '- %s | archive:%s | checksum:%s',
                (string) $asset['name'],
                ($asset['matches_archive_pattern'] ?? false) ? 'yes' : 'no',
                ($asset['matches_checksum_pattern'] ?? false) ? 'yes' : 'no'
            );
        }

        if ($result['warnings'] !== []) {
            $lines[] = '';
            $lines[] = 'Warnings:';

            foreach ((array) $result['warnings'] as $warning) {
                $lines[] = '- ' . (string) $warning;
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param array<string, mixed> $options
     */
    private function requiredOption(array $options, string $name): string
    {
        $value = $this->nullableOption($options, $name);

        if ($value === null) {
            throw new RuntimeException(sprintf('--%s is required.', $name));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function nullableOption(array $options, string $name): ?string
    {
        $value = $options[$name] ?? null;

        if ($value === null || $value === true) {
            return null;
        }

        if (! is_string($value)) {
            throw new RuntimeException(sprintf('--%s must be a string value.', $name));
        }

        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }
}
