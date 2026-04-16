<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use DateTimeImmutable;
use RuntimeException;

final class ManagedDependencyReleaseResolver
{
    public function __construct(
        private readonly Config $config,
        private readonly GitHubReleaseClient $gitHubReleaseClient,
        private readonly ManagedSourceRegistry $managedSourceRegistry,
    ) {
    }

    /**
     * @param array<string, mixed> $dependency
     * @return array<string, mixed>
     */
    public function fetchReleaseCatalog(array $dependency): array
    {
        return $this->managedSourceRegistry->for($dependency)->fetchCatalog($dependency);
    }

    /**
     * @param array<string, mixed> $dependency
     * @param array<string, mixed> $catalog
     * @return array<string, mixed>
     */
    public function releaseDataForVersion(array $dependency, array $catalog, string $targetVersion, string $fallbackReleaseAt): array
    {
        $releaseData = $this->managedSourceRegistry->for($dependency)->releaseDataForVersion(
            $dependency,
            $catalog,
            $targetVersion,
            $fallbackReleaseAt
        );

        $releaseData = $this->normalizedReleaseData($releaseData, $targetVersion);
        $publishedAt = (string) ($releaseData['release_at'] ?? $fallbackReleaseAt);
        $this->assertDependencyReleaseAgeEligible($dependency, $targetVersion, $publishedAt);
        $releaseData['expected_checksum_sha256'] = $this->expectedManagedReleaseChecksumSha256($dependency, $releaseData);
        [$trustState, $trustDetails] = $this->deriveTrustState($dependency, $releaseData);
        $releaseData['trust_state'] = $trustState;
        $releaseData['trust_details'] = $trustDetails;
        $releaseData['source_details'] = array_merge((array) ($releaseData['source_details'] ?? []), [
            ['label' => 'Artifact trust state', 'value' => sprintf('`%s`', $trustState)],
            ['label' => 'Artifact provenance', 'value' => $trustDetails],
        ]);

        return $releaseData;
    }

    /**
     * @param array<string, mixed> $releaseData
     * @return array<string, mixed>
     */
    private function normalizedReleaseData(array $releaseData, string $targetVersion): array
    {
        $notesMarkup = trim((string) ($releaseData['notes_markup'] ?? ''));
        $notesText = trim((string) ($releaseData['notes_text'] ?? ''));
        $hadNotesMarkup = $notesMarkup !== '';

        if ($notesMarkup === '') {
            $notesMarkup = sprintf('_Release notes unavailable for version %s._', $targetVersion);
        }

        if ($notesText === '') {
            if ($hadNotesMarkup) {
                $notesText = trim(
                    preg_replace('/\s+/', ' ', preg_replace('/[`*_>#-]+/', ' ', strip_tags($notesMarkup)) ?? '') ?? ''
                );
            } else {
                $notesText = sprintf('Release notes unavailable for version %s.', $targetVersion);
            }
        }

        if ($notesText === '') {
            $notesText = sprintf('Release notes unavailable for version %s.', $targetVersion);
        }

        $releaseData['notes_markup'] = $notesMarkup;
        $releaseData['notes_text'] = $notesText;

        return $releaseData;
    }

