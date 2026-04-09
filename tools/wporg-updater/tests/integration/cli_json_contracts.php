<?php

declare(strict_types=1);

use WpOrgPluginUpdater\CoreScanner;
use WpOrgPluginUpdater\FrameworkReleaseArtifactBuilder;
use WpOrgPluginUpdater\RuntimeInspector;
use WpOrgPluginUpdater\ZipExtractor;

/**
 * @param callable(bool,string):void $assert
 * @param callable(string,array<int,array<string,mixed>>):void $writeManifest
 * @param callable(string,string,string):void $writePremiumProvider
 */
function run_cli_json_contract_tests(
    callable $assert,
    string $repoRoot,
    RuntimeInspector $runtimeInspector,
    string $currentFrameworkVersion,
    callable $writeManifest,
    callable $writePremiumProvider
): void {
    $artifactFixturePath = sys_get_temp_dir() . '/wporg-release-artifact-' . bin2hex(random_bytes(4)) . '.zip';
    $artifactBuilder = new FrameworkReleaseArtifactBuilder($repoRoot);
    $artifactBuild = $artifactBuilder->build($artifactFixturePath);
    $assert(is_file($artifactBuild['artifact']), 'Expected the framework artifact builder to create the vendored snapshot ZIP.');
    $assert(is_file($artifactBuild['checksum_file']), 'Expected the framework artifact builder to create the checksum sidecar.');
    $artifactExtractRoot = sys_get_temp_dir() . '/wporg-release-artifact-extract-' . bin2hex(random_bytes(4));
    mkdir($artifactExtractRoot, 0777, true);
    $artifactZip = new ZipArchive();
    $assert($artifactZip->open($artifactFixturePath) === true, 'Expected to reopen the built framework artifact.');
    ZipExtractor::extractValidated($artifactZip, $artifactExtractRoot);
    $artifactZip->close();
    $assert(! file_exists($artifactExtractRoot . '/wp-core-base/tools/wporg-updater/.tmp'), 'Expected release artifacts to exclude temp paths.');
    $assert(! file_exists($artifactExtractRoot . '/wp-core-base/tools/wporg-updater/tests'), 'Expected release artifacts to exclude framework tests.');
    $assert(! file_exists($artifactExtractRoot . '/wp-core-base/.github'), 'Expected release artifacts to exclude upstream workflow definitions.');
    $releaseVerifyArtifactFailure = run_command_json_allow_failure($repoRoot, [
        'php',
        'tools/wporg-updater/bin/wporg-updater.php',
        'release-verify',
        '--repo-root=.',
        '--artifact=' . $artifactBuild['artifact'],
        '--checksum-file=' . $artifactBuild['checksum_file'],
        '--json',
    ]);
    $assert($releaseVerifyArtifactFailure['exit_code'] === 1, 'Expected release-verify --json to fail when artifact verification omits the detached signature.');
    $assert(str_contains((string) ($releaseVerifyArtifactFailure['payload']['error'] ?? ''), '--signature-file'), 'Expected release-verify --json failure to explain that signature-backed verification is required.');
    $runtimeInspector->clearPath($artifactExtractRoot);
    @unlink($artifactFixturePath);
    @unlink($artifactBuild['checksum_file']);

    $doctorJson = run_command_json($repoRoot, [
        'php',
        'tools/wporg-updater/bin/wporg-updater.php',
        'doctor',
        '--repo-root=.',
        '--json',
    ]);
    $assert(($doctorJson['status'] ?? null) === 'success', 'Expected doctor --json to report success for the local repository without live GitHub requirements.');
    $assert(is_array($doctorJson['messages'] ?? null), 'Expected doctor --json to include structured messages.');

    $stageRuntimeJsonOutput = '.wp-core-base/build/runtime-json';
    $stageRuntimeJson = run_command_json($repoRoot, [
        'php',
        'tools/wporg-updater/bin/wporg-updater.php',
        'stage-runtime',
        '--repo-root=.',
        '--output=' . $stageRuntimeJsonOutput,
        '--json',
    ]);
    $assert(($stageRuntimeJson['status'] ?? null) === 'success', 'Expected stage-runtime --json to report success.');
    $assert(in_array('wp-content/plugins/woocommerce', (array) ($stageRuntimeJson['staged_paths'] ?? []), true), 'Expected stage-runtime --json to include staged runtime paths.');
    $runtimeInspector->clearPath($repoRoot . '/' . $stageRuntimeJsonOutput);

    $releaseVerifyJson = run_command_json($repoRoot, [
        'php',
        'tools/wporg-updater/bin/wporg-updater.php',
        'release-verify',
        '--repo-root=.',
        '--json',
    ]);
    $assert(($releaseVerifyJson['status'] ?? null) === 'success', 'Expected release-verify --json to report success.');
    $assert(($releaseVerifyJson['release_tag'] ?? null) === 'v' . $currentFrameworkVersion, 'Expected release-verify --json to report the resolved release tag.');

    $unknownModeJson = run_command_json_allow_failure($repoRoot, [
        'php',
        'tools/wporg-updater/bin/wporg-updater.php',
        'definitely-not-a-real-mode',
        '--json',
    ]);
    $assert($unknownModeJson['exit_code'] === 2, 'Expected unknown CLI modes to preserve the dispatch failure exit code under --json.');
    $assert(str_contains((string) ($unknownModeJson['payload']['error'] ?? ''), 'Unknown mode:'), 'Expected unknown CLI modes to return structured JSON errors.');

    $managedPlanJsonRoot = sys_get_temp_dir() . '/wporg-authoring-plan-json-' . bin2hex(random_bytes(4));
    mkdir($managedPlanJsonRoot . '/cms/plugins', 0777, true);
    $writeManifest($managedPlanJsonRoot);
    $writePremiumProvider($managedPlanJsonRoot);
    $managedPlanJson = run_command_json($managedPlanJsonRoot, [
        'php',
        $repoRoot . '/tools/wporg-updater/bin/wporg-updater.php',
        'add-dependency',
        '--repo-root=.',
        '--source=premium',
        '--provider=test-provider',
        '--kind=plugin',
        '--slug=premium-plan-plugin',
        '--plan',
        '--json',
    ]);
    $assert(($managedPlanJson['status'] ?? null) === 'success', 'Expected add-dependency --plan --json to report success.');
    $assert(($managedPlanJson['operation'] ?? null) === 'add-dependency', 'Expected add-dependency --plan --json to report the operation type.');
    $assert(($managedPlanJson['selected_version'] ?? null) === '2.3.4', 'Expected add-dependency --plan --json to report the selected version.');
    $assert(($managedPlanJson['component_key'] ?? null) === 'plugin:premium:test-provider:premium-plan-plugin', 'Expected add-dependency --plan --json to report the provider-aware component key.');
    $assert(str_contains((string) ($managedPlanJson['source_reference'] ?? ''), 'https://example.com/test-provider'), 'Expected add-dependency --plan --json to report the resolved provider source reference.');

    $adoptJsonRoot = sys_get_temp_dir() . '/wporg-authoring-adopt-json-' . bin2hex(random_bytes(4));
    mkdir($adoptJsonRoot . '/cms/plugins/premium-plan-plugin', 0777, true);
    $writePremiumProvider($adoptJsonRoot);
    file_put_contents(
        $adoptJsonRoot . '/cms/plugins/premium-plan-plugin/premium-plan-plugin.php',
        "<?php\n/*\nPlugin Name: Premium Plan Plugin\nVersion: 2.3.4\n*/\n"
    );
    $writeManifest($adoptJsonRoot, [[
        'name' => 'Premium Plan Plugin',
        'slug' => 'premium-plan-plugin',
        'kind' => 'plugin',
        'management' => 'local',
        'source' => 'local',
        'path' => 'cms/plugins/premium-plan-plugin',
        'main_file' => 'premium-plan-plugin.php',
        'version' => null,
        'checksum' => null,
        'archive_subdir' => '',
        'extra_labels' => [],
        'source_config' => ['github_repository' => null, 'github_release_asset_pattern' => null, 'github_token_env' => null, 'credential_key' => null, 'provider' => null, 'provider_product_id' => null],
        'policy' => ['class' => 'local-owned', 'allow_runtime_paths' => [], 'strip_paths' => [], 'strip_files' => [], 'sanitize_paths' => [], 'sanitize_files' => []],
    ]]);

    $adoptPlanJson = run_command_json($adoptJsonRoot, [
        'php',
        $repoRoot . '/tools/wporg-updater/bin/wporg-updater.php',
        'adopt-dependency',
        '--repo-root=.',
        '--kind=plugin',
        '--slug=premium-plan-plugin',
        '--source=premium',
        '--provider=test-provider',
        '--preserve-version',
        '--plan',
        '--json',
    ]);
    $assert(($adoptPlanJson['status'] ?? null) === 'success', 'Expected adopt-dependency --plan --json to report success.');
    $assert(($adoptPlanJson['operation'] ?? null) === 'adopt-dependency', 'Expected adopt-dependency --plan --json to report the operation type.');
    $assert(($adoptPlanJson['adopted_from'] ?? null) === 'plugin:local:premium-plan-plugin', 'Expected adopt-dependency --plan --json to identify the source dependency.');
    $assert(($adoptPlanJson['selected_version'] ?? null) === '2.3.4', 'Expected adopt-dependency --plan --json to preserve the current local version through the plan.');

    ZipExtractor::assertSafeEntryName('wordpress/wp-includes/version.php');
    $zipTraversalRejected = false;

    try {
        ZipExtractor::assertSafeEntryName('../escape.php');
    } catch (RuntimeException) {
        $zipTraversalRejected = true;
    }

    $assert($zipTraversalRejected, 'Expected ZipExtractor to reject path traversal entries.');

    $zipBombPath = sys_get_temp_dir() . '/wporg-zip-bomb-' . bin2hex(random_bytes(4)) . '.zip';
    $zipBomb = new ZipArchive();
    $assert($zipBomb->open($zipBombPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'Expected to create ZIP bomb fixture.');
    $zipBomb->addFromString('bomb.txt', str_repeat('A', 1024 * 1024));
    $zipBomb->close();
    $zipBombRejected = false;
    $zipBombReader = new ZipArchive();
    $assert($zipBombReader->open($zipBombPath) === true, 'Expected to reopen ZIP bomb fixture.');

    try {
        ZipExtractor::extractValidated($zipBombReader, sys_get_temp_dir() . '/wporg-zip-bomb-extract-' . bin2hex(random_bytes(4)));
    } catch (RuntimeException $exception) {
        $zipBombRejected = str_contains($exception->getMessage(), 'compression ratio');
    }

    $zipBombReader->close();
    $assert($zipBombRejected, 'Expected ZipExtractor to reject suspicious compression ratios.');

    $tempCoreRoot = sys_get_temp_dir() . '/wporg-core-scanner-' . bin2hex(random_bytes(4));
    mkdir($tempCoreRoot . '/wp-includes', 0777, true);
    file_put_contents($tempCoreRoot . '/wp-includes/version.php', "<?php\n\$wp_version = '6.9.4';\n");
    $coreScan = (new CoreScanner())->inspect($tempCoreRoot);
    $assert($coreScan['version'] === '6.9.4', 'Expected CoreScanner to parse $wp_version correctly.');
}

/**
 * @param list<string> $command
 * @return array<string, mixed>
 */
function run_command_json(string $cwd, array $command): array
{
    $result = run_command_json_allow_failure($cwd, $command);

    if ($result['exit_code'] !== 0) {
        throw new RuntimeException(sprintf(
            'Command failed unexpectedly: %s (%s)',
            implode(' ', $command),
            $result['payload']['error'] ?? 'unknown error'
        ));
    }

    return $result['payload'];
}

/**
 * @param list<string> $command
 * @return array{exit_code:int,payload:array<string,mixed>}
 */
function run_command_json_allow_failure(string $cwd, array $command): array
{
    $descriptor = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptor, $pipes, $cwd);

    if (! is_resource($process)) {
        throw new RuntimeException(sprintf('Unable to start command: %s', implode(' ', $command)));
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);

    if (! is_string($stdout) || trim($stdout) === '') {
        throw new RuntimeException(sprintf(
            "Command did not produce JSON output in %s: %s\n%s",
            $cwd,
            implode(' ', $command),
            is_string($stderr) ? $stderr : ''
        ));
    }

    $decoded = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);

    if (! is_array($decoded)) {
        throw new RuntimeException(sprintf('Command did not return a JSON object: %s', implode(' ', $command)));
    }

    return [
        'exit_code' => $status,
        'payload' => $decoded,
    ];
}
