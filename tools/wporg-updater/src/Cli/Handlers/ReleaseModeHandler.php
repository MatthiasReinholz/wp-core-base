<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater\Cli\Handlers;

use Closure;
use RuntimeException;
use WpOrgPluginUpdater\Cli\CliModeHandler;
use WpOrgPluginUpdater\FrameworkReleaseArtifactBuilder;
use WpOrgPluginUpdater\FrameworkReleasePreparer;
use WpOrgPluginUpdater\FrameworkReleaseSignature;
use WpOrgPluginUpdater\FrameworkReleaseVerifier;
use WpOrgPluginUpdater\ReleaseSignatureKeyStore;

final class ReleaseModeHandler implements CliModeHandler
{
    public function __construct(
        private readonly string $repoRoot,
        private readonly bool $jsonOutput,
        private readonly Closure $emitJson,
    ) {
    }

    public function supports(string $mode): bool
    {
        return in_array($mode, [
            'release-verify',
            'build-release-artifact',
            'release-sign',
            'prepare-framework-release',
        ], true);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function handle(string $mode, array $options): int
    {
        if ($mode === 'release-verify') {
            $tag = isset($options['tag']) && is_string($options['tag']) ? $options['tag'] : null;
            $artifact = isset($options['artifact']) && is_string($options['artifact']) ? $options['artifact'] : null;
            $checksumFile = isset($options['checksum-file']) && is_string($options['checksum-file']) ? $options['checksum-file'] : null;
            $signatureFile = isset($options['signature-file']) && is_string($options['signature-file']) ? $options['signature-file'] : null;
            $publicKeyFile = isset($options['public-key-file']) && is_string($options['public-key-file']) ? $options['public-key-file'] : null;
            $report = (new FrameworkReleaseVerifier($this->repoRoot))->verifyDetailed($tag, $artifact, $checksumFile, $signatureFile, $publicKeyFile);

            if ($this->jsonOutput) {
                ($this->emitJson)($report);
            }

            $resolvedTag = $report['release_tag'];
            fwrite(STDOUT, sprintf("Release verification passed for %s\n", $resolvedTag));

            return 0;
        }

        if ($mode === 'build-release-artifact') {
            $artifact = isset($options['output']) && is_string($options['output']) ? $options['output'] : null;
            $checksumFile = isset($options['checksum-file']) && is_string($options['checksum-file']) ? $options['checksum-file'] : null;

            if ($artifact === null || trim($artifact) === '') {
                throw new RuntimeException('build-release-artifact requires --output=/path/to/wp-core-base-vendor-snapshot.zip.');
            }

            $report = (new FrameworkReleaseArtifactBuilder($this->repoRoot))->build($artifact, $checksumFile);

            if ($this->jsonOutput) {
                ($this->emitJson)([
                    'status' => 'success',
                    ...$report,
                ]);
            }

            fwrite(STDOUT, sprintf("Built release artifact %s\n", $report['artifact']));
            fwrite(STDOUT, sprintf("Checksum file: %s\n", $report['checksum_file']));

            return 0;
        }

        if ($mode === 'release-sign') {
            $artifact = isset($options['artifact']) && is_string($options['artifact']) ? $options['artifact'] : null;
            $checksumFile = isset($options['checksum-file']) && is_string($options['checksum-file']) ? $options['checksum-file'] : null;
            $signatureFile = isset($options['signature-file']) && is_string($options['signature-file']) ? $options['signature-file'] : null;
            $privateKeyEnv = isset($options['private-key-env']) && is_string($options['private-key-env']) ? $options['private-key-env'] : null;
            $passphraseEnv = isset($options['passphrase-env']) && is_string($options['passphrase-env']) ? $options['passphrase-env'] : null;

            if ($artifact === null || trim($artifact) === '' || ! is_file($artifact)) {
                throw new RuntimeException('release-sign requires --artifact=/path/to/release.zip.');
            }

            if ($checksumFile === null || trim($checksumFile) === '' || ! is_file($checksumFile)) {
                throw new RuntimeException('release-sign requires --checksum-file=/path/to/release.zip.sha256.');
            }

            if ($signatureFile === null || trim($signatureFile) === '') {
                throw new RuntimeException('release-sign requires --signature-file=/path/to/release.zip.sha256.sig.');
            }

            if ($privateKeyEnv === null || trim($privateKeyEnv) === '') {
                throw new RuntimeException('release-sign requires --private-key-env=ENV_VAR.');
            }

            $privateKeyPem = ReleaseSignatureKeyStore::privateKeyFromEnvironment($privateKeyEnv);
            $passphrase = ReleaseSignatureKeyStore::optionalEnvironmentValue($passphraseEnv);
            $document = FrameworkReleaseSignature::signChecksumFile($checksumFile, $signatureFile, $privateKeyPem, $passphrase);

            fwrite(STDOUT, sprintf("Release signature written for %s\n", basename($artifact)));
            fwrite(STDOUT, sprintf("Signed checksum: %s\n", $document['signed_file']));
            fwrite(STDOUT, sprintf("Key ID: %s\n", $document['key_id']));

            return 0;
        }

        $releaseType = $options['release-type'] ?? null;

        if (! is_string($releaseType) || $releaseType === '') {
            throw new RuntimeException('prepare-framework-release requires --release-type=patch|minor|major|custom.');
        }

        $customVersion = isset($options['version']) && is_string($options['version']) ? $options['version'] : null;
        $result = (new FrameworkReleasePreparer($this->repoRoot))->prepare(
            $releaseType,
            $customVersion,
            isset($options['allow-current-version'])
        );

        fwrite(STDOUT, sprintf("Prepared framework release %s\n", $result['version']));
        fwrite(STDOUT, sprintf("Release notes: %s\n", $result['release_notes_path']));

        if ($result['release_notes_created']) {
            fwrite(STDOUT, "Release notes template created.\n");
        }

        return 0;
    }
}
