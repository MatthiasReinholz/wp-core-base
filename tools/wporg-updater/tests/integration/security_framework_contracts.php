<?php

declare(strict_types=1);

use WpOrgPluginUpdater\Config;
use WpOrgPluginUpdater\FileChecksum;
use WpOrgPluginUpdater\FrameworkConfig;
use WpOrgPluginUpdater\FrameworkInstaller;
use WpOrgPluginUpdater\FrameworkReleaseArtifactBuilder;
use WpOrgPluginUpdater\FrameworkSyncer;
use WpOrgPluginUpdater\FrameworkWriter;
use WpOrgPluginUpdater\PrBodyRenderer;
use WpOrgPluginUpdater\ReleaseClassifier;
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
        $repoRuntimeInspector = new RuntimeInspector($config->runtime);
    (new FrameworkReleaseArtifactBuilder($repoRoot))->copySnapshotTo($payloadRoot);
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
    $installPlan = $installer->plan($payloadRoot, 'vendor/wp-core-base');
    $assert(in_array('.github/workflows/wp-core-base-self-update.yml', $installPlan['skipped_files'], true), 'Expected framework installer plans to surface customized managed workflows before apply.');
    $assert(in_array('.github/workflows/wporg-updates.yml', $installPlan['refreshed_files'], true), 'Expected framework installer plans to list framework-managed workflows that will refresh.');
    $assert(FrameworkConfig::load($tempScaffoldRoot)->version === $frameworkConfig->version, 'Expected framework installer planning to avoid mutating the pinned framework version.');
    $installResult = $installer->apply($payloadRoot, 'vendor/wp-core-base');
    $updatedFramework = FrameworkConfig::load($tempScaffoldRoot);
    $assert($updatedFramework->version === '1.0.1', 'Expected framework installer to update the pinned framework version.');
    $assert($updatedFramework->releaseSourceProvider() === $payloadFramework->releaseSourceProvider(), 'Expected framework installer to preserve the authoritative release source provider.');
    $assert($updatedFramework->releaseSourceReference() === $payloadFramework->releaseSourceReference(), 'Expected framework installer to preserve the authoritative release source reference.');
    $assert(in_array('.github/workflows/wp-core-base-self-update.yml', $installResult['skipped_files'], true), 'Expected customized framework-managed workflow to be skipped as drift.');
    $assert(in_array('.github/workflows/wporg-updates.yml', $installResult['refreshed_files'], true), 'Expected unchanged framework-managed workflow to refresh during framework install.');
    $assert(
        $updatedFramework->managedFiles()['.github/workflows/wp-core-base-self-update.yml'] === $scaffoldedFramework->managedFiles()['.github/workflows/wp-core-base-self-update.yml'],
        'Expected skipped managed file checksum to remain pinned to the previous managed version.'
    );
    $assert(file_exists($tempScaffoldRoot . '/vendor/wp-core-base/.wp-core-base/framework.php'), 'Expected framework installer to replace the vendored framework snapshot.');
    $assert(is_executable($tempScaffoldRoot . '/vendor/wp-core-base/bin/wp-core-base'), 'Expected framework installer to preserve the executable wrapper bit.');

    $legacyUntrackedCustomizedRoot = sys_get_temp_dir() . '/wporg-framework-legacy-untracked-customized-' . bin2hex(random_bytes(4));
    mkdir($legacyUntrackedCustomizedRoot, 0777, true);
    (new \WpOrgPluginUpdater\DownstreamScaffolder($repoRoot, $legacyUntrackedCustomizedRoot))->scaffold('vendor/wp-core-base', 'content-only', 'cms', true);
    (new FrameworkReleaseArtifactBuilder($repoRoot))->copySnapshotTo($legacyUntrackedCustomizedRoot . '/vendor/wp-core-base');
    $repoRuntimeInspector->clearPath($legacyUntrackedCustomizedRoot . '/vendor/wp-core-base/.git');
    $legacyUntrackedCustomizedFramework = FrameworkConfig::load($legacyUntrackedCustomizedRoot);
    $legacyManagedFiles = $legacyUntrackedCustomizedFramework->managedFiles();
    unset($legacyManagedFiles['.github/workflows/wp-core-base-self-update.yml']);
    (new FrameworkWriter())->write($legacyUntrackedCustomizedFramework->withInstalledRelease(
        version: $legacyUntrackedCustomizedFramework->version,
        wordPressCoreVersion: $legacyUntrackedCustomizedFramework->baseline['wordpress_core'],
        managedComponents: $legacyUntrackedCustomizedFramework->baseline['managed_components'],
        managedFiles: $legacyManagedFiles,
    ));
    $legacyCustomizedWorkflowPath = $legacyUntrackedCustomizedRoot . '/.github/workflows/wp-core-base-self-update.yml';
    $legacyCustomizedWorkflowContents = (string) file_get_contents($legacyCustomizedWorkflowPath) . "\n# preserved local customization\n";
    file_put_contents($legacyCustomizedWorkflowPath, $legacyCustomizedWorkflowContents);
    $legacyCustomizedPayloadRoot = sys_get_temp_dir() . '/wporg-framework-legacy-untracked-customized-payload-' . bin2hex(random_bytes(4));
        (new FrameworkReleaseArtifactBuilder($repoRoot))->copySnapshotTo($legacyCustomizedPayloadRoot);
    (new RuntimeInspector($config->runtime))->clearPath($legacyCustomizedPayloadRoot . '/.git');
    (new FrameworkWriter())->write(FrameworkConfig::load($legacyCustomizedPayloadRoot)->withInstalledRelease(
        version: '1.0.2',
        wordPressCoreVersion: '6.9.4',
        managedComponents: $frameworkConfig->baseline['managed_components'],
        managedFiles: [],
        distributionPath: '.'
    ));
    $legacyCustomizedPayloadTemplatePath = $legacyCustomizedPayloadRoot . '/tools/wporg-updater/templates/downstream-framework-self-update-workflow.yml.tpl';
    file_put_contents(
        $legacyCustomizedPayloadTemplatePath,
        str_replace(
            'framework-sync --repo-root=.',
            'framework-sync --repo-root=. --from-new-payload',
            (string) file_get_contents($legacyCustomizedPayloadTemplatePath)
        )
    );
    $legacyCustomizedInstall = (new FrameworkInstaller($legacyUntrackedCustomizedRoot, new RuntimeInspector(Config::load($legacyUntrackedCustomizedRoot)->runtime)))
        ->apply($legacyCustomizedPayloadRoot, 'vendor/wp-core-base');
    $legacyCustomizedUpdatedFramework = FrameworkConfig::load($legacyUntrackedCustomizedRoot);
    $assert(
        in_array('.github/workflows/wp-core-base-self-update.yml', $legacyCustomizedInstall['skipped_files'], true),
        'Expected legacy untracked customized framework-managed workflows to be preserved instead of overwritten.'
    );
    $assert(
        (string) file_get_contents($legacyCustomizedWorkflowPath) === $legacyCustomizedWorkflowContents,
        'Expected legacy untracked customized framework-managed workflows to keep their local contents.'
    );
    $assert(
        $legacyCustomizedUpdatedFramework->managedFiles()['.github/workflows/wp-core-base-self-update.yml'] === 'sha256:' . hash('sha256', $legacyCustomizedWorkflowContents),
        'Expected preserved legacy untracked managed workflows to be adopted into framework metadata using their current checksum.'
    );

    $legacyUntrackedRefreshRoot = sys_get_temp_dir() . '/wporg-framework-legacy-untracked-refresh-' . bin2hex(random_bytes(4));
    mkdir($legacyUntrackedRefreshRoot, 0777, true);
    (new \WpOrgPluginUpdater\DownstreamScaffolder($repoRoot, $legacyUntrackedRefreshRoot))->scaffold('vendor/wp-core-base', 'content-only', 'cms', true);
    (new FrameworkReleaseArtifactBuilder($repoRoot))->copySnapshotTo($legacyUntrackedRefreshRoot . '/vendor/wp-core-base');
    $repoRuntimeInspector->clearPath($legacyUntrackedRefreshRoot . '/vendor/wp-core-base/.git');
    $legacyUntrackedRefreshFramework = FrameworkConfig::load($legacyUntrackedRefreshRoot);
    $legacyRefreshManagedFiles = $legacyUntrackedRefreshFramework->managedFiles();
    unset($legacyRefreshManagedFiles['.github/workflows/wp-core-base-self-update.yml']);
    (new FrameworkWriter())->write($legacyUntrackedRefreshFramework->withInstalledRelease(
        version: $legacyUntrackedRefreshFramework->version,
        wordPressCoreVersion: $legacyUntrackedRefreshFramework->baseline['wordpress_core'],
        managedComponents: $legacyUntrackedRefreshFramework->baseline['managed_components'],
        managedFiles: $legacyRefreshManagedFiles,
    ));
    $legacyRefreshPayloadRoot = sys_get_temp_dir() . '/wporg-framework-legacy-untracked-refresh-payload-' . bin2hex(random_bytes(4));
        (new FrameworkReleaseArtifactBuilder($repoRoot))->copySnapshotTo($legacyRefreshPayloadRoot);
    (new RuntimeInspector($config->runtime))->clearPath($legacyRefreshPayloadRoot . '/.git');
    (new FrameworkWriter())->write(FrameworkConfig::load($legacyRefreshPayloadRoot)->withInstalledRelease(
        version: '1.0.3',
        wordPressCoreVersion: '6.9.4',
        managedComponents: $frameworkConfig->baseline['managed_components'],
        managedFiles: [],
        distributionPath: '.'
    ));
    $legacyRefreshPayloadTemplatePath = $legacyRefreshPayloadRoot . '/tools/wporg-updater/templates/downstream-framework-self-update-workflow.yml.tpl';
    file_put_contents(
        $legacyRefreshPayloadTemplatePath,
        str_replace(
            'Run framework self-update',
            'Run framework self-update from refreshed legacy payload',
            (string) file_get_contents($legacyRefreshPayloadTemplatePath)
        )
    );
    $legacyRefreshInstall = (new FrameworkInstaller($legacyUntrackedRefreshRoot, new RuntimeInspector(Config::load($legacyUntrackedRefreshRoot)->runtime)))
        ->apply($legacyRefreshPayloadRoot, 'vendor/wp-core-base');
    $legacyRefreshWorkflowPath = $legacyUntrackedRefreshRoot . '/.github/workflows/wp-core-base-self-update.yml';
    $legacyRefreshWorkflowContents = (string) file_get_contents($legacyRefreshWorkflowPath);
    $legacyRefreshUpdatedFramework = FrameworkConfig::load($legacyUntrackedRefreshRoot);
    $assert(
        in_array('.github/workflows/wp-core-base-self-update.yml', $legacyRefreshInstall['refreshed_files'], true),
        'Expected legacy untracked framework-managed workflows that still match the previous template to refresh normally.'
    );
    $assert(
        str_contains($legacyRefreshWorkflowContents, 'Run framework self-update from refreshed legacy payload'),
        'Expected legacy untracked framework-managed workflows that matched the previous template to refresh from the new payload.'
    );
    $assert(
        $legacyRefreshUpdatedFramework->managedFiles()['.github/workflows/wp-core-base-self-update.yml'] === 'sha256:' . hash('sha256', $legacyRefreshWorkflowContents),
        'Expected refreshed legacy untracked managed workflows to be tracked under their refreshed checksum.'
    );

    $strictCheckRoot = sys_get_temp_dir() . '/wporg-framework-strict-check-' . bin2hex(random_bytes(4));
    mkdir($strictCheckRoot, 0777, true);
    (new \WpOrgPluginUpdater\DownstreamScaffolder($repoRoot, $strictCheckRoot))->scaffold('vendor/wp-core-base', 'content-only', 'cms', true);
    (new FrameworkReleaseArtifactBuilder($repoRoot))->copySnapshotTo($strictCheckRoot . '/vendor/wp-core-base');
    $repoRuntimeInspector->clearPath($strictCheckRoot . '/vendor/wp-core-base/.git');
    $strictCheckWorkflowPath = $strictCheckRoot . '/.github/workflows/wp-core-base-self-update.yml';
    file_put_contents($strictCheckWorkflowPath, (string) file_get_contents($strictCheckWorkflowPath) . "\n# local customization\n");
    [$strictMajor, $strictMinor, $strictPatch] = array_map('intval', explode('.', $frameworkConfig->normalizedVersion()));
    $strictTargetVersion = sprintf('%d.%d.%d', $strictMajor, $strictMinor, $strictPatch + 1);
    $strictPayloadRoot = sys_get_temp_dir() . '/wporg-framework-strict-check-payload-' . bin2hex(random_bytes(4));
        (new FrameworkReleaseArtifactBuilder($repoRoot))->copySnapshotTo($strictPayloadRoot);
    (new RuntimeInspector($config->runtime))->clearPath($strictPayloadRoot . '/.git');
    (new FrameworkWriter())->write(FrameworkConfig::load($strictPayloadRoot)->withInstalledRelease(
        version: $strictTargetVersion,
        wordPressCoreVersion: '6.9.4',
        managedComponents: $frameworkConfig->baseline['managed_components'],
        managedFiles: [],
        distributionPath: '.'
    ));
    $strictPayloadTemplatePath = $strictPayloadRoot . '/tools/wporg-updater/templates/downstream-workflow.yml.tpl';
    file_put_contents(
        $strictPayloadTemplatePath,
        str_replace(
            'scheduled update PRs',
            'scheduled update PRs from strict check payload',
            (string) file_get_contents($strictPayloadTemplatePath)
        )
    );
    \WpOrgPluginUpdater\FrameworkReleasePayload::writeInventory($strictPayloadRoot, 'fixture');
    $strictArtifactPath = sys_get_temp_dir() . '/wporg-framework-strict-check-' . bin2hex(random_bytes(4)) . '.zip';
    $strictZip = new ZipArchive();
    $assert($strictZip->open($strictArtifactPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'Expected strict framework-sync fixture ZIP to be created.');
    $strictPayloadIterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($strictPayloadRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($strictPayloadIterator as $strictEntry) {
        $strictEntryPath = $strictEntry->getPathname();
        $strictRelativePath = ltrim(str_replace('\\', '/', substr($strictEntryPath, strlen($strictPayloadRoot))), '/');
        $strictArchivePath = 'wp-core-base/' . $strictRelativePath;

        if ($strictEntry->isDir()) {
            $strictZip->addEmptyDir($strictArchivePath);
            continue;
        }

        $strictZip->addFile($strictEntryPath, $strictArchivePath);
    }

    $strictZip->close();
    $strictReleaseSource = new class($strictArtifactPath, $strictTargetVersion) implements \WpOrgPluginUpdater\FrameworkReleaseSource
    {
        public function __construct(
            private readonly string $artifactPath,
            private readonly string $targetVersion,
        )
        {
        }

        public function fetchStableReleases(FrameworkConfig $framework): array
        {
            return [[
                'version' => $this->targetVersion,
                'release_at' => '2026-04-19T12:00:00+00:00',
            ]];
        }

        public function releaseData(FrameworkConfig $framework, array $release): array
        {
            return [
                'release' => $release,
                'version' => (string) $release['version'],
                'release_at' => (string) $release['release_at'],
                'release_url' => 'https://example.com/wp-core-base/releases/' . $this->targetVersion,
                'notes_sections' => [
                    'Summary' => 'Strict check fixture release.',
                    'Downstream Impact' => 'Check-only should expose skipped managed files.',
                    'Migration Notes' => 'Review customized workflows.',
                    'Downstream Workflow Changes' => 'Self-update workflow remains managed.',
                    'Required Downstream Actions' => 'Reconcile local customizations before rollout.',
                    'Bundled Baseline' => 'WordPress core 6.9.4',
                ],
            ];
        }

        public function downloadVerifiedReleaseAsset(FrameworkConfig $framework, array $release, string $destination): void
        {
            copy($this->artifactPath, $destination);
        }
    };
    run_framework_refresh_native_noop_contract_tests($assert, $repoRoot, $strictArtifactPath, $strictReleaseSource, $strictTargetVersion);
    $strictFrameworkSyncer = new FrameworkSyncer(
        framework: FrameworkConfig::load($strictCheckRoot),
        repoRoot: $strictCheckRoot,
        config: Config::load($strictCheckRoot),
        frameworkReleaseClient: $strictReleaseSource,
        releaseClassifier: new ReleaseClassifier(),
        prBodyRenderer: new PrBodyRenderer(),
        automationClient: null,
        gitRunner: new \FakeGitRunner(),
        runtimeInspector: new RuntimeInspector(Config::load($strictCheckRoot)->runtime),
    );
    $strictReport = $strictFrameworkSyncer->checkOnlyReport(true);
    $assert($strictReport['update_available'] === true, 'Expected framework-sync check-only reports to detect available framework updates.');
    $assert($strictReport['release_scope'] === 'patch', 'Expected framework-sync check-only reports to classify the pending release scope.');
    $assert(in_array('.github/workflows/wp-core-base-self-update.yml', $strictReport['skipped_files'], true), 'Expected framework-sync check-only reports to list customized managed files that would be skipped.');
    $assert(in_array('.github/workflows/wporg-updates.yml', $strictReport['refreshed_files'], true), 'Expected framework-sync check-only reports to list managed files that would refresh.');
    $assert($strictReport['would_fail_on_skipped_managed_files'] === true, 'Expected strict framework-sync check-only mode to report a failing preflight when managed files would be skipped.');
    $strictSyncerReflection = new ReflectionClass(FrameworkSyncer::class);
    $strictApplyMethod = $strictSyncerReflection->getMethod('checkoutAndApplyFrameworkVersion');
    $strictFailureRaised = false;

    try {
        $strictApplyMethod->invoke($strictFrameworkSyncer, 'main', 'codex/framework-strict-check', [
            'release' => ['version' => $strictTargetVersion, 'release_at' => '2026-04-19T12:00:00+00:00'],
            'version' => $strictTargetVersion,
            'release_at' => '2026-04-19T12:00:00+00:00',
            'release_url' => 'https://example.com/wp-core-base/releases/' . $strictTargetVersion,
            'target_wordpress_core' => '6.9.4',
            'notes_sections' => [],
        ], true);
    } catch (RuntimeException $exception) {
        $strictFailureRaised = str_contains($exception->getMessage(), '--fail-on-skipped-managed-files')
            && str_contains($exception->getMessage(), '.github/workflows/wp-core-base-self-update.yml');
    }

    $assert($strictFailureRaised, 'Expected strict framework-sync apply mode to abort when customized managed files would be skipped.');

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
        sourceReferenceLabel: 'Source repository',
        sourceReference: 'MatthiasReinholz/wp-core-base',
        sourceReferenceUrl: 'https://github.com/MatthiasReinholz/wp-core-base',
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

/** @param callable(bool,string):void $assert */
function run_framework_refresh_native_noop_contract_tests(callable $assert, string $repoRoot, string $archive, \WpOrgPluginUpdater\FrameworkReleaseSource $innerSource, string $targetVersion): void
{
    $root = sys_get_temp_dir() . '/framework-refresh-base-noop-' . bin2hex(random_bytes(6));
    mkdir($root, 0700, true);
    $run = static function (string $cwd, array $args): string {
        $process = proc_open($args, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
        if (! is_resource($process)) { throw new RuntimeException('Unable to start framework Git fixture.'); }
        $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process) !== 0) { throw new RuntimeException('Framework Git fixture failed: ' . substr((string) $err, 0, 2048)); }
        return trim((string) $out);
    };
    try {
        // Reuse the maintained detached-signature fixture and release-source seam.
        $checksum = $root . '/target.sha256'; $signature = $checksum . '.sig';
        file_put_contents($checksum, hash_file('sha256', $archive) . '  ' . basename($archive) . "\n");
        \WpOrgPluginUpdater\FrameworkReleaseSignature::signChecksumFile($checksum, $signature,
            (string) file_get_contents($repoRoot . '/tools/wporg-updater/tests/fixtures/release-signing/private.pem'));
        $source = new class($innerSource, $archive, $checksum, $signature, $repoRoot) implements \WpOrgPluginUpdater\FrameworkReleaseSource {
            public int $verifiedDownloads = 0;
            public function __construct(private readonly \WpOrgPluginUpdater\FrameworkReleaseSource $inner, private readonly string $archive, private readonly string $checksum, private readonly string $signature, private readonly string $repoRoot) {}
            public function fetchStableReleases(\WpOrgPluginUpdater\FrameworkConfig $framework): array { return $this->inner->fetchStableReleases($framework); }
            public function releaseData(\WpOrgPluginUpdater\FrameworkConfig $framework, array $release): array { return $this->inner->releaseData($framework, $release); }
            public function downloadVerifiedReleaseAsset(\WpOrgPluginUpdater\FrameworkConfig $framework, array $release, string $destination): void {
                \WpOrgPluginUpdater\FrameworkReleaseSignature::verifyChecksumFile($this->checksum, $this->signature, $this->repoRoot . '/tools/wporg-updater/tests/fixtures/release-signing/public.pem');
                \WpOrgPluginUpdater\FileChecksum::assertSha256Matches($this->archive,
                    \WpOrgPluginUpdater\FileChecksum::extractSha256ForAsset((string) file_get_contents($this->checksum), basename($this->archive)), 'Signed framework refresh fixture');
                $this->verifiedDownloads++;
                $this->inner->downloadVerifiedReleaseAsset($framework, $release, $destination);
            }
        };
        foreach ([false, true] as $baseSatisfied) {
            $case = $root . ($baseSatisfied ? '/satisfied' : '/pending'); mkdir($case);
            $work = $case . '/work'; mkdir($work);
            (new \WpOrgPluginUpdater\DownstreamScaffolder($repoRoot, $work))->scaffold('vendor/wp-core-base', 'content-only', 'cms', true);
            (new \WpOrgPluginUpdater\FrameworkReleaseArtifactBuilder($repoRoot))->copySnapshotTo($work . '/vendor/wp-core-base');
            $inspector = new \WpOrgPluginUpdater\RuntimeInspector(\WpOrgPluginUpdater\Config::load($work)->runtime);
            $payload = $case . '/target'; mkdir($payload);
            $zip = new ZipArchive();
            if ($zip->open($archive) !== true) { throw new RuntimeException('Unable to open existing target fixture.'); }
            \WpOrgPluginUpdater\ZipExtractor::extractValidated($zip, $payload); $zip->close();
            $installer = new \WpOrgPluginUpdater\FrameworkInstaller($work, $inspector);
            if ($baseSatisfied) { $installer->apply($payload . '/wp-core-base', 'vendor/wp-core-base'); }
            $run($case, ['git', 'init', '--bare', $case . '/remote.git']); $run($work, ['git', 'init', '-b', 'main']);
            $run($work, ['git', 'config', 'user.name', 'Framework refresh fixture']); $run($work, ['git', 'config', 'user.email', 'fixture@example.test']);
            $run($work, ['git', 'config', 'commit.gpgsign', 'false']);
            file_put_contents($work . '/.gitignore', "\n/.wp-core-base/build/\n", FILE_APPEND);
            $run($work, ['git', 'add', '.']); $run($work, ['git', 'commit', '--no-gpg-sign', '-m', 'Current framework base']);
            $run($work, ['git', 'remote', 'add', 'origin', $case . '/remote.git']); $run($work, ['git', 'push', '-u', 'origin', 'main']);
            $base = $run($work, ['git', 'rev-parse', 'HEAD']); $baseFramework = \WpOrgPluginUpdater\FrameworkConfig::load($work);
            $branch = 'codex/framework-native-refresh-fixture'; $run($work, ['git', 'checkout', '-b', $branch]);
            if (! $baseSatisfied) { $installer->apply($payload . '/wp-core-base', 'vendor/wp-core-base'); $run($work, ['git', 'add', '.']); $run($work, ['git', 'commit', '--no-gpg-sign', '-m', 'Existing verified framework target']); }
            $head = $run($work, ['git', 'rev-parse', 'HEAD']); $run($work, ['git', 'push', 'origin', $branch]); $run($work, ['git', 'checkout', 'main']);
            $client = new FakeGitHubAutomationClient(); $git = new \WpOrgPluginUpdater\GitCommandRunner($work);
            $config = \WpOrgPluginUpdater\Config::load($work);
            $syncer = new \WpOrgPluginUpdater\FrameworkSyncer($baseFramework, $work, $config, $source,
                new \WpOrgPluginUpdater\ReleaseClassifier(), new \WpOrgPluginUpdater\PrBodyRenderer(), $client, $git, $inspector);
            $pr = ['number' => 42, 'state' => 'open', 'draft' => false, 'labels' => [['name' => 'automation:framework-update']],
                'metadata' => ['component_key' => 'framework:wp-core-base', 'branch' => $branch, 'base_version' => $baseFramework->version],
                'head' => ['ref' => $branch, 'sha' => $head, 'repo' => ['full_name' => 'example/site']], 'base' => ['ref' => 'main', 'repo' => ['full_name' => 'example/site']],
                'planned_target_version' => $targetVersion, 'planned_release_at' => '2026-04-19T12:00:00Z', 'planned_scope' => 'patch', 'requires_code_update' => true];
            $pr['body'] = '<!-- wporg-update-metadata: ' . json_encode($pr['metadata'], JSON_THROW_ON_ERROR) . ' -->';
            $beforeDownloads = $source->verifiedDownloads;
            $result = (new ReflectionMethod(\WpOrgPluginUpdater\FrameworkSyncer::class, 'refreshPullRequest'))->invoke($syncer, $pr, [], [], 'main', $base, true, $baseFramework);
            $remote = $run($work, ['git', 'ls-remote', '--refs', 'origin', 'refs/heads/' . $branch]);
            if ($baseSatisfied) {
                $assert(! $result && count($client->closedPullRequests) === 1 && $remote === '', 'A framework target actually satisfied by base must close with exact owned-ref cleanup.');
                $assert($source->verifiedDownloads === $beforeDownloads, 'A satisfied framework base must not download or reinstall the release.');
            } else {
                $assert($result && $client->closedPullRequests === [] && count($client->updatedPullRequests) === 1, 'A signed already-current framework branch must remain open against an older base.');
                $assert(str_starts_with($remote, $head) && $git->successfulPushRevision($branch) === null, 'An identical framework refresh must preserve its exact remote head without a phantom push.');
                $assert($source->verifiedDownloads === $beforeDownloads + 2, 'Metadata and install refresh must both retain real detached-signature and checksum verification.');
            }
            $assert($run($work, ['git', 'rev-parse', 'origin/main']) === $base && $run($work, ['git', 'status', '--porcelain']) === '', 'Framework refresh must preserve current base and return a clean checkout.');
        }
    } finally { (new \WpOrgPluginUpdater\RuntimeInspector([]))->clearPath($root); }
}
