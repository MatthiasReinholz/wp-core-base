<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;
use ZipArchive;

final class FrameworkReleaseVerifier
{
    public function __construct(
        private readonly string $repoRoot,
    ) {
    }

    public function verify(
        ?string $expectedTag = null,
        ?string $artifactPath = null,
        ?string $checksumPath = null,
        ?string $signaturePath = null,
        ?string $publicKeyPath = null,
    ): string
    {
        $framework = FrameworkConfig::load($this->repoRoot);
        $releaseVersion = $framework->normalizedVersion();
        $releaseTag = 'v' . $releaseVersion;
        $releaseNotesPath = $this->repoRoot . '/docs/releases/' . $releaseVersion . '.md';

        if ($expectedTag !== null && trim($expectedTag) !== '' && trim($expectedTag) !== $releaseTag) {
            throw new RuntimeException(sprintf(
                'Release tag mismatch. Expected %s from .wp-core-base/framework.php but received %s.',
                $releaseTag,
                trim($expectedTag)
            ));
        }

        if (! is_file($releaseNotesPath)) {
            throw new RuntimeException(sprintf('Release notes not found: %s', $releaseNotesPath));
        }

        $releaseNotes = file_get_contents($releaseNotesPath);

        if ($releaseNotes === false) {
            throw new RuntimeException(sprintf('Unable to read release notes: %s', $releaseNotesPath));
        }

        $missingSections = FrameworkReleaseNotes::missingRequiredSections($releaseNotes);

        if ($missingSections !== []) {
            throw new RuntimeException(sprintf(
                'Release notes %s are missing required sections: %s.',
                basename($releaseNotesPath),
                implode(', ', $missingSections)
            ));
        }

        if (! str_contains($releaseNotes, $framework->baseline['wordpress_core'])) {
            throw new RuntimeException(sprintf(
                'Release notes %s must mention the bundled WordPress core baseline %s.',
                basename($releaseNotesPath),
                $framework->baseline['wordpress_core']
            ));
        }

        if (trim($framework->repository) === '') {
            throw new RuntimeException('Framework metadata must declare repository.');
        }

        if ($artifactPath !== null || $checksumPath !== null || $signaturePath !== null || $publicKeyPath !== null) {
            if (! is_string($artifactPath) || trim($artifactPath) === '' || ! is_string($checksumPath) || trim($checksumPath) === '') {
                throw new RuntimeException('Artifact verification requires both --artifact and --checksum-file.');
            }

            $this->verifyArtifact(
                $framework,
                $artifactPath,
                $checksumPath,
                is_string($signaturePath) && trim($signaturePath) !== '' ? $signaturePath : null,
                is_string($publicKeyPath) && trim($publicKeyPath) !== '' ? $publicKeyPath : null
            );
        }

        return $releaseTag;
    }

