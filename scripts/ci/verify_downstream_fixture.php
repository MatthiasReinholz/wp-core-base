<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/tools/wporg-updater/src/Autoload.php';

$frameworkRoot = dirname(__DIR__, 2);
$profile = 'content-only';

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--profile=')) {
        $profile = substr($argument, strlen('--profile='));
    }
}

if (! in_array($profile, ['full-core', 'content-only'], true)) {
    throw new RuntimeException(sprintf('Unsupported downstream fixture profile: %s', $profile));
}

$contentRoot = $profile === 'full-core' ? 'wp-content' : 'cms';
$fixtureRoot = sys_get_temp_dir() . '/wporg-downstream-fixture-' . $profile . '-' . bin2hex(random_bytes(4));
$repoRoot = $fixtureRoot . '/repo';
$frameworkVendorRoot = $repoRoot . '/vendor/wp-core-base';

mkdir($repoRoot, 0777, true);

try {
    runCommand($repoRoot, ['git', 'init', '-q']);
    runCommand($frameworkRoot, [
        'php',
        'tools/wporg-updater/bin/wporg-updater.php',
        'scaffold-downstream',
        '--repo-root=' . $repoRoot,
        '--tool-path=vendor/wp-core-base',
        '--profile=' . $profile,
        '--content-root=' . $contentRoot,
        '--force',
    ]);

    (new \WpOrgPluginUpdater\FrameworkReleaseArtifactBuilder($frameworkRoot))->copySnapshotTo($frameworkVendorRoot);
    seedFixtureRepository($repoRoot, $profile, $contentRoot);

    $pluginPath = $contentRoot . '/plugins/example-plugin';
    runCommand($repoRoot, [
        'php',
        'vendor/wp-core-base/tools/wporg-updater/bin/wporg-updater.php',
        'add-dependency',
        '--repo-root=.',
        '--source=local',
        '--kind=plugin',
        '--path=' . $pluginPath,
    ]);
    runCommand($repoRoot, [
        'php',
        'vendor/wp-core-base/tools/wporg-updater/bin/wporg-updater.php',
        'refresh-admin-governance',
        '--repo-root=.',
    ]);
    runCommand($repoRoot, [
        'php',
        'vendor/wp-core-base/tools/wporg-updater/bin/wporg-updater.php',
        'doctor',
        '--repo-root=.',
    ]);

    $stageOutput = '.wp-core-base/build/runtime-' . $profile;
    runCommand($repoRoot, [
        'php',
        'vendor/wp-core-base/tools/wporg-updater/bin/wporg-updater.php',
        'stage-runtime',
        '--repo-root=.',
        '--output=' . $stageOutput,
    ]);

    assertFixtureOutput($repoRoot . '/' . $stageOutput, $profile, $contentRoot);
    fwrite(STDOUT, sprintf("Downstream fixture verified for %s\n", $profile));
} finally {
    clearPath($fixtureRoot);
}

function seedFixtureRepository(string $repoRoot, string $profile, string $contentRoot): void
{
    $pluginRoot = $repoRoot . '/' . $contentRoot . '/plugins/example-plugin';
    $muPluginRoot = $repoRoot . '/' . $contentRoot . '/mu-plugins';

    if (! is_dir($pluginRoot) && ! mkdir($pluginRoot, 0777, true) && ! is_dir($pluginRoot)) {
        throw new RuntimeException(sprintf('Unable to create plugin fixture root: %s', $pluginRoot));
    }

    if (! is_dir($muPluginRoot) && ! mkdir($muPluginRoot, 0777, true) && ! is_dir($muPluginRoot)) {
        throw new RuntimeException(sprintf('Unable to create MU plugin fixture root: %s', $muPluginRoot));
    }

    file_put_contents(
        $pluginRoot . '/example-plugin.php',
        "<?php\n/*\nPlugin Name: Example Plugin\nVersion: 1.0.0\n*/\n"
    );

    if ($profile !== 'full-core') {
        return;
    }

    $wpAdmin = $repoRoot . '/wp-admin';
    $wpIncludes = $repoRoot . '/wp-includes';

    if (! is_dir($wpAdmin) && ! mkdir($wpAdmin, 0777, true) && ! is_dir($wpAdmin)) {
        throw new RuntimeException(sprintf('Unable to create wp-admin fixture: %s', $wpAdmin));
    }

    if (! is_dir($wpIncludes) && ! mkdir($wpIncludes, 0777, true) && ! is_dir($wpIncludes)) {
        throw new RuntimeException(sprintf('Unable to create wp-includes fixture: %s', $wpIncludes));
    }

    file_put_contents($repoRoot . '/index.php', "<?php\nrequire __DIR__ . '/wp-blog-header.php';\n");
    file_put_contents($repoRoot . '/wp-blog-header.php', "<?php\n");
    file_put_contents($repoRoot . '/wp-load.php', "<?php\n");
    file_put_contents($wpAdmin . '/index.php', "<?php\n");
    file_put_contents($wpIncludes . '/version.php', "<?php\n\$wp_version = '6.9.4';\n");
}

function assertFixtureOutput(string $stageRoot, string $profile, string $contentRoot): void
{
    $pluginMain = $stageRoot . '/' . $contentRoot . '/plugins/example-plugin/example-plugin.php';
    $governanceLoader = $stageRoot . '/' . $contentRoot . '/mu-plugins/wp-core-base-admin-governance.php';
    $governanceData = $stageRoot . '/' . $contentRoot . '/mu-plugins/wp-core-base-admin-governance.data.php';

    if (! is_file($pluginMain)) {
        throw new RuntimeException(sprintf('Staged downstream fixture is missing plugin main file: %s', $pluginMain));
    }

    if (! is_file($governanceLoader) || ! is_file($governanceData)) {
        throw new RuntimeException('Staged downstream fixture is missing admin governance runtime files.');
    }

    if ($profile === 'full-core') {
        if (! is_file($stageRoot . '/wp-includes/version.php') || ! is_file($stageRoot . '/index.php')) {
            throw new RuntimeException('full-core fixture did not stage WordPress core files.');
        }

        return;
    }

    if (file_exists($stageRoot . '/wp-includes/version.php') || file_exists($stageRoot . '/wp-admin')) {
        throw new RuntimeException('content-only fixture unexpectedly staged WordPress core files.');
    }
}

/**
 * @param list<string> $command
 */
function runCommand(string $cwd, array $command): void
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

    if ($status !== 0) {
        throw new RuntimeException(sprintf(
            "Command failed in %s: %s\n%s%s",
            $cwd,
            implode(' ', $command),
            is_string($stdout) ? $stdout : '',
            is_string($stderr) ? $stderr : ''
        ));
    }
}

function clearPath(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }

    if (! is_dir($path)) {
        return;
    }

    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        clearPath($path . '/' . $entry);
    }

    @rmdir($path);
}
