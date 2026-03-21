<?php

declare(strict_types=1);

return array (
  'profile' => 'full-core',
  'paths' => 
  array (
    'content_root' => 'wp-content',
    'plugins_root' => 'wp-content/plugins',
    'themes_root' => 'wp-content/themes',
    'mu_plugins_root' => 'wp-content/mu-plugins',
  ),
  'core' => 
  array (
    'mode' => 'managed',
    'enabled' => true,
  ),
  'runtime' => 
  array (
    'stage_dir' => '.wp-core-base/build/runtime',
    'forbidden_paths' => 
    array (
      0 => '.git',
      1 => '.github',
      2 => '.gitlab',
      3 => '.circleci',
      4 => '.wordpress-org',
      5 => 'node_modules',
      6 => 'docs',
      7 => 'doc',
      8 => 'tests',
      9 => 'test',
      10 => '__tests__',
      11 => 'examples',
      12 => 'example',
      13 => 'demo',
      14 => 'screenshots',
    ),
    'forbidden_files' => 
    array (
      0 => 'README*',
      1 => 'CHANGELOG*',
      2 => '.gitignore',
      3 => '.gitattributes',
      4 => 'phpunit.xml*',
      5 => 'composer.json',
      6 => 'composer.lock',
      7 => 'package.json',
      8 => 'package-lock.json',
      9 => 'pnpm-lock.yaml',
      10 => 'yarn.lock',
    ),
    'allow_runtime_paths' => 
    array (
    ),
  ),
  'github' => 
  array (
    'api_base' => 'https://api.github.com',
  ),
  'automation' => 
  array (
    'base_branch' => NULL,
    'dry_run' => false,
  ),
  'dependencies' => 
  array (
    0 => 
    array (
      'name' => 'Akismet Anti-spam: Spam Protection',
      'slug' => 'akismet',
      'kind' => 'plugin',
      'management' => 'local',
      'source' => 'local',
      'path' => 'wp-content/plugins/akismet',
      'main_file' => 'akismet.php',
      'version' => '5.6',
      'checksum' => NULL,
      'archive_subdir' => '',
      'extra_labels' => 
      array (
        0 => 'plugin:akismet',
      ),
      'source_config' => 
      array (
        'github_repository' => NULL,
        'github_release_asset_pattern' => NULL,
        'github_token_env' => NULL,
      ),
      'policy' => 
      array (
        'class' => 'local-owned',
        'allow_runtime_paths' => 
        array (
        ),
      ),
    ),
    1 => 
    array (
      'name' => 'WooCommerce',
      'slug' => 'woocommerce',
      'kind' => 'plugin',
      'management' => 'managed',
      'source' => 'wordpress.org',
      'path' => 'wp-content/plugins/woocommerce',
      'main_file' => 'woocommerce.php',
      'version' => '10.6.1',
      'checksum' => 'sha256:def6c41bee9bd9ef329992e0bd2b6e0f9a85f4769fe7c593bb6637c671ab96bb',
      'archive_subdir' => '',
      'extra_labels' => 
      array (
        0 => 'plugin:woocommerce',
      ),
      'source_config' => 
      array (
        'github_repository' => NULL,
        'github_release_asset_pattern' => NULL,
        'github_token_env' => NULL,
      ),
      'policy' => 
      array (
        'class' => 'managed-upstream',
        'allow_runtime_paths' => 
        array (
        ),
      ),
    ),
    2 => 
    array (
      'name' => 'Jetpack',
      'slug' => 'jetpack',
      'kind' => 'plugin',
      'management' => 'managed',
      'source' => 'wordpress.org',
      'path' => 'wp-content/plugins/jetpack',
      'main_file' => 'jetpack.php',
      'version' => '15.6',
      'checksum' => 'sha256:0d196c5cb7f6118eb233659ad14434a0c190e8cabdaa11664afcfb272a7a25fb',
      'archive_subdir' => '',
      'extra_labels' => 
      array (
        0 => 'plugin:jetpack',
      ),
      'source_config' => 
      array (
        'github_repository' => NULL,
        'github_release_asset_pattern' => NULL,
        'github_token_env' => NULL,
      ),
      'policy' => 
      array (
        'class' => 'managed-upstream',
        'allow_runtime_paths' => 
        array (
        ),
      ),
    ),
    3 => 
    array (
      'name' => 'Contact Form 7',
      'slug' => 'contact-form-7',
      'kind' => 'plugin',
      'management' => 'managed',
      'source' => 'wordpress.org',
      'path' => 'wp-content/plugins/contact-form-7',
      'main_file' => 'wp-contact-form-7.php',
      'version' => '6.1.5',
      'checksum' => 'sha256:924dfe631a37fa89cd371d0dfa0db8c2441ddcbd3eabe806342a990bb6c2b39e',
      'archive_subdir' => '',
      'extra_labels' => 
      array (
        0 => 'plugin:contact-form-7',
      ),
      'source_config' => 
      array (
        'github_repository' => NULL,
        'github_release_asset_pattern' => NULL,
        'github_token_env' => NULL,
      ),
      'policy' => 
      array (
        'class' => 'managed-upstream',
        'allow_runtime_paths' => 
        array (
        ),
      ),
    ),
    4 => 
    array (
      'name' => 'Redirection',
      'slug' => 'redirection',
      'kind' => 'plugin',
      'management' => 'managed',
      'source' => 'wordpress.org',
      'path' => 'wp-content/plugins/redirection',
      'main_file' => 'redirection.php',
      'version' => '5.7.5',
      'checksum' => 'sha256:738a6a141a4d75009a8005c19b4accabbb4a11d96e9910beeb43e925953e1928',
      'archive_subdir' => '',
      'extra_labels' => 
      array (
        0 => 'plugin:redirection',
      ),
      'source_config' => 
      array (
        'github_repository' => NULL,
        'github_release_asset_pattern' => NULL,
        'github_token_env' => NULL,
      ),
      'policy' => 
      array (
        'class' => 'managed-upstream',
        'allow_runtime_paths' => 
        array (
        ),
      ),
    ),
    5 => 
    array (
      'name' => 'Twenty Twenty-Three',
      'slug' => 'twentytwentythree',
      'kind' => 'theme',
      'management' => 'local',
      'source' => 'local',
      'path' => 'wp-content/themes/twentytwentythree',
      'main_file' => 'style.css',
      'version' => '1.6',
      'checksum' => NULL,
      'archive_subdir' => '',
      'extra_labels' => 
      array (
        0 => 'theme:twentytwentythree',
      ),
      'source_config' => 
      array (
        'github_repository' => NULL,
        'github_release_asset_pattern' => NULL,
        'github_token_env' => NULL,
      ),
      'policy' => 
      array (
        'class' => 'local-owned',
        'allow_runtime_paths' => 
        array (
        ),
      ),
    ),
    6 => 
    array (
      'name' => 'Twenty Twenty-Four',
      'slug' => 'twentytwentyfour',
      'kind' => 'theme',
      'management' => 'local',
      'source' => 'local',
      'path' => 'wp-content/themes/twentytwentyfour',
      'main_file' => 'style.css',
      'version' => '1.4',
      'checksum' => NULL,
      'archive_subdir' => '',
      'extra_labels' => 
      array (
        0 => 'theme:twentytwentyfour',
      ),
      'source_config' => 
      array (
        'github_repository' => NULL,
        'github_release_asset_pattern' => NULL,
        'github_token_env' => NULL,
      ),
      'policy' => 
      array (
        'class' => 'local-owned',
        'allow_runtime_paths' => 
        array (
        ),
      ),
    ),
    7 => 
    array (
      'name' => 'Twenty Twenty-Five',
      'slug' => 'twentytwentyfive',
      'kind' => 'theme',
      'management' => 'local',
      'source' => 'local',
      'path' => 'wp-content/themes/twentytwentyfive',
      'main_file' => 'style.css',
      'version' => '1.4',
      'checksum' => NULL,
      'archive_subdir' => '',
      'extra_labels' => 
      array (
        0 => 'theme:twentytwentyfive',
      ),
      'source_config' => 
      array (
        'github_repository' => NULL,
        'github_release_asset_pattern' => NULL,
        'github_token_env' => NULL,
      ),
      'policy' => 
      array (
        'class' => 'local-owned',
        'allow_runtime_paths' => 
        array (
        ),
      ),
    ),
  ),
);
