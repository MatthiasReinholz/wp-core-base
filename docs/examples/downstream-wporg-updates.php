<?php

declare(strict_types=1);

// Copy this file into the downstream repository as .github/wporg-updates.php
// and then adjust the managed plugins for that project.
// GITHUB_API_URL is used automatically when the workflow runs on GitHub Enterprise.

return [
    'base_branch' => null,
    'support_max_pages' => 30,
    'github_api_base' => getenv('GITHUB_API_URL') ?: 'https://api.github.com',
    'dry_run' => (bool) getenv('WPORG_UPDATE_DRY_RUN'),
    'core' => [
        'enabled' => true,
    ],
    'plugins' => [
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
    ],
];
