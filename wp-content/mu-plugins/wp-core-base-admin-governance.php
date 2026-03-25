<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class WpCoreBaseAdminGovernance
{
    /** @var array<string, mixed>|null */
    private static ?array $data = null;

    public static function boot(): void
    {
        add_filter('site_transient_update_plugins', [self::class, 'filterPluginUpdates']);
        add_filter('plugin_action_links', [self::class, 'filterPluginActionLinks'], 20, 4);
        add_filter('plugin_row_meta', [self::class, 'filterPluginRowMeta'], 20, 2);
        add_filter('plugin_auto_update_setting_html', [self::class, 'filterAutoUpdateHtml'], 20, 2);
    }

    /**
     * @param mixed $transient
     * @return mixed
     */
    public static function filterPluginUpdates($transient)
    {
        if (! is_object($transient) || ! isset($transient->response) || ! is_array($transient->response)) {
            return $transient;
        }

        foreach (self::workflowManagedPlugins() as $basename => $entry) {
            unset($transient->response[$basename]);
            unset($transient->no_update[$basename]);
        }

        return $transient;
    }

    /**
     * @param array<int|string, string> $actions
     * @return array<int|string, string>
     */
    public static function filterPluginActionLinks(array $actions, string $pluginFile): array
    {
        $entry = self::pluginEntry($pluginFile);

        if (! is_array($entry)) {
            return $actions;
        }

        foreach ($actions as $key => $action) {
            if (! is_string($action)) {
                continue;
            }

            if (! empty($entry['workflow_managed']) && (str_contains($action, 'upgrade-plugin') || str_contains($action, 'update-now'))) {
                unset($actions[$key]);
            }
        }

        return $actions;
    }

    /**
     * @param array<int|string, string> $meta
     * @return array<int|string, string>
     */
    public static function filterPluginRowMeta(array $meta, string $pluginFile): array
    {
        $entry = self::pluginEntry($pluginFile);

        if (! is_array($entry)) {
            return $meta;
        }

        $label = isset($entry['label']) && is_string($entry['label']) ? $entry['label'] : 'Managed by wp-core-base';
        $source = isset($entry['source']) && is_string($entry['source']) ? $entry['source'] : 'unknown';
        $meta[] = sprintf(
            '<span style="color:#50575e;font-weight:600;">%s</span> <span style="color:#646970;">(%s)</span>',
            esc_html($label),
            esc_html($source)
        );

        return $meta;
    }

    public static function filterAutoUpdateHtml(string $html, string $pluginFile): string
    {
        $entry = self::pluginEntry($pluginFile);

        if (! is_array($entry) || empty($entry['workflow_managed'])) {
            return $html;
        }

        return '<span style="color:#646970;">Managed by wp-core-base workflows</span>';
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function workflowManagedPlugins(): array
    {
        $workflowManaged = [];

        foreach (self::plugins() as $basename => $entry) {
            if (! empty($entry['workflow_managed'])) {
                $workflowManaged[$basename] = $entry;
            }
        }

        return $workflowManaged;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function plugins(): array
    {
        $data = self::data();
        $plugins = $data['plugins'] ?? [];

        return is_array($plugins) ? $plugins : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function pluginEntry(string $pluginFile): ?array
    {
        $entry = self::plugins()[$pluginFile] ?? null;

        return is_array($entry) ? $entry : null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function data(): array
    {
        if (is_array(self::$data)) {
            return self::$data;
        }

        $path = __DIR__ . '/wp-core-base-admin-governance.data.php';

        if (! is_file($path)) {
            self::$data = [];
            return self::$data;
        }

        $data = require $path;
        self::$data = is_array($data) ? $data : [];

        return self::$data;
    }
}

WpCoreBaseAdminGovernance::boot();
