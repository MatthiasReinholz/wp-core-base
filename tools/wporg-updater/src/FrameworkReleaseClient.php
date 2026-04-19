<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class FrameworkReleaseClient implements FrameworkReleaseSource
{
    public function __construct(
        private readonly HttpClient $httpClient,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchStableReleases(FrameworkConfig $framework): array
    {
        return match ($framework->releaseSourceProvider()) {
            'gitlab-release' => $this->gitLabReleaseClient($framework)->fetchStableReleases($this->dependencyShape($framework)),
            'github-release' => $this->gitHubReleaseClient($framework)->fetchStableReleases($this->dependencyShape($framework)),
            default => throw new RuntimeException(sprintf(
                'Unsupported framework release source provider: %s',
                $framework->releaseSourceProvider()
            )),
        };
    }

    /**
     * @param array<string, mixed> $release
     * @return array<string, mixed>
     */
    public function releaseData(FrameworkConfig $framework, array $release): array
    {
        $version = $this->latestVersion($framework, $release);
        $releaseNotes = $this->releaseNotesMarkdown($framework, $release, $version);

        if ($releaseNotes === '') {
            throw new RuntimeException(sprintf('Framework release v%s is missing release notes.', $version));
        }

        $missingSections = FrameworkReleaseNotes::missingRequiredSections($releaseNotes);

        if ($missingSections !== []) {
            throw new RuntimeException(sprintf(
                'Framework release v%s is missing required release-note sections: %s.',
                $version,
                implode(', ', $missingSections)
            ));
        }

        return [
            'version' => $version,
            'release_at' => $this->latestReleaseAt($framework, $release),
            'release_url' => $this->releaseUrl($framework, $release),
            'notes_markdown' => $releaseNotes,
            'notes_text' => $this->markdownToText($framework, $releaseNotes),
            'notes_sections' => FrameworkReleaseNotes::parseSections($releaseNotes),
            'release' => $release,
        ];
    }

    /**
     * @param array<string, mixed> $release
     */
    public function downloadReleaseAsset(FrameworkConfig $framework, array $release, string $destination): void
    {
        match ($framework->releaseSourceProvider()) {
            'gitlab-release' => $this->gitLabReleaseClient($framework)->downloadReleaseToFile(
                $release,
                $this->dependencyShape($framework),
                $destination
            ),
            'github-release' => $this->gitHubReleaseClient($framework)->downloadReleaseToFile(
                $release,
                $this->dependencyShape($framework),
                $destination
            ),
            default => throw new RuntimeException(sprintf(
                'Unsupported framework release source provider: %s',
                $framework->releaseSourceProvider()
            )),
        };
    }

    /**
     * @param array<string, mixed> $release
     */
    public function downloadVerifiedReleaseAsset(FrameworkConfig $framework, array $release, string $destination): void
    {
        $checksumPath = $destination . '.sha256';
        $signaturePath = $checksumPath . '.sig';

        try {
            $this->downloadReleaseAsset($framework, $release, $destination);
            $this->downloadAuxiliaryAsset($framework, $release, $framework->checksumAssetName(), $checksumPath);
            $this->downloadAuxiliaryAsset($framework, $release, $framework->checksumSignatureAssetName(), $signaturePath);
            FrameworkReleaseSignature::verifyChecksumFileWithKeyPaths(
                $checksumPath,
                $signaturePath,
                ReleaseSignatureKeyStore::publicKeyPaths($framework)
            );

            $checksumContents = file_get_contents($checksumPath);

            if (! is_string($checksumContents)) {
                throw new RuntimeException(sprintf('Unable to read framework release checksum file: %s', $checksumPath));
            }

            $expectedChecksum = $this->extractChecksum($checksumContents, $framework->assetName());
            $actualChecksum = hash_file('sha256', $destination);

            if (! is_string($actualChecksum) || $actualChecksum === '') {
                throw new RuntimeException(sprintf('Unable to hash framework release archive: %s', $destination));
            }

            if (! hash_equals($expectedChecksum, strtolower($actualChecksum))) {
                throw new RuntimeException(sprintf(
                    'Framework release checksum mismatch. Expected %s but found %s.',
                    $expectedChecksum,
                    strtolower($actualChecksum)
                ));
            }
        } finally {
            if (is_file($signaturePath) && ! unlink($signaturePath)) {
                fwrite(STDERR, sprintf("[warn] Failed to remove temporary signature file %s\n", $signaturePath));
            }

            if (is_file($checksumPath) && ! unlink($checksumPath)) {
                fwrite(STDERR, sprintf("[warn] Failed to remove temporary checksum file %s\n", $checksumPath));
            }
        }
    }

    /**
     * @param array<string, mixed> $release
     */
    private function latestVersion(FrameworkConfig $framework, array $release): string
    {
        return match ($framework->releaseSourceProvider()) {
            'gitlab-release' => $this->gitLabReleaseClient($framework)->latestVersion($release, $this->dependencyShape($framework)),
            'github-release' => $this->gitHubReleaseClient($framework)->latestVersion($release, $this->dependencyShape($framework)),
            default => throw new RuntimeException(sprintf(
                'Unsupported framework release source provider: %s',
                $framework->releaseSourceProvider()
            )),
        };
    }

    /**
     * @param array<string, mixed> $release
     */
    private function latestReleaseAt(FrameworkConfig $framework, array $release): string
    {
        return match ($framework->releaseSourceProvider()) {
            'gitlab-release' => $this->gitLabReleaseClient($framework)->latestReleaseAt($release),
            'github-release' => $this->gitHubReleaseClient($framework)->latestReleaseAt($release),
            default => throw new RuntimeException(sprintf(
                'Unsupported framework release source provider: %s',
                $framework->releaseSourceProvider()
            )),
        };
    }

    /**
     * @param array<string, mixed> $release
     */
    private function releaseUrl(FrameworkConfig $framework, array $release): string
    {
        return match ($framework->releaseSourceProvider()) {
            'gitlab-release' => $this->gitLabReleaseClient($framework)->releaseUrl($release, $this->dependencyShape($framework)),
            'github-release' => $this->gitHubReleaseClient($framework)->releaseUrl($release, $framework->releaseSourceReference()),
            default => throw new RuntimeException(sprintf(
                'Unsupported framework release source provider: %s',
                $framework->releaseSourceProvider()
            )),
        };
    }

    /**
     * @param array<string, mixed> $release
     */
    private function releaseNotesMarkdown(FrameworkConfig $framework, array $release, string $version): string
    {
        return match ($framework->releaseSourceProvider()) {
            'gitlab-release' => trim($this->gitLabReleaseClient($framework)->releaseNotesMarkdown($release, $version)),
            'github-release' => trim($this->gitHubReleaseClient($framework)->releaseNotesMarkdown($release, $version)),
            default => throw new RuntimeException(sprintf(
                'Unsupported framework release source provider: %s',
                $framework->releaseSourceProvider()
            )),
        };
    }

    private function markdownToText(FrameworkConfig $framework, string $markdown): string
    {
        return match ($framework->releaseSourceProvider()) {
            'gitlab-release' => $this->gitLabReleaseClient($framework)->markdownToText($markdown),
            'github-release' => $this->gitHubReleaseClient($framework)->markdownToText($markdown),
            default => throw new RuntimeException(sprintf(
                'Unsupported framework release source provider: %s',
                $framework->releaseSourceProvider()
            )),
        };
    }

    /**
     * @param array<string, mixed> $release
     */
    private function downloadAuxiliaryAsset(FrameworkConfig $framework, array $release, string $assetName, string $destination): void
    {
        $shape = $this->dependencyShape($framework);

        if ($framework->releaseSourceProvider() === 'gitlab-release') {
            $shape['source_config']['gitlab_release_asset_pattern'] = $assetName;
            $this->gitLabReleaseClient($framework)->downloadReleaseToFile($release, $shape, $destination);
            return;
        }

        $shape['source_config']['github_release_asset_pattern'] = $assetName;
        $this->gitHubReleaseClient($framework)->downloadReleaseToFile($release, $shape, $destination);
    }

    /**
     * @return array<string, mixed>
     */
    private function dependencyShape(FrameworkConfig $framework): array
    {
        return match ($framework->releaseSourceProvider()) {
            'gitlab-release' => [
                'slug' => 'wp-core-base',
                'source_config' => [
                    'gitlab_project' => $framework->releaseSourceReference(),
                    'gitlab_release_asset_pattern' => $framework->assetName(),
                    'gitlab_token_env' => null,
                    'gitlab_api_base' => $framework->releaseSourceApiBase(),
                ],
                'archive_subdir' => '',
            ],
            'github-release' => [
                'slug' => 'wp-core-base',
                'source_config' => [
                    'github_repository' => $framework->releaseSourceReference(),
                    'github_release_asset_pattern' => $framework->assetName(),
                    'github_token_env' => null,
                ],
                'archive_subdir' => '',
            ],
            default => throw new RuntimeException(sprintf(
                'Unsupported framework release source provider: %s',
                $framework->releaseSourceProvider()
            )),
        };
    }

    private function gitHubReleaseClient(FrameworkConfig $framework): GitHubReleaseClient
    {
        return new GitHubReleaseClient($this->httpClient, $framework->releaseSourceApiBase());
    }

    private function gitLabReleaseClient(FrameworkConfig $framework): GitLabReleaseClient
    {
        return new GitLabReleaseClient($this->httpClient, $framework->releaseSourceApiBase());
    }

    private function extractChecksum(string $contents, string $assetName): string
    {
        $lines = preg_split("/\r\n|\n|\r/", trim($contents)) ?: [];

        if ($lines === []) {
            throw new RuntimeException(sprintf('Framework release checksum sidecar is empty for %s.', $assetName));
        }

        foreach ($lines as $line) {
            $parsed = $this->parseChecksumLine($line, $assetName);

            if ($parsed !== null) {
                return $parsed;
            }
        }

        throw new RuntimeException(sprintf(
            'Framework release checksum sidecar for %s did not contain a matching SHA-256 entry.',
            $assetName
        ));
    }

    private function parseChecksumLine(string $line, string $assetName): ?string
    {
        $line = trim($line);

        if ($line === '') {
            return null;
        }

        $parts = preg_split('/\s+/', $line, 2);
        $checksum = strtolower((string) ($parts[0] ?? ''));

        if (preg_match('/^[a-f0-9]{64}$/', $checksum) !== 1) {
            return null;
        }

        $filename = trim((string) ($parts[1] ?? ''), " *\t");

        if ($filename === '') {
            return null;
        }

        if ($filename !== $assetName) {
            throw new RuntimeException(sprintf(
                'Framework release checksum sidecar entry bound digest to %s, expected %s.',
                $filename,
                $assetName
            ));
        }

        return $checksum;
    }
}
