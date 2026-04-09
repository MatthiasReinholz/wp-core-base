<?php

declare(strict_types=1);

use WpOrgPluginUpdater\Config;
use WpOrgPluginUpdater\DependencyScanner;
use WpOrgPluginUpdater\LabelHelper;
use WpOrgPluginUpdater\RuntimeInspector;
use WpOrgPluginUpdater\RuntimeStager;

/**
 * @param callable(bool,string):void $assert
 * @param array<string, mixed> $runtimeDefaults
 * @return array{config:Config, runtimeInspector:RuntimeInspector}
 */
function run_config_runtime_contract_tests(
    callable $assert,
    string $repoRoot,
    array $runtimeDefaults,
    string $longLabel,
    string $normalizedLongLabel
): array {
    $config = Config::load($repoRoot);
    $assert($config->profile === 'full-core', 'Expected repository manifest to load as full-core.');
    $assert($config->coreManaged(), 'Expected repository manifest to manage WordPress core.');
    $assert($config->manifestMode() === 'strict', 'Expected repository manifest to default to strict runtime ownership.');
    $assert($config->validationMode() === 'source-clean', 'Expected repository manifest to default to source-clean validation.');
    $assert(in_array('runtime-file', $config->stagedKinds(), true), 'Expected runtime-file to be stageable by default.');
    $assert(in_array('runtime-directory', $config->stagedKinds(), true), 'Expected runtime-directory to be stageable by default.');
    $assert(in_array('plugin', $config->managedKinds(), true), 'Expected plugins to remain managed by default.');
    $assert(count($config->managedDependencies()) === 4, 'Expected four managed baseline dependencies.');

    $longLabelManifestRoot = sys_get_temp_dir() . '/wporg-long-label-' . bin2hex(random_bytes(4));
    mkdir($longLabelManifestRoot . '/.wp-core-base', 0777, true);
    file_put_contents(
        $longLabelManifestRoot . '/.wp-core-base/manifest.php',
        "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export([
            'profile' => 'content-only',
            'paths' => [
                'content_root' => 'cms',
                'plugins_root' => 'cms/plugins',
                'themes_root' => 'cms/themes',
                'mu_plugins_root' => 'cms/mu-plugins',
            ],
            'core' => [
                'mode' => 'external',
                'enabled' => false,
            ],
            'runtime' => $runtimeDefaults,
            'github' => [
                'api_base' => 'https://api.github.com',
            ],
            'automation' => [
                'base_branch' => null,
                'dry_run' => false,
                'managed_kinds' => ['plugin', 'theme'],
            ],
            'dependencies' => [[
                'name' => 'Example Long Label Plugin',
                'slug' => 'example-long-label-plugin',
                'kind' => 'plugin',
                'management' => 'local',
                'source' => 'local',
                'path' => 'cms/plugins/example-long-label-plugin',
                'main_file' => 'example-long-label-plugin.php',
                'version' => null,
                'checksum' => null,
                'archive_subdir' => '',
                'extra_labels' => [$longLabel],
                'source_config' => [
                    'github_repository' => null,
                    'github_release_asset_pattern' => null,
                    'github_token_env' => null,
                ],
                'policy' => [
                    'class' => 'local-owned',
                    'allow_runtime_paths' => [],
                    'strip_paths' => [],
                    'strip_files' => [],
                ],
            ]],
        ], true) . ";\n"
    );
    $longLabelConfig = Config::load($longLabelManifestRoot);
    $normalizedManifestLabel = $longLabelConfig->dependencies()[0]['extra_labels'][0];
    $assert(strlen($normalizedManifestLabel) <= LabelHelper::MAX_LENGTH, 'Expected manifest extra_labels to be normalized on load.');
    $assert($normalizedManifestLabel === $normalizedLongLabel, 'Expected manifest label normalization to match the shared helper output.');

    $scanner = new DependencyScanner();
    $woocommerce = $config->dependencyByKey('plugin:wordpress.org:woocommerce');
    $woocommerceState = $scanner->inspect($repoRoot, $woocommerce);
    $assert($woocommerceState['version'] === $woocommerce['version'], 'Expected bundled WooCommerce version to match the manifest.');

    $runtimeInspector = new RuntimeInspector($config->runtime);
    $runtimeInspector->assertTreeIsClean($repoRoot . '/wp-content/plugins/woocommerce');
    $assert(
        $runtimeInspector->computeTreeChecksum($repoRoot . '/wp-content/plugins/woocommerce') === $woocommerce['checksum'],
        'Expected managed dependency checksum to match the sanitized tree.'
    );
    $checksumSymlinkRoot = sys_get_temp_dir() . '/wporg-checksum-symlink-' . bin2hex(random_bytes(4));
    mkdir($checksumSymlinkRoot, 0777, true);
    file_put_contents($checksumSymlinkRoot . '/real.php', "<?php\n");
    @symlink($checksumSymlinkRoot . '/real.php', $checksumSymlinkRoot . '/link.php');
    $checksumSymlinkRejected = false;

    try {
        $runtimeInspector->computeTreeChecksum($checksumSymlinkRoot);
    } catch (RuntimeException $exception) {
        $checksumSymlinkRejected = str_contains($exception->getMessage(), 'Symlink detected in checksum tree');
    }

    $runtimeInspector->clearPath($checksumSymlinkRoot);
    $assert($checksumSymlinkRejected, 'Expected checksum calculation to reject symlinked runtime trees directly.');

    $nestedSanitizeRoot = sys_get_temp_dir() . '/wporg-nested-sanitize-' . bin2hex(random_bytes(4));
    mkdir($nestedSanitizeRoot . '/packages/blueprint/src/docs', 0777, true);
    file_put_contents($nestedSanitizeRoot . '/packages/blueprint/src/docs/notes.md', "# Notes\n");
    file_put_contents($nestedSanitizeRoot . '/woocommerce.php', "<?php\n");
    $assert(
        $runtimeInspector->matchingStrippedEntries($nestedSanitizeRoot, ['**/docs']) === [
            'packages/blueprint/src/docs',
            'packages/blueprint/src/docs/notes.md',
        ],
        'Expected wildcard sanitize paths to match nested documentation directories.'
    );
    $runtimeInspector->stripPath($nestedSanitizeRoot, ['**/docs']);
    $assert(! is_dir($nestedSanitizeRoot . '/packages/blueprint/src/docs'), 'Expected wildcard sanitize paths to strip nested documentation directories.');
    $runtimeInspector->assertPathIsClean($nestedSanitizeRoot, [], [], ['**/docs']);
    $runtimeInspector->clearPath($nestedSanitizeRoot);

    $stageDir = '.wp-core-base/build/test-runtime';
    $stagedPaths = (new RuntimeStager($config, $runtimeInspector))->stage($stageDir);
    $assert(in_array('wp-content/plugins/woocommerce', $stagedPaths, true), 'Expected runtime staging to include managed plugin paths.');
    $runtimeInspector->clearDirectory($repoRoot . '/' . $stageDir);

    $legacyRoot = sys_get_temp_dir() . '/wporg-legacy-' . bin2hex(random_bytes(4));
    mkdir($legacyRoot . '/.github', 0777, true);
    file_put_contents($legacyRoot . '/.github/wporg-updates.php', "<?php\nreturn [];\n");
    $legacyFailed = false;

    try {
        Config::load($legacyRoot);
    } catch (RuntimeException $exception) {
        $legacyFailed = str_contains($exception->getMessage(), '.wp-core-base/manifest.php');
    }

    $assert($legacyFailed, 'Expected legacy config loading to fail with migration guidance.');

    return [
        'config' => $config,
        'runtimeInspector' => $runtimeInspector,
    ];
}
