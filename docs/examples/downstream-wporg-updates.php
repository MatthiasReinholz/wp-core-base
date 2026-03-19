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
        [
            'slug' => 'contact-form-7',
            'path' => 'wp-content/plugins/contact-form-7',
            'main_file' => 'wp-contact-form-7.php',
            'enabled' => true,
            'extra_labels' => ['plugin:contact-form-7'],
        ],
        [
            'slug' => 'redirection',
            'path' => 'wp-content/plugins/redirection',
            'main_file' => 'redirection.php',
            'enabled' => true,
            'extra_labels' => ['plugin:redirection'],
        ],
        // Public GitHub release-backed plugin example:
        // [
        //     'source' => 'github',
        //     'slug' => 'example-plugin',
        //     'path' => 'wp-content/plugins/example-plugin',
        //     'main_file' => 'example-plugin.php',
        //     'enabled' => true,
        //     'github_repository' => 'owner/repo',
        //     'github_release_asset_pattern' => '*.zip',
        //     'github_archive_subdir' => '',
        //     'extra_labels' => ['plugin:example-plugin'],
        // ],
    ],
];
