<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/support/WordpressSmokeRunner.php';

// Executed in a subprocess because WordPress owns process-wide globals/constants.
$root = getenv('WP_CORE_BASE_SMOKE_RUNTIME');
$runId = getenv('WP_CORE_BASE_SMOKE_RUN_ID');
$phase = $argv[1] ?? '';
if (! is_string($root) || ! is_file($root . '/wp-config.php')
    || ! is_string($runId) || preg_match('/^[a-f0-9]{12}$/D', $runId) !== 1
    || ! in_array($phase, ['install', 'bootstrap', 'cleanup'], true)) {
    throw new RuntimeException('Invalid disposable WordPress fixture configuration.');
}
if ($phase === 'install') {
    define('WP_INSTALLING', true);
}
if ($phase === 'cleanup') {
    // Cleanup must work even when a plugin fatals during WordPress bootstrap.
    $database = getenv('WP_CORE_BASE_SMOKE_DB_NAME');
    $host = (string) getenv('WP_CORE_BASE_SMOKE_DB_HOST');
    if ($database !== 'wp_core_base_smoke' || preg_match('/^(127\.0\.0\.1|localhost)(?::([0-9]+))?$/D', $host, $parts) !== 1) {
        throw new RuntimeException('Refusing cleanup outside the dedicated local smoke database.');
    }
    $connection = new mysqli($parts[1], (string) getenv('WP_CORE_BASE_SMOKE_DB_USER'), (string) getenv('WP_CORE_BASE_SMOKE_DB_PASSWORD'), $database, isset($parts[2]) ? (int) $parts[2] : 3306);
    $tables = $connection->query('SHOW TABLES');
    if (! $tables instanceof mysqli_result) {
        throw new RuntimeException('Unable to enumerate disposable database tables.');
    }
    while ($row = $tables->fetch_row()) {
        $table = $row[0];
        if (str_starts_with($table, 'smoke_' . $runId . '_') && preg_match('/^[a-zA-Z0-9_]+$/D', $table) === 1) {
            if (! $connection->query('DROP TABLE `' . $table . '`')) {
                throw new RuntimeException('Unable to remove disposable table.');
            }
        }
    }
    $connection->close();
    WordpressSmokeRunner::complete('cleanup');
    exit(0);
}
$_SERVER['HTTP_HOST'] = 'wp-core-base.invalid';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
require $root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

// Disable all outbound HTTP, including loopback/vendor activation calls.
add_filter('pre_http_request', static fn () => new WP_Error('smoke_offline', 'Network disabled in runtime smoke.'), PHP_INT_MIN);
$plugins = json_decode((string) getenv('WP_CORE_BASE_SMOKE_PLUGINS'), true, 512, JSON_THROW_ON_ERROR);
if ($phase === 'install') {
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $result = wp_install('wp-core-base smoke', 'smoke-admin', 'smoke@example.invalid', false, '', bin2hex(random_bytes(24)));
    if (is_wp_error($result)) {
        throw new RuntimeException($result->get_error_message());
    }
    foreach ($plugins as $plugin) {
        $error = activate_plugin($plugin, '', false, false);
        if (is_wp_error($error)) {
            throw new RuntimeException('Activation failed for ' . $plugin . ': ' . $error->get_error_message());
        }
    }
    fwrite(STDOUT, "Installed disposable WordPress and activated bundled plugins.\n");
} elseif ($phase === 'bootstrap') {
    if (! is_blog_installed()) {
        throw new RuntimeException('WordPress did not persist its installation.');
    }
    $active = get_option('active_plugins', []);
    foreach ($plugins as $plugin) {
        if (! in_array($plugin, $active, true)) {
            throw new RuntimeException('Plugin did not remain active: ' . $plugin);
        }
    }
    $muPlugins = wp_get_mu_plugins();
    if (! in_array(WPMU_PLUGIN_DIR . '/wp-core-base-admin-governance.php', $muPlugins, true)) {
        throw new RuntimeException('Staged admin governance MU plugin did not load.');
    }
    $response = rest_do_request(new WP_REST_Request('GET', '/wp/v2/types'));
    if ($response->get_status() !== 200 || ! isset($response->get_data()['post'])) {
        throw new RuntimeException('WordPress REST application request failed.');
    }
    fwrite(STDOUT, "Bootstrapped active plugins, governance MU plugin and WordPress REST API.\n");
}
WordpressSmokeRunner::complete($phase);