    private function verifyArtifact(
        FrameworkConfig $framework,
        string $artifactPath,
        string $checksumPath,
        ?string $signaturePath,
        ?string $publicKeyPath,
    ): void
    {
        if (! is_file($artifactPath)) {
            throw new RuntimeException(sprintf('Release artifact not found: %s', $artifactPath));
        }

        if (! is_file($checksumPath)) {
            throw new RuntimeException(sprintf('Release checksum file not found: %s', $checksumPath));
        }

        if ($signaturePath !== null) {
            FrameworkReleaseSignature::verifyChecksumFile(
                $checksumPath,
                $signaturePath,
                $publicKeyPath ?? ReleaseSignatureKeyStore::defaultPublicKeyPath($framework)
            );
        }

        $checksumContents = file_get_contents($checksumPath);

        if (! is_string($checksumContents)) {
            throw new RuntimeException(sprintf('Unable to read release checksum file: %s', $checksumPath));
        }

        $expectedChecksum = $this->extractChecksum($checksumContents, basename($artifactPath));
        $actualChecksum = hash_file('sha256', $artifactPath);

        if (! is_string($actualChecksum) || $actualChecksum === '') {
            throw new RuntimeException(sprintf('Unable to hash release artifact: %s', $artifactPath));
        }

        if (! hash_equals($expectedChecksum, strtolower($actualChecksum))) {
            throw new RuntimeException(sprintf(
                'Release artifact checksum mismatch. Expected %s but found %s.',
                $expectedChecksum,
                strtolower($actualChecksum)
            ));
        }

        $tempRoot = sys_get_temp_dir() . '/wp-core-base-release-verify-' . bin2hex(random_bytes(6));
        $extractPath = $tempRoot . '/extract';
        $downstreamRoot = $tempRoot . '/downstream';
        $runtimeInspector = new RuntimeInspector(Config::load($this->repoRoot)->runtime);

        if (! mkdir($extractPath, 0775, true) && ! is_dir($extractPath)) {
            throw new RuntimeException(sprintf('Unable to create release verification temp dir: %s', $extractPath));
        }

        try {
            $zip = new ZipArchive();

            if ($zip->open($artifactPath) !== true) {
                throw new RuntimeException(sprintf('Unable to open release artifact archive: %s', $artifactPath));
            }

            ZipExtractor::extractValidated($zip, $extractPath);
            $zip->close();

            $payloadRoot = $this->resolvePayloadRoot($extractPath);
            $payloadFramework = FrameworkConfig::load($payloadRoot);

            if ($payloadFramework->version !== $framework->version) {
                throw new RuntimeException(sprintf(
                    'Release artifact framework version mismatch. Expected %s but found %s.',
                    $framework->version,
                    $payloadFramework->version
                ));
            }

            if ($payloadFramework->repository !== $framework->repository) {
                throw new RuntimeException(sprintf(
                    'Release artifact repository mismatch. Expected %s but found %s.',
                    $framework->repository,
                    $payloadFramework->repository
                ));
            }

            if ($payloadFramework->assetName() !== $framework->assetName()) {
                throw new RuntimeException(sprintf(
                    'Release artifact asset-name mismatch. Expected %s but found %s.',
                    $framework->assetName(),
                    $payloadFramework->assetName()
                ));
            }

            if (! mkdir($downstreamRoot, 0775, true) && ! is_dir($downstreamRoot)) {
                throw new RuntimeException(sprintf('Unable to create downstream release verification dir: %s', $downstreamRoot));
            }

            (new DownstreamScaffolder($this->repoRoot, $downstreamRoot))->scaffold(
                'vendor/wp-core-base',
                'content-only',
                'cms',
                true
            );
            $downstreamConfig = Config::load($downstreamRoot);
            (new FrameworkInstaller($downstreamRoot, new RuntimeInspector($downstreamConfig->runtime)))->apply(
                $payloadRoot,
                'vendor/wp-core-base'
            );
        } finally {
            $runtimeInspector->clearPath($tempRoot);
        }
    }

    private function resolvePayloadRoot(string $extractPath): string
    {
        $entries = array_values(array_filter(scandir($extractPath) ?: [], static fn (string $entry): bool => $entry !== '.' && $entry !== '..'));

        if ($entries === []) {
            throw new RuntimeException('Release artifact extracted without any files.');
        }

        if (count($entries) === 1) {
            $candidate = $extractPath . '/' . $entries[0];

            if (is_dir($candidate)) {
                return $candidate;
            }
        }

        return $extractPath;
    }

    private function extractChecksum(string $contents, string $assetName): string
    {
        $lines = preg_split("/\r\n|\n|\r/", trim($contents)) ?: [];

        foreach ($lines as $line) {
            $parsed = $this->parseChecksumLine($line, $assetName);

            if ($parsed !== null) {
                return $parsed;
            }
        }

        throw new RuntimeException(sprintf('Checksum file for %s did not contain a matching SHA-256 digest.', $assetName));
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
                'Checksum file entry bound digest to %s, expected %s.',
                $filename,
                $assetName
            ));
        }

        return $checksum;
    }
}
