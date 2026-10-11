<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

/** Explicit, digest-pinned installation into the repository's private build directory. */
final class SigstoreToolInstaller
{
    public const VERSION = '3.1.3';
    private const DIGESTS = [
        'linux-amd64' => '4629c757b7618056f8ddd7e2625ae9fdd94c0372a65049520bc7d9df9efc7f71',
        'linux-arm64' => 'c5d324e091826b0d7a78eb16fef316450b4eb9aaec045611c08ba06f5e73220a',
        'darwin-amd64' => '2347488e5d5b25336644024dfeca5601b190e91197a71a917bda44744aff106c',
        'darwin-arm64' => '5cf948c2f4dfe59687bdd0b8523709067383e03982cc543475c8a7dc70e92a76',
    ];

    public function install(Config $config, HttpClient $httpClient): ?string
    {
        $required = false;
        foreach ($config->managedDependencies() as $dependency) {
            $required = $required || SigstoreVerificationPolicy::forDependency($dependency) !== null;
        }
        if (! $required) {
            return null;
        }
        $platform = strtolower(PHP_OS_FAMILY) . '-' . match (strtolower(php_uname('m'))) {
            'x86_64', 'amd64' => 'amd64',
            'aarch64', 'arm64' => 'arm64',
            default => 'unsupported',
        };
        $digest = self::DIGESTS[$platform] ?? null;
        if ($digest === null) {
            throw new RuntimeException('Automatic Cosign installation supports Linux and macOS on amd64/arm64. Install Cosign on PATH explicitly on other platforms.');
        }
        $relativeDirectory = '.wp-core-base/build/verification-tools';
        ConfigPathRules::assertNoSymlinkDescendants($config->repoRoot, $relativeDirectory . '/cosign');
        $directory = $config->repoRoot . '/' . $relativeDirectory;
        if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
            throw new RuntimeException('Unable to create the verification tool directory.');
        }
        $destination = $directory . '/cosign';
        if (is_file($destination) && hash_file('sha256', $destination) === $digest && is_executable($destination)) {
            return $destination;
        }
        $workspace = TempWorkspace::create($config->repoRoot, 'sigstore-tool-install');
        try {
            $temporary = $workspace->path() . '/cosign';
            $httpClient->downloadToFileWithOptions(
                sprintf('https://github.com/sigstore/cosign/releases/download/v%s/cosign-%s', self::VERSION, $platform),
                $temporary,
                [],
                ['allowed_redirect_hosts' => ['github.com', 'release-assets.githubusercontent.com', 'objects.githubusercontent.com'], 'max_download_bytes' => 256 * 1024 * 1024]
            );
            FileChecksum::assertSha256Matches($temporary, $digest, 'Pinned Cosign executable');
            if (! chmod($temporary, 0755) || ! rename($temporary, $destination)) {
                throw new RuntimeException('Unable to publish the verified Cosign executable.');
            }
            return $destination;
        } finally {
            $workspace->close();
        }
    }
}
