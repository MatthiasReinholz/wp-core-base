<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/tools/wporg-updater/src/Autoload.php';

use WpOrgPluginUpdater\AtomicFileWriter;
use WpOrgPluginUpdater\FileChecksum;
use WpOrgPluginUpdater\FrameworkReleaseSignature;
use WpOrgPluginUpdater\HttpClient;
use WpOrgPluginUpdater\ReleaseSignatureKeyStore;
use WpOrgPluginUpdater\TempWorkspace;

// Execute historical installer code only after both an immutable artifact pin and
// the official detached signature establish the exact published input.
$repoRoot = dirname(__DIR__, 2);
$tag = 'v1.4.8';
$assetName = 'wp-core-base-vendor-snapshot.zip';
$expectedSha256 = 'a08e8675aa10ef3d2eb1de279c1280d0cd65e74af7b3326ca48b2da7015f6fe5';
$destination = $repoRoot . '/.wp-core-base/build/legacy-release-' . $tag;
$receiptPath = $repoRoot . '/.wp-core-base/build/legacy-release-verification.json';
$workspace = TempWorkspace::create($repoRoot, 'legacy-release-input');
try {
    $http = new HttpClient(userAgent: 'wp-core-base/legacy-release-compatibility', timeoutSeconds: 180);
    foreach ([$assetName, $assetName . '.sha256', $assetName . '.sha256.sig'] as $name) {
        $http->downloadToFileWithOptions(
            'https://github.com/MatthiasReinholz/wp-core-base/releases/download/' . $tag . '/' . $name,
            $workspace->path() . '/' . $name,
            [],
            [
                'allowed_redirect_hosts' => ['github.com', 'release-assets.githubusercontent.com', 'objects.githubusercontent.com'],
                'max_redirects' => 3,
                'max_download_bytes' => $name === $assetName ? 100 * 1024 * 1024 : 64 * 1024,
                'connect_timeout_seconds' => 15,
            ],
        );
    }
    $artifact = $workspace->path() . '/' . $assetName;
    $checksum = $artifact . '.sha256';
    FileChecksum::assertSha256Matches($artifact, $expectedSha256, 'Pinned v1.4.8 compatibility artifact');
    $defaultKey = $repoRoot . '/' . ReleaseSignatureKeyStore::PUBLIC_KEY_RELATIVE_PATH;
    $keys = array_merge([$defaultKey], glob(dirname($defaultKey) . '/' . ReleaseSignatureKeyStore::PUBLIC_KEY_ROTATED_GLOB) ?: []);
    $signature = FrameworkReleaseSignature::verifyChecksumFileWithKeyPaths($checksum, $checksum . '.sig', $keys);
    $checksumContents = file_get_contents($checksum);
    if (! is_string($checksumContents)) {
        throw new RuntimeException('Cannot read verified historical release checksum.');
    }
    FileChecksum::assertSha256Matches($artifact, FileChecksum::extractSha256ForAsset($checksumContents, $assetName), 'Signed v1.4.8 compatibility artifact');
    if (! is_dir($destination) && ! mkdir($destination, 0700, true) && ! is_dir($destination)) {
        throw new RuntimeException('Cannot create historical release fixture directory.');
    }
    foreach ([$assetName, $assetName . '.sha256', $assetName . '.sha256.sig'] as $name) {
        if (! rename($workspace->path() . '/' . $name, $destination . '/' . $name)) {
            throw new RuntimeException('Cannot publish verified historical release fixture: ' . $name);
        }
    }
    (new AtomicFileWriter())->write($receiptPath, json_encode([
        'status' => 'verified_input',
        'release_tag' => $tag,
        'artifact_sha256' => $expectedSha256,
        'artifact_size_bytes' => filesize($destination . '/' . $assetName),
        'signature_key_id' => $signature['key_id'],
        'artifact_path' => '.wp-core-base/build/legacy-release-' . $tag . '/' . $assetName,
        'verified_at' => gmdate(DATE_ATOM),
        'compatibility_result' => 'Recorded separately by release_distribution_contracts.php in test-report.json.',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    fwrite(STDOUT, "Verified pinned, officially signed v1.4.8 input for the required legacy compatibility lane.\n");
} finally {
    $workspace->close();
}
