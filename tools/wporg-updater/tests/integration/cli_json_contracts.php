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

    $parallelDoctorResults = run_commands_json_allow_failure_parallel($repoRoot, [
        [
            'php',
            'tools/wporg-updater/bin/wporg-updater.php',
            'doctor',
            '--repo-root=.',
            '--json',
        ],
        [
            'php',
            'tools/wporg-updater/bin/wporg-updater.php',
            'doctor',
            '--repo-root=.',
            '--json',
        ],
    ]);
    $assert(count($parallelDoctorResults) === 2, 'Expected two doctor --json results for concurrent runtime staging isolation coverage.');

    foreach ($parallelDoctorResults as $parallelDoctorResult) {
        $payload = $parallelDoctorResult['payload'];
        $assert($parallelDoctorResult['exit_code'] === 0, 'Expected concurrent doctor --json invocations to succeed.');
        $assert(is_array($payload['messages'] ?? null), 'Expected concurrent doctor --json output to preserve the messages array.');
        $assert(array_key_exists('status', $payload), 'Expected concurrent doctor --json output to preserve the status key.');
        $assert(array_key_exists('error_count', $payload), 'Expected concurrent doctor --json output to preserve the error_count key.');
        $assert(array_key_exists('warning_count', $payload), 'Expected concurrent doctor --json output to preserve the warning_count key.');
    }

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

    $unsafeStageRuntimeJson = run_command_json_allow_failure($repoRoot, [
        'php',
        'tools/wporg-updater/bin/wporg-updater.php',
        'stage-runtime',
        '--repo-root=.',
        '--output=../outside-runtime',
        '--json',
    ]);
    $assert($unsafeStageRuntimeJson['exit_code'] === 1, 'Expected stage-runtime --json to fail for traversal output overrides.');
    $assert(
        str_contains((string) ($unsafeStageRuntimeJson['payload']['error'] ?? ''), 'repo-relative')
        || str_contains((string) ($unsafeStageRuntimeJson['payload']['error'] ?? ''), 'traversal'),
        'Expected stage-runtime --json to explain that output overrides must remain repo-relative.'
    );

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

    $unknownFlagJson = run_command_json_allow_failure($repoRoot, [
        'php',
        'tools/wporg-updater/bin/wporg-updater.php',
        'sync',
        '--report-json=.wp-core-base/build/sync-report.json',
        '--typoed-flag',
        '--json',
    ]);
    $assert($unknownFlagJson['exit_code'] === 2, 'Expected unknown CLI flags to fail with exit code 2 in JSON mode.');
    $assert(str_contains((string) ($unknownFlagJson['payload']['error'] ?? ''), '--typoed-flag'), 'Expected unknown CLI flag errors to include the offending flag name.');

    $unknownFlagPlain = run_command_allow_failure($repoRoot, [
        'php',
        'tools/wporg-updater/bin/wporg-updater.php',
        'sync',
        '--typoed-flag',
    ]);
    $assert($unknownFlagPlain['exit_code'] === 2, 'Expected unknown CLI flags to fail with exit code 2 in plain mode.');
    $assert(str_contains($unknownFlagPlain['stderr'], '--typoed-flag'), 'Expected plain unknown-flag errors to include the offending flag name.');
    $assert(str_contains($unknownFlagPlain['stderr'], 'help'), 'Expected plain unknown-flag errors to point users to grouped help.');

    $helpWithOptionBeforeTopic = run_command_allow_failure($repoRoot, [
        'php',
        'tools/wporg-updater/bin/wporg-updater.php',
        'help',
        '--json',
        'sync',
    ]);
    $assert($helpWithOptionBeforeTopic['exit_code'] === 0, 'Expected help mode to succeed when options precede a topic token.');
    $assert(str_contains($helpWithOptionBeforeTopic['stdout'], "sync\n\nPurpose:"), 'Expected help mode to preserve the topic token when boolean options are present.');

    $doctorJsonSplitRepoRoot = run_command_json($repoRoot, [
        'php',
        'tools/wporg-updater/bin/wporg-updater.php',
        'doctor',
        '--repo-root',
        '.',
        '--json',
    ]);
    $assert(($doctorJsonSplitRepoRoot['status'] ?? null) === 'success', 'Expected split-form --repo-root value parsing to work in JSON mode.');

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

    $listDependenciesJson = run_command_json($adoptJsonRoot, [
        'php',
        $repoRoot . '/tools/wporg-updater/bin/wporg-updater.php',
        'list-dependencies',
        '--repo-root=.',
        '--freshness',
        '--json',
    ]);
    $assert(($listDependenciesJson['operation'] ?? null) === 'list-dependencies', 'Expected list-dependencies --json to report the operation type.');
    $assert(is_array($listDependenciesJson['dependencies'] ?? null), 'Expected list-dependencies --json to include structured dependencies.');
    $firstListedDependency = is_array($listDependenciesJson['dependencies'][0] ?? null) ? $listDependenciesJson['dependencies'][0] : [];
    $assert(array_key_exists('freshness', $firstListedDependency) && $firstListedDependency['freshness'] === null, 'Expected local entries to use null freshness in freshness reports.');

    $inspectReleaseAssetsMissingRepository = run_command_json_allow_failure($repoRoot, [
        'php',
        'tools/wporg-updater/bin/wporg-updater.php',
        'inspect-release-assets',
        '--repo-root=.',
        '--source=github-release',
        '--json',
    ]);
    $assert($inspectReleaseAssetsMissingRepository['exit_code'] === 1, 'Expected inspect-release-assets --json to fail when required source options are missing.');
    $assert(str_contains((string) ($inspectReleaseAssetsMissingRepository['payload']['error'] ?? ''), '--github-repository'), 'Expected inspect-release-assets --json to identify the missing GitHub repository option.');

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

    $zipSymlinkPath = sys_get_temp_dir() . '/wporg-zip-symlink-' . bin2hex(random_bytes(4)) . '.zip';
    $zipSymlink = new ZipArchive();
    $assert($zipSymlink->open($zipSymlinkPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'Expected to create symlink ZIP fixture.');
    $zipSymlink->addFromString('symlink-entry', 'target');
    $zipSymlink->setExternalAttributesName('symlink-entry', ZipArchive::OPSYS_UNIX, 0120777 << 16);
    $zipSymlink->close();
    $zipSymlinkRejected = false;
    $zipSymlinkReader = new ZipArchive();
    $assert($zipSymlinkReader->open($zipSymlinkPath) === true, 'Expected to reopen symlink ZIP fixture.');

    try {
        ZipExtractor::extractValidated($zipSymlinkReader, sys_get_temp_dir() . '/wporg-zip-symlink-extract-' . bin2hex(random_bytes(4)));
    } catch (RuntimeException $exception) {
        $zipSymlinkRejected = str_contains($exception->getMessage(), 'symlink');
    }

    $zipSymlinkReader->close();
    $assert($zipSymlinkRejected, 'Expected ZipExtractor to reject extracted archive symlink entries.');

    $oldGitHubRepository = getenv('GITHUB_REPOSITORY');
    $oldGitHubToken = getenv('GITHUB_TOKEN');
    $oldGitHubApiUrl = getenv('GITHUB_API_URL');
    putenv('GITHUB_REPOSITORY=example/repo');
    putenv('GITHUB_TOKEN=super-secret-token');
    putenv('GITHUB_API_URL=https://user:pass@example.invalid/api?token=secret-token');

    $doctorRedactedJson = run_command_json($repoRoot, [
        'php',
        'tools/wporg-updater/bin/wporg-updater.php',
        'doctor',
        '--repo-root=.',
        '--json',
    ]);
    $doctorMessages = json_encode($doctorRedactedJson['messages'] ?? [], JSON_THROW_ON_ERROR);
    $assert(! str_contains((string) $doctorMessages, 'super-secret-token'), 'Expected doctor --json to redact secret environment values.');
    $assert(! str_contains((string) $doctorMessages, 'user:pass@'), 'Expected doctor --json to redact credential-bearing URLs.');
    $assert(str_contains((string) $doctorMessages, '[REDACTED]'), 'Expected doctor --json redaction markers in sanitized messages.');

    $doctorRedactedPlain = run_command_allow_failure($repoRoot, [
        'php',
        'tools/wporg-updater/bin/wporg-updater.php',
        'doctor',
        '--repo-root=.',
    ]);
    $assert($doctorRedactedPlain['exit_code'] === 0, 'Expected doctor plain output run to succeed in redaction regression test.');
    $assert(! str_contains($doctorRedactedPlain['stdout'], 'super-secret-token'), 'Expected doctor plain output to redact secret environment values.');
    $assert(! str_contains($doctorRedactedPlain['stdout'], 'user:pass@'), 'Expected doctor plain output to redact credential-bearing URLs.');
    $assert(str_contains($doctorRedactedPlain['stdout'], '[REDACTED]'), 'Expected doctor plain output to include redaction markers.');

    if ($oldGitHubRepository === false) {
        putenv('GITHUB_REPOSITORY');
    } else {
        putenv('GITHUB_REPOSITORY=' . $oldGitHubRepository);
    }

    if ($oldGitHubToken === false) {
        putenv('GITHUB_TOKEN');
    } else {
        putenv('GITHUB_TOKEN=' . $oldGitHubToken);
    }

    if ($oldGitHubApiUrl === false) {
        putenv('GITHUB_API_URL');
    } else {
        putenv('GITHUB_API_URL=' . $oldGitHubApiUrl);
    }

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
 * @return array{exit_code:int,stdout:string,stderr:string}
 */
function run_command_allow_failure(string $cwd, array $command): array
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

    return [
        'exit_code' => $status,
        'stdout' => is_string($stdout) ? $stdout : '',
        'stderr' => is_string($stderr) ? $stderr : '',
    ];
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

/**
 * @param list<list<string>> $commands
 * @return list<array{exit_code:int,payload:array<string,mixed>}>
 */
function run_commands_json_allow_failure_parallel(string $cwd, array $commands): array
{
    $descriptor = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $handles = [];

    foreach ($commands as $index => $command) {
        $process = proc_open($command, $descriptor, $pipes, $cwd);

        if (! is_resource($process)) {
            throw new RuntimeException(sprintf('Unable to start parallel command: %s', implode(' ', $command)));
        }

        fclose($pipes[0]);

        $handles[$index] = [
            'command' => $command,
            'process' => $process,
            'stdout_pipe' => $pipes[1],
            'stderr_pipe' => $pipes[2],
        ];
    }

    $results = [];

    foreach ($handles as $handle) {
        $stdout = stream_get_contents($handle['stdout_pipe']);
        $stderr = stream_get_contents($handle['stderr_pipe']);
        fclose($handle['stdout_pipe']);
        fclose($handle['stderr_pipe']);
        $status = proc_close($handle['process']);

        if (! is_string($stdout) || trim($stdout) === '') {
            throw new RuntimeException(sprintf(
                "Parallel command did not produce JSON output in %s: %s\n%s",
                $cwd,
                implode(' ', $handle['command']),
                is_string($stderr) ? $stderr : ''
            ));
        }

        $decoded = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new RuntimeException(sprintf('Parallel command did not return a JSON object: %s', implode(' ', $handle['command'])));
        }

        $results[] = [
            'exit_code' => $status,
            'payload' => $decoded,
        ];
    }

    return $results;
}
