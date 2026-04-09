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
        return $this->verifyDetailed($expectedTag, $artifactPath, $checksumPath, $signaturePath, $publicKeyPath)['release_tag'];
    }

    /**
     * @return array<string, mixed>
     */
    public function verifyDetailed(
        ?string $expectedTag = null,
        ?string $artifactPath = null,
        ?string $checksumPath = null,
        ?string $signaturePath = null,
        ?string $publicKeyPath = null,
    ): array
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

        $contractReport = (new FrameworkPublicContractVerifier($this->repoRoot))->verify($framework, $releaseNotes);

        if (trim($framework->repository) === '') {
            throw new RuntimeException('Framework metadata must declare repository.');
        }

        $artifactVerified = false;

        if ($artifactPath !== null || $checksumPath !== null || $signaturePath !== null || $publicKeyPath !== null) {
            if (! is_string($artifactPath) || trim($artifactPath) === '' || ! is_string($checksumPath) || trim($checksumPath) === '') {
                throw new RuntimeException('Artifact verification requires --artifact, --checksum-file, and --signature-file.');
            }

            if (! is_string($signaturePath) || trim($signaturePath) === '') {
                throw new RuntimeException('Artifact verification requires --signature-file so unsigned checksum sidecars are never trusted.');
            }

            $this->verifyArtifact(
                $framework,
                $artifactPath,
                $checksumPath,
                $signaturePath,
                is_string($publicKeyPath) && trim($publicKeyPath) !== '' ? $publicKeyPath : null
            );
            $artifactVerified = true;
        }

        return [
            'status' => 'success',
            'release_tag' => $releaseTag,
            'framework_version' => $releaseVersion,
            'release_notes_path' => $releaseNotesPath,
            'artifact_verified' => $artifactVerified,
            'baseline' => $contractReport['baseline'],
        ];
    }

    private function verifyArtifact(
        FrameworkConfig $framework,
        string $artifactPath,
        string $checksumPath,
        string $signaturePath,
        ?string $publicKeyPath,
    ): void
    {
        if (! is_file($artifactPath)) {
            throw new RuntimeException(sprintf('Release artifact not found: %s', $artifactPath));
        }

        if (! is_file($checksumPath)) {
            throw new RuntimeException(sprintf('Release checksum file not found: %s', $checksumPath));
        }

        if (! is_file($signaturePath)) {
            throw new RuntimeException(sprintf('Release signature file not found: %s', $signaturePath));
        }

        FrameworkReleaseSignature::verifyChecksumFile(
            $checksumPath,
            $signaturePath,
            $publicKeyPath ?? ReleaseSignatureKeyStore::defaultPublicKeyPath($framework)
        );

        $checksumContents = file_get_contents($checksumPath);

        if (! is_string($checksumContents)) {
            throw new RuntimeException(sprintf('Unable to read release checksum file: %s', $checksumPath));
        }

        $expectedChecksum = $this->extractChecksum($checksumContents, basename($artifactPath));
        FileChecksum::assertSha256Matches($artifactPath, $expectedChecksum, 'Release artifact');

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

            foreach (FrameworkReleaseArtifactBuilder::excludedPaths() as $excludedPath) {
                if (file_exists($payloadRoot . '/' . $excludedPath) || is_link($payloadRoot . '/' . $excludedPath)) {
                    throw new RuntimeException(sprintf(
                        'Release artifact unexpectedly included excluded path: %s',
                        $excludedPath
                    ));
                }
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
            $this->assertInstalledArtifactOperable($downstreamRoot);
        } finally {
            $runtimeInspector->clearPath($tempRoot);
        }
    }

    private function assertInstalledArtifactOperable(string $downstreamRoot): void
    {
        $doctorOutput = $this->runVendoredCommand(
            $downstreamRoot,
            'php vendor/wp-core-base/tools/wporg-updater/bin/wporg-updater.php doctor --repo-root=. --json'
        );
        $this->assertSuccessfulJsonResult($doctorOutput, 'doctor');

        $stageRuntimeOutput = $this->runVendoredCommand(
            $downstreamRoot,
            'php vendor/wp-core-base/tools/wporg-updater/bin/wporg-updater.php stage-runtime --repo-root=. --output=.wp-core-base/build/release-verify-runtime --json'
        );
        $this->assertSuccessfulJsonResult($stageRuntimeOutput, 'stage-runtime');
    }

    private function runVendoredCommand(string $workingDirectory, string $command): string
    {
        $descriptorSpec = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(['/bin/sh', '-lc', $command], $descriptorSpec, $pipes, $workingDirectory);

        if (! is_resource($process)) {
            throw new RuntimeException(sprintf('Failed to start vendored release verification command: %s', $command));
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $status = proc_close($process);
        $output = trim((string) $stdout . "\n" . (string) $stderr);

        if ($status !== 0) {
            throw new RuntimeException(sprintf(
                "Installed release artifact command failed: %s\n%s",
                $command,
                $output
            ));
        }

        return (string) $stdout;
    }

    private function assertSuccessfulJsonResult(string $output, string $commandName): void
    {
        $decoded = json_decode($output, true);

        if (! is_array($decoded)) {
            throw new RuntimeException(sprintf(
                'Installed release artifact %s command did not return valid JSON output.',
                $commandName
            ));
        }

        if (($decoded['status'] ?? null) !== 'success') {
            throw new RuntimeException(sprintf(
                'Installed release artifact %s command reported failure.',
                $commandName
            ));
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
        return FileChecksum::extractSha256ForAsset($contents, $assetName);
    }
}
