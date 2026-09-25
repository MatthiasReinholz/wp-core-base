<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/tools/wporg-updater/src/Autoload.php';
require dirname(__DIR__, 2) . '/tools/wporg-updater/tests/support/WordpressSmokeRunner.php';

use WpOrgPluginUpdater\Config;
use WpOrgPluginUpdater\MutationLock;
use WpOrgPluginUpdater\OutputRedactor;
use WpOrgPluginUpdater\RuntimeInspector;
use WpOrgPluginUpdater\RuntimeStager;

// This test deliberately requires a dedicated, local, disposable database.
// It never reads a site's wp-config.php or inherits production DB credentials.
$host = getenv('WP_CORE_BASE_SMOKE_DB_HOST');
$database = getenv('WP_CORE_BASE_SMOKE_DB_NAME');
if (! is_string($host) || preg_match('/^(127\.0\.0\.1|localhost)(:[0-9]+)?$/D', $host) !== 1
    || $database !== 'wp_core_base_smoke') {
    throw new RuntimeException('Set WP_CORE_BASE_SMOKE_DB_HOST to a local disposable database and WP_CORE_BASE_SMOKE_DB_NAME=wp_core_base_smoke.');
}
foreach (['USER', 'PASSWORD'] as $field) {
    if (! is_string(getenv('WP_CORE_BASE_SMOKE_DB_' . $field))) {
        throw new RuntimeException('Missing smoke database credential: ' . $field);
    }
}

$repoRoot = dirname(__DIR__, 2);
$lease = (new MutationLock())->acquire($repoRoot, 'wordpress-smoke');
$config = Config::load($repoRoot);
$arguments = array_slice(array_values(array_map('strval', $GLOBALS['argv'] ?? [])), 1);
if (count($arguments) > 1 || (isset($arguments[0]) && ! in_array($arguments[0], ['--profile=full-core', '--profile=content-only'], true))) {
    throw new RuntimeException('Smoke profile must be --profile=full-core or --profile=content-only.');
}
$profile = isset($arguments[0]) ? substr($arguments[0], strlen('--profile=')) : 'full-core';
if ($profile === 'content-only') {
    $manifest = $config->toArray();
    $manifest['profile'] = 'content-only';
    $manifest['core'] = ['mode' => 'external', 'enabled' => false];
    $config = Config::fromArray($repoRoot, $manifest);
}
$inspector = new RuntimeInspector($config->runtime);
$output = '.wp-core-base/build/wordpress-smoke-' . bin2hex(random_bytes(6));
$runtimeRoot = $config->stageDir($output);
$plugins = [];
foreach ($config->dependencies() as $dependency) {
    if ($dependency['kind'] === 'plugin' && $dependency['management'] !== 'ignored') {
        $plugins[] = basename((string) $dependency['path']) . '/' . $dependency['main_file'];
    }
}

$environment = null;
$operationFailure = null;
$fixture = $repoRoot . '/tools/wporg-updater/tests/fixtures/wordpress/runtime-smoke.php';
try {
    (new RuntimeStager($config, $inspector))->stage($output);
    if ($profile === 'content-only') {
        if (file_exists($runtimeRoot . '/wp-load.php') || file_exists($runtimeRoot . '/wp-includes')) {
            throw new RuntimeException('Content-only staging unexpectedly included core.');
        }
        // Simulate the host/image supplying the separately verified core layer.
        foreach (scandir($repoRoot) ?: [] as $entry) {
            if (in_array($entry, ['wp-admin', 'wp-includes', 'index.php', 'xmlrpc.php'], true)
                || ($entry !== 'wp-config.php' && fnmatch('wp-*.php', $entry))) {
                $inspector->copyPath($repoRoot . '/' . $entry, $runtimeRoot . '/' . $entry);
            }
        }
    }
    $configuration = <<<'PHP'
<?php
define('DB_NAME', getenv('WP_CORE_BASE_SMOKE_DB_NAME'));
define('DB_USER', getenv('WP_CORE_BASE_SMOKE_DB_USER'));
define('DB_PASSWORD', getenv('WP_CORE_BASE_SMOKE_DB_PASSWORD'));
define('DB_HOST', getenv('WP_CORE_BASE_SMOKE_DB_HOST'));
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');
define('WP_HOME', 'http://wp-core-base.invalid');
define('WP_SITEURL', 'http://wp-core-base.invalid');
define('WP_ENVIRONMENT_TYPE', 'local');
define('DISABLE_WP_CRON', true);
define('AUTOMATIC_UPDATER_DISABLED', true);
define('WP_HTTP_BLOCK_EXTERNAL', true);
define('WP_DEBUG', true);
define('WP_DEBUG_DISPLAY', false);
define('WP_DEBUG_LOG', __DIR__ . '/smoke-debug.log');
$table_prefix = 'smoke_' . getenv('WP_CORE_BASE_SMOKE_RUN_ID') . '_';
if (! defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
require_once ABSPATH . 'wp-settings.php';
PHP;
    if (file_put_contents($runtimeRoot . '/wp-config.php', $configuration) === false) {
        throw new RuntimeException('Unable to create disposable WordPress configuration.');
    }
    $environment = getenv();
    $environment['WP_CORE_BASE_SMOKE_RUN_ID'] = bin2hex(random_bytes(6));
    $environment['WP_CORE_BASE_SMOKE_PLUGINS'] = json_encode($plugins, JSON_THROW_ON_ERROR);
    $environment['WP_CORE_BASE_SMOKE_RUNTIME'] = $runtimeRoot;
    foreach (['install', 'bootstrap'] as $phase) {
        WordpressSmokeRunner::run($fixture, $phase, $runtimeRoot, $environment);
    }
    $log = is_file($runtimeRoot . '/smoke-debug.log') ? file_get_contents($runtimeRoot . '/smoke-debug.log') : '';
    if (is_string($log) && preg_match('/PHP (Fatal error|Parse error|Recoverable fatal error)/', $log) === 1) {
        throw new RuntimeException('WordPress runtime smoke recorded a fatal error.');
    }
} catch (Throwable $failure) {
    $operationFailure = $failure;
    if (is_file($runtimeRoot . '/smoke-debug.log')) {
        $log = file_get_contents($runtimeRoot . '/smoke-debug.log');
        if (is_string($log)) {
            fwrite(STDERR, OutputRedactor::redact(substr($log, -16000)) . "\n");
        }
    }
    throw $failure;
} finally {
    try {
        if ($environment !== null) {
            WordpressSmokeRunner::run($fixture, 'cleanup', $runtimeRoot, $environment);
        }
    } catch (Throwable $cleanupFailure) {
        if ($operationFailure === null) {
            throw $cleanupFailure;
        }
        fwrite(STDERR, '[warn] Disposable database cleanup also failed: ' . OutputRedactor::redact($cleanupFailure->getMessage()) . "\n");
    } finally {
        $inspector->clearPath($runtimeRoot);
    }
}
fwrite(STDOUT, sprintf("WordPress %s staged-runtime smoke passed on PHP %s (%d plugins).\n", $profile, PHP_VERSION, count($plugins)));
