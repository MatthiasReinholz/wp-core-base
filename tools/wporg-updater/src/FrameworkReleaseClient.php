<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class FrameworkReleaseClient implements FrameworkReleaseSource
{
    public function __construct(
        private readonly GitHubReleaseClient $gitHubReleaseClient,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchStableReleases(FrameworkConfig $framework): array
    {
        return $this->gitHubReleaseClient->fetchStableReleases($this->dependencyShape($framework));
    }

    /**
     * @param array<string, mixed> $release
     * @return array<string, mixed>
     */
    public function releaseData(FrameworkConfig $framework, array $release): array
    {
        $version = $this->gitHubReleaseClient->latestVersion($release, $this->dependencyShape($framework));
        $releaseNotes = trim((string) ($release['body'] ?? ''));

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
            'release_at' => $this->gitHubReleaseClient->latestReleaseAt($release),
            'release_url' => $this->gitHubReleaseClient->releaseUrl($release, $framework->repository),
            'notes_markdown' => $releaseNotes,
            'notes_text' => $this->gitHubReleaseClient->markdownToText($releaseNotes),
            'notes_sections' => FrameworkReleaseNotes::parseSections($releaseNotes),
            'release' => $release,
        ];
    }

    /**
     * @param array<string, mixed> $release
     */
    public function downloadReleaseAsset(FrameworkConfig $framework, array $release, string $destination): void
    {
        $this->gitHubReleaseClient->downloadReleaseToFile($release, $this->dependencyShape($framework), $destination);
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
            $this->gitHubReleaseClient->downloadReleaseToFile($release, $this->checksumDependencyShape($framework), $checksumPath);
            $this->gitHubReleaseClient->downloadReleaseToFile($release, $this->signatureDependencyShape($framework), $signaturePath);
            FrameworkReleaseSignature::verifyChecksumFile(
                $checksumPath,
                $signaturePath,
                ReleaseSignatureKeyStore::defaultPublicKeyPath($framework)
            );

            $checksumContents = file_get_contents($checksumPath);

            if (! is_string($checksumContents)) {
                throw new RuntimeException(sprintf('Unable to read framework release checksum file: %s', $checksumPath));
            }

            $expectedChecksum = $this->extractChecksum($checksumContents, $framework->checksumAssetName());
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
            if (is_file($signaturePath)) {
                @unlink($signaturePath);
            }

            if (is_file($checksumPath)) {
                @unlink($checksumPath);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function dependencyShape(FrameworkConfig $framework): array
    {
        return [
            'slug' => 'wp-core-base',
            'source_config' => [
                'github_repository' => $framework->repository,
                'github_release_asset_pattern' => $framework->assetName(),
                'github_token_env' => null,
            ],
            'archive_subdir' => '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checksumDependencyShape(FrameworkConfig $framework): array
    {
        $shape = $this->dependencyShape($framework);
        $shape['source_config']['github_release_asset_pattern'] = $framework->checksumAssetName();

        return $shape;
    }

    /**
     * @return array<string, mixed>
     */
    private function signatureDependencyShape(FrameworkConfig $framework): array
    {
        $shape = $this->dependencyShape($framework);
        $shape['source_config']['github_release_asset_pattern'] = $framework->checksumSignatureAssetName();

        return $shape;
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
