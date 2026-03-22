<?php

declare(strict_types=1);

return [
    'profile' => '__PROFILE__',
    'paths' => [
        'content_root' => '__CONTENT_ROOT__',
        'plugins_root' => '__PLUGINS_ROOT__',
        'themes_root' => '__THEMES_ROOT__',
        'mu_plugins_root' => '__MU_PLUGINS_ROOT__',
    ],
    'core' => [
        'mode' => '__CORE_MODE__',
        'enabled' => __CORE_ENABLED__,
    ],
    'runtime' => [
        'stage_dir' => '.wp-core-base/build/runtime',
        'manifest_mode' => 'strict',
        'staged_kinds' => ['plugin', 'theme', 'mu-plugin-package', 'mu-plugin-file', 'runtime-file'],
        'validated_kinds' => ['plugin', 'theme', 'mu-plugin-package', 'mu-plugin-file', 'runtime-file'],
        'forbidden_paths' => [
            '.git',
            '.github',
            '.gitlab',
            '.circleci',
            '.wordpress-org',
            'node_modules',
            'docs',
            'doc',
            'tests',
            'test',
            '__tests__',
            'examples',
            'example',
            'demo',
            'screenshots',
        ],
        'forbidden_files' => [
            'README*',
            'CHANGELOG*',
            '.gitignore',
            '.gitattributes',
            'phpunit.xml*',
            'composer.json',
            'composer.lock',
            'package.json',
            'package-lock.json',
            'pnpm-lock.yaml',
            'yarn.lock',
        ],
        'allow_runtime_paths' => [],
    ],
    'github' => [
        'api_base' => getenv('GITHUB_API_URL') ?: 'https://api.github.com',
    ],
    'automation' => [
        'base_branch' => null,
        'dry_run' => false,
        'managed_kinds' => ['plugin', 'theme', 'mu-plugin-package'],
    ],
    'dependencies' => [
        // content-only repos should declare every managed dependency explicitly.
        // local entries are first-class and are the normal way to keep project-owned code in the repo.
        // Example managed wordpress.org plugin:
        // [
        //     'name' => 'WooCommerce',
        //     'slug' => 'woocommerce',
        //     'kind' => 'plugin',
        //     'management' => 'managed',
        //     'source' => 'wordpress.org',
        //     'path' => '__PLUGINS_ROOT__/woocommerce',
        //     'main_file' => 'woocommerce.php',
        //     'version' => '10.6.1',
        //     'checksum' => 'sha256:...',
        //     'archive_subdir' => '',
        //     'extra_labels' => ['plugin:woocommerce'],
        //     'source_config' => [
        //         'github_repository' => null,
        //         'github_release_asset_pattern' => null,
        //         'github_token_env' => null,
        //     ],
        //     'policy' => [
        //         'class' => 'managed-upstream',
        //         'allow_runtime_paths' => [],
        //     ],
        // ],
        // Example managed GitHub Release plugin:
        // [
        //     'name' => 'Example Private Plugin',
        //     'slug' => 'example-private-plugin',
        //     'kind' => 'plugin',
        //     'management' => 'managed',
        //     'source' => 'github-release',
        //     'path' => '__PLUGINS_ROOT__/example-private-plugin',
        //     'main_file' => 'example-private-plugin.php',
        //     'version' => '1.2.3',
        //     'checksum' => 'sha256:...',
        //     'archive_subdir' => '',
        //     'extra_labels' => ['plugin:example-private-plugin'],
        //     'source_config' => [
        //         'github_repository' => 'owner/private-plugin',
        //         'github_release_asset_pattern' => '*.zip',
        //         'github_token_env' => 'PRIVATE_PLUGIN_GITHUB_TOKEN',
        //     ],
        //     'policy' => [
        //         'class' => 'managed-private',
        //         'allow_runtime_paths' => [],
        //     ],
        // ],
        // Example local project-owned theme:
        // [
        //     'name' => 'Project Theme',
        //     'slug' => 'project-theme',
        //     'kind' => 'theme',
        //     'management' => 'local',
        //     'source' => 'local',
        //     'path' => '__THEMES_ROOT__/project-theme',
        //     'main_file' => 'style.css',
        //     'version' => '1.0.0',
        //     'checksum' => null,
        //     'archive_subdir' => '',
        //     'extra_labels' => ['theme:project-theme'],
        //     'source_config' => [
        //         'github_repository' => null,
        //         'github_release_asset_pattern' => null,
        //         'github_token_env' => null,
        //     ],
        //     'policy' => [
        //         'class' => 'local-owned',
        //         'allow_runtime_paths' => [],
        //     ],
        // ],
        // Example local single-file MU plugin:
        // [
        //     'name' => 'Project Bootstrap',
        //     'slug' => 'project-bootstrap-loader',
        //     'kind' => 'mu-plugin-file',
        //     'management' => 'local',
        //     'source' => 'local',
        //     'path' => '__MU_PLUGINS_ROOT__/project-bootstrap-loader.php',
        //     'version' => '1.0.0',
        //     'checksum' => null,
        //     'archive_subdir' => '',
        //     'extra_labels' => ['mu-plugin:project-bootstrap-loader'],
        //     'source_config' => [
        //         'github_repository' => null,
        //         'github_release_asset_pattern' => null,
        //         'github_token_env' => null,
        //     ],
        //     'policy' => [
        //         'class' => 'local-owned',
        //         'allow_runtime_paths' => [],
        //     ],
        // ],
    ],
];
