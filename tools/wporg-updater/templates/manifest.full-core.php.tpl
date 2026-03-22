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
        // Add managed, local, and ignored dependencies here.
        // local entries are first-class and are the right way to keep project-owned code in the repo.
        // Example managed wordpress.org plugin:
        // [
        //     'name' => 'Contact Form 7',
        //     'slug' => 'contact-form-7',
        //     'kind' => 'plugin',
        //     'management' => 'managed',
        //     'source' => 'wordpress.org',
        //     'path' => '__PLUGINS_ROOT__/contact-form-7',
        //     'main_file' => 'wp-contact-form-7.php',
        //     'version' => '6.1.5',
        //     'checksum' => 'sha256:...',
        //     'archive_subdir' => '',
        //     'extra_labels' => ['plugin:contact-form-7'],
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
        // Example local theme:
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
    ],
];