    /**
     * @param array<string, mixed> $dependency
     */
    private function assertDependencyReleaseAgeEligible(array $dependency, string $targetVersion, string $publishedAt): void
    {
        $minimumAgeHours = $this->config->dependencyMinReleaseAgeHours($dependency);

        if ($minimumAgeHours <= 0) {
            return;
        }

        try {
            $releaseTimestamp = new DateTimeImmutable($publishedAt);
        } catch (\Throwable $throwable) {
            throw new RuntimeException(sprintf(
                'Release age verification for %s could not parse published_at value %s.',
                (string) $dependency['component_key'],
                $publishedAt
            ), previous: $throwable);
        }

        $ageSeconds = time() - $releaseTimestamp->getTimestamp();

        if ($ageSeconds >= ($minimumAgeHours * 3600)) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Release %s for %s is only %.2f hours old. Minimum required age is %d hours.',
            $targetVersion,
            (string) $dependency['component_key'],
            max(0, $ageSeconds) / 3600,
            $minimumAgeHours
        ));
    }

    /**
     * @param array<string, mixed> $dependency
     * @param array<string, mixed> $releaseData
     */
    private function expectedManagedReleaseChecksumSha256(array $dependency, array $releaseData): ?string
    {
        $verificationMode = $this->config->dependencyVerificationMode($dependency);

        if ($verificationMode === 'none') {
            return null;
        }

        if ($dependency['source'] === 'github-release') {
            return $this->expectedGitHubReleaseChecksumSha256($dependency, $releaseData, $verificationMode);
        }

        $checksum = $releaseData['checksum_sha256'] ?? null;

        if (is_string($checksum) && trim($checksum) !== '') {
            return trim($checksum);
        }

        if ($verificationMode === 'checksum-sidecar-required') {
            throw new RuntimeException(sprintf(
                '%s requires release checksum verification, but source %s did not provide releaseData.checksum_sha256.',
                (string) $dependency['component_key'],
                (string) $dependency['source']
            ));
        }

        return null;
    }

    /**
     * @param array<string, mixed> $dependency
     * @param array<string, mixed> $releaseData
     */
    private function expectedGitHubReleaseChecksumSha256(array $dependency, array $releaseData, string $verificationMode): ?string
    {
        $checksumAssetPattern = $dependency['source_config']['checksum_asset_pattern'] ?? null;

        if (! is_string($checksumAssetPattern) || trim($checksumAssetPattern) === '') {
            if ($verificationMode === 'checksum-sidecar-required') {
                throw new RuntimeException(sprintf(
                    '%s requires source_config.checksum_asset_pattern because checksum-sidecar verification is required.',
                    (string) $dependency['component_key']
                ));
            }

            return null;
        }

        $release = $releaseData['release'] ?? null;

        if (! is_array($release)) {
            throw new RuntimeException(sprintf(
                'GitHub release payload is missing for %s.',
                (string) $dependency['component_key']
            ));
        }

        $expectedAssetName = $this->expectedGitHubArtifactName($release, $dependency);
        $checksum = $this->gitHubReleaseClient->checksumSha256ForAssetPattern(
            $release,
            $dependency,
            trim($checksumAssetPattern),
            $expectedAssetName
        );

        if ($checksum === null && $verificationMode === 'checksum-sidecar-required') {
            throw new RuntimeException(sprintf(
                'Required checksum sidecar asset %s was not found for %s.',
                trim($checksumAssetPattern),
                (string) $dependency['component_key']
            ));
        }

        return $checksum;
    }

    /**
     * @param array<string, mixed> $release
     * @param array<string, mixed> $dependency
     */
    private function expectedGitHubArtifactName(array $release, array $dependency): string
    {
        $assetPattern = $dependency['source_config']['github_release_asset_pattern'] ?? null;

        if (is_string($assetPattern) && $assetPattern !== '') {
            $assets = $release['assets'] ?? null;

            if (! is_array($assets)) {
                throw new RuntimeException(sprintf(
                    'GitHub release asset list is missing for %s.',
                    (string) $dependency['component_key']
                ));
            }

            foreach ($assets as $asset) {
                if (! is_array($asset)) {
                    continue;
                }

                $name = $asset['name'] ?? null;

                if (is_string($name) && $name !== '' && fnmatch($assetPattern, $name)) {
                    return $name;
                }
            }

            throw new RuntimeException(sprintf(
                'No GitHub release asset matching "%s" was found for %s.',
                $assetPattern,
                (string) $dependency['component_key']
            ));
        }

        throw new RuntimeException(sprintf(
            '%s requires source_config.github_release_asset_pattern to bind checksum verification to a release asset filename.',
            (string) $dependency['component_key']
        ));
    }

    /**
     * @param array<string, mixed> $dependency
     * @param array<string, mixed> $releaseData
     * @return array{0:string,1:string}
     */
    private function deriveTrustState(array $dependency, array $releaseData): array
    {
        $source = (string) ($dependency['source'] ?? '');
        $hasChecksum = is_string($releaseData['expected_checksum_sha256'] ?? null)
            && trim((string) $releaseData['expected_checksum_sha256']) !== '';

        if ($source === 'github-release' && $hasChecksum) {
            return [
                DependencyTrustState::VERIFIED,
                'Archive checksum was independently verified against a release-side checksum sidecar.',
            ];
        }

        if ($source === 'premium') {
            return [
                DependencyTrustState::PROVIDER_ASSERTED,
                'Artifact provenance is asserted by the premium provider integration and was not independently verified by checksum-sidecar.',
            ];
        }

        return [
            DependencyTrustState::METADATA_ONLY,
            (string) ($releaseData['trust_details'] ?? 'Archive authenticity was not independently verified; release metadata only.'),
        ];
    }
}
