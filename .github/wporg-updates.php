<?php

declare(strict_types=1);

return [
    'base_branch' => null,
    'support_max_pages' => 30,
    'github_api_base' => 'https://api.github.com',
    'dry_run' => (bool) getenv('WPORG_UPDATE_DRY_RUN'),
    'core' => [
        'enabled' => true,
    ],
    'plugins' => [
        [
            'slug' => 'akismet',
            'path' => 'wp-content/plugins/akismet',
            'main_file' => 'akismet.php',
            'enabled' => false,
            'extra_labels' => ['plugin:akismet'],
        ],
        [
            'slug' => 'woocommerce',
            'path' => 'wp-content/plugins/woocommerce',
            'main_file' => 'woocommerce.php',
            'enabled' => true,
            'support_max_pages' => 60,
            'extra_labels' => ['plugin:woocommerce'],
        ],
        [
            'slug' => 'jetpack',
            'path' => 'wp-content/plugins/jetpack',
            'main_file' => 'jetpack.php',
            'enabled' => true,
            'support_max_pages' => 60,
            'extra_labels' => ['plugin:jetpack'],
        ],
        // Copy this block for each managed wordpress.org plugin.
        // [
        //     'slug' => 'example-plugin',
        //     'path' => 'wp-content/plugins/example-plugin',
        //     'main_file' => 'example-plugin.php',
        //     'enabled' => true,
        //     'support_max_pages' => 30,
        //     'extra_labels' => ['plugin:example-plugin'],
        // ],
    ],
];
