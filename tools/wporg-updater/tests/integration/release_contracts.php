<?php

declare(strict_types=1);

use WpOrgPluginUpdater\FrameworkConfig;
use WpOrgPluginUpdater\FrameworkReleaseNotes;
use WpOrgPluginUpdater\FrameworkReleasePreparer;
use WpOrgPluginUpdater\FrameworkReleaseSignature;
use WpOrgPluginUpdater\FrameworkWriter;

/**
 * @param callable(bool,string):void $assert
 */
function run_release_contract_tests(
    callable $assert,
    string $repoRoot,
    FrameworkConfig $frameworkConfig,
    string $currentFrameworkVersion
): void {
    $signatureFixtureRoot = sys_get_temp_dir() . '/wporg-release-signature-' . bin2hex(random_bytes(4));
    mkdir($signatureFixtureRoot, 0777, true);
    $checksumFixturePath = $signatureFixtureRoot . '/artifact.zip.sha256';
    $signatureFixturePath = $signatureFixtureRoot . '/artifact.zip.sha256.sig';
    file_put_contents($checksumFixturePath, str_repeat('a', 64) . "  artifact.zip\n");
    $privateFixtureKey = (string) file_get_contents($repoRoot . '/tools/wporg-updater/tests/fixtures/release-signing/private.pem');
    $publicFixtureKeyPath = $repoRoot . '/tools/wporg-updater/tests/fixtures/release-signing/public.pem';
    $signatureDocument = FrameworkReleaseSignature::signChecksumFile($checksumFixturePath, $signatureFixturePath, $privateFixtureKey);
    $assert($signatureDocument['signed_file'] === 'artifact.zip.sha256', 'Expected release signing to bind the checksum sidecar filename.');
    $verifiedSignatureDocument = FrameworkReleaseSignature::verifyChecksumFile($checksumFixturePath, $signatureFixturePath, $publicFixtureKeyPath);
    $assert($verifiedSignatureDocument['key_id'] === $signatureDocument['key_id'], 'Expected release signature verification to report the same key identifier.');
    $signatureTamperRejected = false;
    file_put_contents($checksumFixturePath, str_repeat('b', 64) . "  artifact.zip\n");

    try {
        FrameworkReleaseSignature::verifyChecksumFile($checksumFixturePath, $signatureFixturePath, $publicFixtureKeyPath);
    } catch (RuntimeException $exception) {
        $signatureTamperRejected = str_contains($exception->getMessage(), 'digest mismatch');
    }

    $assert($signatureTamperRejected, 'Expected release signature verification to reject tampered checksum sidecars.');

    $releasePrepRoot = sys_get_temp_dir() . '/wporg-framework-release-' . bin2hex(random_bytes(4));
    mkdir($releasePrepRoot . '/.wp-core-base', 0777, true);
    mkdir($releasePrepRoot . '/docs/releases', 0777, true);
    (new FrameworkWriter())->write($frameworkConfig->withInstalledRelease(
        version: $frameworkConfig->version,
        wordPressCoreVersion: $frameworkConfig->baseline['wordpress_core'],
        managedComponents: $frameworkConfig->baseline['managed_components'],
        managedFiles: $frameworkConfig->managedFiles(),
        repoRoot: $releasePrepRoot,
        path: $releasePrepRoot . '/.wp-core-base/framework.php',
    ));
    $preparedRelease = (new FrameworkReleasePreparer($releasePrepRoot))->prepare('patch');
    $expectedPreparedVersion = 'v' . preg_replace_callback('/^(\d+)\.(\d+)\.(\d+)$/', static fn (array $m): string => sprintf('%d.%d.%d', (int) $m[1], (int) $m[2], (int) $m[3] + 1), $currentFrameworkVersion);
    $assert($preparedRelease['version'] === $expectedPreparedVersion, 'Expected prepare-framework-release to derive the next patch version.');
    $assert($preparedRelease['release_notes_created'] === true, 'Expected prepare-framework-release to scaffold release notes.');
    $preparedFramework = FrameworkConfig::load($releasePrepRoot);
    $preparedPlainVersion = ltrim($expectedPreparedVersion, 'v');
    $assert($preparedFramework->version === $preparedPlainVersion, 'Expected prepare-framework-release to bump framework.php.');
    $preparedNotes = (string) file_get_contents($releasePrepRoot . '/docs/releases/' . $preparedPlainVersion . '.md');
    $assert($preparedNotes !== '', 'Expected scaffolded release notes to be written.');
    $assert(FrameworkReleaseNotes::missingRequiredSections($preparedNotes) === [], 'Expected scaffolded release notes to include required sections.');
    $assert(str_contains($preparedNotes, sprintf('This is the `patch` framework release from `v%s` to `%s`', $currentFrameworkVersion, $expectedPreparedVersion)), 'Expected scaffolded release notes summary to be prefilled with the version transition.');
    $assert(str_contains($preparedNotes, sprintf('Downstream repositories pinned to an older `wp-core-base` release can update to `%s`', $expectedPreparedVersion)), 'Expected scaffolded release notes to include downstream impact guidance.');
    $assert(str_contains($preparedNotes, 'The published framework asset for this release is `wp-core-base-vendor-snapshot.zip`.'), 'Expected scaffolded release notes to include operational asset details.');
    $assert(str_contains($preparedNotes, 'publish the authoritative framework release artifact'), 'Expected scaffolded release notes to describe the singular authoritative release source contract.');
    $assert(
        str_contains(
            $preparedNotes,
            sprintf(
                '- %s `%s`',
                $frameworkConfig->baseline['managed_components'][0]['name'],
                $frameworkConfig->baseline['managed_components'][0]['version']
            )
        ),
        'Expected scaffolded release notes to include the bundled baseline component list.'
    );
    $assert(! str_contains($preparedNotes, 'Describe the framework changes in this release.'), 'Expected scaffolded release notes to avoid placeholder prose.');
    $refreshedRelease = (new FrameworkReleasePreparer($releasePrepRoot))->prepare('custom', $expectedPreparedVersion, true);
    $assert($refreshedRelease['version'] === $expectedPreparedVersion, 'Expected prepare-framework-release to allow refreshing the current version when explicitly requested.');
    $assert($refreshedRelease['release_notes_created'] === false, 'Expected refresh of existing release notes to avoid recreating the file.');
}
