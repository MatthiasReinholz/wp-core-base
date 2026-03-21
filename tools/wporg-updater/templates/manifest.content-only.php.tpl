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
    ],
    'dependencies' => [
        // content-only repos should declare every runtime package explicitly.
        // Example local-owned MU plugin package:
        // [
        //     'name' => 'Project Bootstrap',
        //     'slug' => 'project-bootstrap',
        //     'kind' => 'mu-plugin-package',
        //     'management' => 'local',
        //     'source' => 'local',
        //     'path' => '__MU_PLUGINS_ROOT__/project-bootstrap',
        //     'main_file' => 'loader.php',
        //     'version' => '1.0.0',
        //     'checksum' => null,
        //     'archive_subdir' => '',
        //     'extra_labels' => ['mu-plugin:project-bootstrap'],
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
