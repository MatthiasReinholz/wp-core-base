<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/tools/wporg-updater/src/Autoload.php';
require dirname(__DIR__, 2) . '/tools/wporg-updater/tests/support/WordpressSmokeRunner.php';

use WpOrgPluginUpdater\Config;
use WpOrgPluginUpdater\CoreScanner;
use WpOrgPluginUpdater\MutationLock;
use WpOrgPluginUpdater\OutputRedactor;
use WpOrgPluginUpdater\RuntimeInspector;
use WpOrgPluginUpdater\RuntimeStager;

// The previous released runtime is the input, not a freshly installed candidate.
const UPGRADE_BASELINE_REVISION = 'a8f6404fa46befd6d2a8ecd9a3aca940ea05a45c';
$options = ['profile' => 'full-core', 'order-storage' => 'hpos'];
$seen = [];
foreach (array_slice(array_values(array_map('strval', $GLOBALS['argv'] ?? [])), 1) as $argument) {
    if (preg_match('/^--(profile|order-storage)=(.+)$/D', $argument, $match) !== 1
        || isset($seen[$match[1]])
        || ! in_array($match[2], $match[1] === 'profile' ? ['full-core', 'content-only'] : ['hpos', 'posts'], true)) {
        throw new RuntimeException('Use --profile=full-core|content-only and --order-storage=hpos|posts.');
    }
    $options[$match[1]] = $match[2];
    $seen[$match[1]] = true;
}
$host = getenv('WP_CORE_BASE_SMOKE_DB_HOST');
if (! is_string($host) || preg_match('/^(127\.0\.0\.1|localhost)(?::([0-9]+))?$/D', $host, $hostParts) !== 1
    || getenv('WP_CORE_BASE_SMOKE_DB_NAME') !== 'wp_core_base_smoke') {
    throw new RuntimeException('Upgrade verification requires the dedicated local wp_core_base_smoke database.');
}
foreach (['USER', 'PASSWORD'] as $field) {
    if (! is_string(getenv('WP_CORE_BASE_SMOKE_DB_' . $field))) {
        throw new RuntimeException('Missing smoke database credential: ' . $field);
    }
}
$repoRoot = dirname(__DIR__, 2);
$lease = (new MutationLock())->acquire($repoRoot, 'wordpress-upgrade');
$config = Config::load($repoRoot);
if ($options['profile'] === 'content-only') {
    $manifest = $config->toArray();
    $manifest['profile'] = 'content-only';
    $manifest['core'] = ['mode' => 'external', 'enabled' => false];
    $config = Config::fromArray($repoRoot, $manifest);
}
$inspector = new RuntimeInspector($config->runtime);
$runId = bin2hex(random_bytes(6));
$output = '.wp-core-base/build/wordpress-upgrade-' . $runId;
$candidate = $config->stageDir($output);
$baseline = $candidate . '-baseline';
$archive = $candidate . '-baseline.tar';
$fixture = $repoRoot . '/tools/wporg-updater/tests/fixtures/wordpress/runtime-upgrade.php';
$smokeFixture = $repoRoot . '/tools/wporg-updater/tests/fixtures/wordpress/runtime-smoke.php';
$environment = getenv();
$environment['WP_CORE_BASE_SMOKE_RUN_ID'] = $runId;
$environment['WP_CORE_BASE_UPGRADE_STORAGE'] = $options['order-storage'];
$environment['WP_CORE_BASE_UPGRADE_CORE_VERSION'] = (new CoreScanner())->inspect($repoRoot)['version'];
$expectedVersions = [];
$plugins = [];
foreach ($config->dependencies() as $dependency) {
    if ($dependency['kind'] === 'plugin' && $dependency['management'] !== 'ignored') {
        $plugins[] = basename((string) $dependency['path']) . '/' . $dependency['main_file'];
        $expectedVersions[basename((string) $dependency['path']) . '/' . $dependency['main_file']] = $dependency['version'];
    }
}
$environment['WP_CORE_BASE_SMOKE_PLUGINS'] = json_encode($plugins, JSON_THROW_ON_ERROR);
$environment['WP_CORE_BASE_UPGRADE_PLUGIN_VERSIONS'] = json_encode($expectedVersions, JSON_THROW_ON_ERROR);
$connection = null;
$snapshot = [];
$failure = null;
try {
    (new RuntimeStager($config, $inspector))->stage($output);
    if ($options['profile'] === 'content-only') {
        if (file_exists($candidate . '/wp-load.php') || file_exists($candidate . '/wp-includes')) {
            throw new RuntimeException('Content-only staging unexpectedly included WordPress core.');
        }
        foreach (scandir($repoRoot) ?: [] as $entry) {
            if (in_array($entry, ['wp-admin', 'wp-includes', 'index.php', 'xmlrpc.php'], true)
                || ($entry !== 'wp-config.php' && fnmatch('wp-*.php', $entry))) {
                $inspector->copyPath($repoRoot . '/' . $entry, $candidate . '/' . $entry);
            }
        }
    }
    if (! mkdir($baseline, 0700, true)) {
        throw new RuntimeException('Unable to allocate previous-release runtime.');
    }
    // Only trusted tracked runtime files are extracted; no downstream config or uploads.
    upgradeCommand(['git', 'archive', '--format=tar', '--output=' . $archive, UPGRADE_BASELINE_REVISION,
        '--', 'wp-admin', 'wp-includes', 'wp-content/plugins', 'wp-content/mu-plugins', 'wp-content/themes', 'index.php', 'xmlrpc.php', ':(glob)wp-*.php'], $repoRoot);
    upgradeCommand(['tar', '-xf', $archive, '-C', $baseline], $repoRoot);
    unlink($archive);
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
    $offline = <<<'PHP'
<?php
add_filter('pre_http_request', static fn () => new WP_Error('smoke_offline', 'No external traffic in upgrade verification.'), PHP_INT_MIN);
add_filter('pre_wp_mail', static fn () => true, PHP_INT_MIN);
add_filter('action_scheduler_allow_async_request_runner', '__return_false');
PHP;
    foreach ([$baseline, $candidate] as $root) {
        if (file_put_contents($root . '/wp-config.php', $configuration) === false
            || file_put_contents($root . '/wp-content/mu-plugins/000-upgrade-offline.php', $offline) === false) {
            throw new RuntimeException('Unable to configure isolated upgrade runtime.');
        }
    }
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $connection = new mysqli($hostParts[1], (string) getenv('WP_CORE_BASE_SMOKE_DB_USER'), (string) getenv('WP_CORE_BASE_SMOKE_DB_PASSWORD'), 'wp_core_base_smoke', isset($hostParts[2]) ? (int) $hostParts[2] : 3306);
    $environment['WP_CORE_BASE_SMOKE_RUNTIME'] = $baseline;
    WordpressSmokeRunner::run($smokeFixture, 'install', $baseline, $environment);
    foreach (['configure', 'seed', 'verify-baseline'] as $phase) {
        WordpressSmokeRunner::run($fixture, $phase, $baseline, $environment);
    }
    upgradeSnapshot($connection, $runId, false, $snapshot);
    $environment['WP_CORE_BASE_SMOKE_RUNTIME'] = $candidate;
    foreach (['migrate', 'verify-upgraded', 'verify-checkout'] as $phase) {
        WordpressSmokeRunner::run($fixture, $phase, $candidate, $environment);
    }
    upgradeSnapshot($connection, $runId, true, $snapshot);
    $environment['WP_CORE_BASE_SMOKE_RUNTIME'] = $baseline;
    WordpressSmokeRunner::run($fixture, 'verify-rollback', $baseline, $environment);
    foreach ([$baseline, $candidate] as $root) {
        $log = is_file($root . '/smoke-debug.log') ? file_get_contents($root . '/smoke-debug.log') : '';
        if (! is_string($log)) {
            throw new RuntimeException('Unable to read WordPress upgrade diagnostic log.');
        }
        WordpressSmokeRunner::assertHealthyLog($log);
        if (preg_match_all('/PHP (Warning|Notice|Deprecated):/', $log, $diagnostics) > 0) {
            fwrite(STDOUT, sprintf("Nonfatal upstream diagnostics (%s): %s\n", $root === $baseline ? 'baseline' : 'candidate', json_encode(array_count_values($diagnostics[1]), JSON_THROW_ON_ERROR)));
            preg_match_all('/^.*?PHP (?:Warning|Notice|Deprecated):\s*(.+)$/m', $log, $messages);
            foreach (array_slice(array_unique($messages[1]), 0, 3) as $message) {
                fwrite(STDOUT, '  ' . OutputRedactor::redact(substr($message, 0, 2000)) . "\n");
            }
        }
    }
} catch (Throwable $caught) {
    $failure = $caught;
    foreach ([$baseline, $candidate] as $root) {
        if (is_file($root . '/smoke-debug.log')) {
            fwrite(STDERR, OutputRedactor::redact(substr((string) file_get_contents($root . '/smoke-debug.log'), -16000)) . "\n");
        }
    }
    throw $caught;
} finally {
    try {
        if (is_file($baseline . '/wp-config.php')) {
            $environment['WP_CORE_BASE_SMOKE_RUNTIME'] = $baseline;
            WordpressSmokeRunner::run($smokeFixture, 'cleanup', $baseline, $environment);
        }
    } catch (Throwable $cleanupFailure) {
        if ($failure === null) {
            throw $cleanupFailure;
        }
        fwrite(STDERR, 'Upgrade cleanup also failed: ' . OutputRedactor::redact($cleanupFailure->getMessage()) . "\n");
    } finally {
        if ($connection instanceof mysqli) {
            $connection->close();
        }
        $inspector->clearPath($baseline);
        $inspector->clearPath($candidate);
        if (is_file($archive)) {
            unlink($archive);
        }
    }
}
fwrite(STDOUT, sprintf("WordPress upgrade and restored-baseline rollback passed (%s, %s, PHP %s, baseline %s).\n", $options['profile'], $options['order-storage'], PHP_VERSION, UPGRADE_BASELINE_REVISION));

