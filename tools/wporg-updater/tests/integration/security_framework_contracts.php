<?php

declare(strict_types=1);

use WpOrgPluginUpdater\Config;
use WpOrgPluginUpdater\FileChecksum;
use WpOrgPluginUpdater\FrameworkConfig;
use WpOrgPluginUpdater\FrameworkInstaller;
use WpOrgPluginUpdater\FrameworkWriter;
use WpOrgPluginUpdater\PrBodyRenderer;
use WpOrgPluginUpdater\RuntimeInspector;
use WpOrgPluginUpdater\WordPressCoreClient;

/**
 * @param callable(bool,string):void $assert
 */
function run_security_framework_contract_tests(
    callable $assert,
    string $repoRoot,
    Config $config,
    FrameworkConfig $frameworkConfig,
    string $tempScaffoldRoot,
    FrameworkConfig $scaffoldedFramework,
    string $fixtureDir,
    WordPressCoreClient $coreClient,
    PrBodyRenderer $renderer
): void {
    $verifierReflection = new ReflectionClass(\WpOrgPluginUpdater\FrameworkReleaseVerifier::class);
    $extractChecksum = $verifierReflection->getMethod('extractChecksum');
    $extractChecksum->setAccessible(true);
    $checksumRejected = false;

    try {
        $extractChecksum->invoke(new \WpOrgPluginUpdater\FrameworkReleaseVerifier($repoRoot), "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa  wrong-file.zip\n", 'wp-core-base-vendor-snapshot.zip');
    } catch (RuntimeException $exception) {
        $checksumRejected = str_contains($exception->getMessage(), 'expected');
    }

    $assert($checksumRejected, 'Expected framework release verification to reject checksum lines bound to the wrong artifact name.');
    $checksumFixture = sys_get_temp_dir() . '/wporg-file-checksum-' . bin2hex(random_bytes(4)) . '.txt';
    file_put_contents($checksumFixture, "fixture\n");
    FileChecksum::assertSha256Matches($checksumFixture, 'sha256:' . hash_file('sha256', $checksumFixture), 'checksum fixture');
    $assert(
        FileChecksum::extractSha256ForAsset(str_repeat('a', 64) . "  fixture.zip\n", 'fixture.zip') === str_repeat('a', 64),
        'Expected generic checksum parsing to return the digest bound to the expected asset filename.'
    );

    $payloadRoot = sys_get_temp_dir() . '/wporg-framework-payload-' . bin2hex(random_bytes(4));
    mkdir($payloadRoot, 0777, true);
    $repoRuntimeInspector = new RuntimeInspector($config->runtime);
    $repoRuntimeInspector->clearPath($repoRoot . '/.wp-core-base/build');
    $repoRuntimeInspector->copyPath($repoRoot, $payloadRoot);
    (new RuntimeInspector($config->runtime))->clearPath($payloadRoot . '/.git');
    $payloadFramework = FrameworkConfig::load($payloadRoot)->withInstalledRelease(
        version: '1.0.1',
        wordPressCoreVersion: '6.9.4',
        managedComponents: $frameworkConfig->baseline['managed_components'],
        managedFiles: [],
        distributionPath: '.'
    );
    (new FrameworkWriter())->write($payloadFramework);
    $payloadTemplatePath = $payloadRoot . '/tools/wporg-updater/templates/downstream-workflow.yml.tpl';
    file_put_contents($payloadTemplatePath, str_replace('scheduled update PRs', 'scheduled update PRs from a newer framework release', (string) file_get_contents($payloadTemplatePath)));
    $customizedWorkflowPath = $tempScaffoldRoot . '/.github/workflows/wp-core-base-self-update.yml';
    file_put_contents($customizedWorkflowPath, (string) file_get_contents($customizedWorkflowPath) . "\n# local customization\n");
    $installer = new FrameworkInstaller($tempScaffoldRoot, new RuntimeInspector(Config::load($tempScaffoldRoot)->runtime));
    $installResult = $installer->apply($payloadRoot, 'vendor/wp-core-base');
    $updatedFramework = FrameworkConfig::load($tempScaffoldRoot);
    $assert($updatedFramework->version === '1.0.1', 'Expected framework installer to update the pinned framework version.');
    $assert(in_array('.github/workflows/wp-core-base-self-update.yml', $installResult['skipped_files'], true), 'Expected customized framework-managed workflow to be skipped as drift.');
    $assert(in_array('.github/workflows/wporg-updates.yml', $installResult['refreshed_files'], true), 'Expected unchanged framework-managed workflow to refresh during framework install.');
    $assert(
        $updatedFramework->managedFiles()['.github/workflows/wp-core-base-self-update.yml'] === $scaffoldedFramework->managedFiles()['.github/workflows/wp-core-base-self-update.yml'],
        'Expected skipped managed file checksum to remain pinned to the previous managed version.'
    );
    $assert(file_exists($tempScaffoldRoot . '/vendor/wp-core-base/.wp-core-base/framework.php'), 'Expected framework installer to replace the vendored framework snapshot.');
    $assert(is_executable($tempScaffoldRoot . '/vendor/wp-core-base/bin/wp-core-base'), 'Expected framework installer to preserve the executable wrapper bit.');

    $corePayload = json_decode((string) file_get_contents($fixtureDir . '/wp-core-version-check.json'), true, 512, JSON_THROW_ON_ERROR);
    $coreOffer = $coreClient->parseLatestStableOffer($corePayload);
    $assert($coreOffer['version'] === '6.9.4', 'Expected latest stable core offer to be 6.9.4 in fixture.');

    $coreRelease = $coreClient->findReleaseAnnouncementInFeed((string) file_get_contents($fixtureDir . '/wp-release-feed.xml'), '6.9.4');
    $assert($coreRelease['release_url'] === 'https://wordpress.org/news/2026/03/wordpress-6-9-4-release/', 'Expected release feed lookup to find the core announcement URL.');
    $assert(str_contains($coreRelease['release_text'], 'security'), 'Expected release summary to include security context.');

    $coreMetadata = PrBodyRenderer::extractMetadata($renderer->renderCoreUpdate(
        currentVersion: '6.9.3',
        targetVersion: '6.9.4',
        releaseScope: 'patch',
        releaseAt: '2026-03-11T15:34:58+00:00',
        labels: ['component:wordpress-core', 'release:patch', 'type:security-bugfix'],
        releaseUrl: $coreRelease['release_url'],
        downloadUrl: 'https://downloads.wordpress.org/release/wordpress-6.9.4.zip',
        releaseHtml: $coreRelease['release_html'],
        metadata: [
            'kind' => 'core',
            'slug' => 'wordpress-core',
            'target_version' => '6.9.4',
            'release_at' => '2026-03-11T15:34:58+00:00',
            'scope' => 'patch',
            'branch' => 'codex/wordpress-core-6-9-4',
            'blocked_by' => [],
        ],
    ));
    $assert(is_array($coreMetadata) && $coreMetadata['kind'] === 'core', 'Expected core PR body metadata round-trip to work.');

    $frameworkMetadata = PrBodyRenderer::extractMetadata($renderer->renderFrameworkUpdate(
        currentVersion: '1.0.0',
        targetVersion: '1.0.1',
        releaseScope: 'patch',
        releaseAt: '2026-03-22T10:00:00+00:00',
        labels: ['automation:framework-update', 'component:framework', 'release:patch'],
        sourceRepository: 'MatthiasReinholz/wp-core-base',
        releaseUrl: 'https://github.com/MatthiasReinholz/wp-core-base/releases/tag/v1.0.1',
        currentBaseline: '6.9.4',
        targetBaseline: '6.9.4',
        notesSections: [
            'Summary' => 'Patch release.',
            'Downstream Impact' => 'Safe update.',
            'Migration Notes' => 'None.',
            'Bundled Baseline' => 'WordPress core 6.9.4',
        ],
        skippedManagedFiles: [],
        metadata: [
            'component_key' => 'framework:wp-core-base',
            'slug' => 'wp-core-base',
            'target_version' => '1.0.1',
            'release_at' => '2026-03-22T10:00:00+00:00',
            'scope' => 'patch',
            'branch' => 'codex/framework-1-0-1',
            'blocked_by' => [],
        ],
    ));
    $assert(is_array($frameworkMetadata) && $frameworkMetadata['slug'] === 'wp-core-base', 'Expected framework PR metadata to include a slug for blocker compatibility.');
}