/** @param list<string> $command */
function upgradeCommand(array $command, string $directory): void
{
    $process = proc_open($command, [], $pipes, $directory);
    if (! is_resource($process) || proc_close($process) !== 0) {
        throw new RuntimeException('Unable to prepare pinned baseline runtime; fetch ' . UPGRADE_BASELINE_REVISION . ' before running the upgrade fixture.');
    }
}

/**
 * Copy or restore only this run's disposable database objects, never a site's database.
 *
 * @param array<string,string> $snapshot Maps bounded backup aliases to original names.
 */
function upgradeSnapshot(mysqli $connection, string $runId, bool $restore, array &$snapshot): void
{
    $livePrefix = 'smoke_' . $runId . '_';
    $backupPrefix = $livePrefix . 'b_';
    $tables = $connection->query('SHOW TABLES');
    if (! $tables instanceof mysqli_result) {
        throw new RuntimeException('Unable to enumerate upgrade database objects.');
    }
    $live = [];
    $backup = [];
    while ($row = $tables->fetch_row()) {
        $table = (string) $row[0];
        if (preg_match('/^[a-zA-Z0-9_]+$/D', $table) !== 1) {
            continue;
        }
        if (str_starts_with($table, $backupPrefix)) {
            $backup[] = $table;
        } elseif (str_starts_with($table, $livePrefix)) {
            $live[] = $table;
        }
    }
    if ($live === [] || ($restore && ($backup === [] || $snapshot === []))) {
        throw new RuntimeException('Refusing empty database snapshot or restore.');
    }
    if ($restore) {
        $expected = array_keys($snapshot);
        sort($backup);
        sort($expected);
        if ($backup !== $expected) {
            throw new RuntimeException('Disposable database snapshot inventory changed before restore.');
        }
        foreach ($live as $table) {
            $connection->query('DROP TABLE `' . $table . '`');
        }
        foreach ($snapshot as $table => $target) {
            $connection->query('RENAME TABLE `' . $table . '` TO `' . $target . '`');
        }
    } else {
        if ($backup !== [] || $snapshot !== []) {
            throw new RuntimeException('Refusing to replace an existing disposable database snapshot.');
        }
        foreach ($live as $index => $table) {
            // Original plugin table names may already use all 64 allowed characters.
            $target = $backupPrefix . $index;
            $connection->query('CREATE TABLE `' . $target . '` LIKE `' . $table . '`');
            $connection->query('INSERT INTO `' . $target . '` SELECT * FROM `' . $table . '`');
            $snapshot[$target] = $table;
        }
    }
    fwrite(STDOUT, sprintf("%s %d isolated database tables.\n", $restore ? 'Restored' : 'Snapshotted', $restore ? count($backup) : count($live)));
}
