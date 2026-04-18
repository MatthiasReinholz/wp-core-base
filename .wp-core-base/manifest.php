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
    'manifest_mode' => 'strict',
    'validation_mode' => 'source-clean',
    'ownership_roots' => 
    array (
      0 => 'wp-content/plugins',
      1 => 'wp-content/themes',
      2 => 'wp-content/mu-plugins',
    ),
    'staged_kinds' => 
    array (
      0 => 'plugin',
      1 => 'theme',
      2 => 'mu-plugin-package',
      3 => 'mu-plugin-file',
      4 => 'runtime-file',
      5 => 'runtime-directory',
    ),
    'validated_kinds' => 
    array (
      0 => 'plugin',
      1 => 'theme',
      2 => 'mu-plugin-package',
      3 => 'mu-plugin-file',
      4 => 'runtime-file',
      5 => 'runtime-directory',
    ),
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
      0 => 'wp-content/plugins/hello.php',
      1 => 'wp-content/plugins/index.php',
      2 => 'wp-content/themes/index.php',
    ),
    'strip_paths' => 
    array (
    ),
    'strip_files' => 
    array (
    ),
    'managed_sanitize_paths' => 
    array (
      0 => 'wp-content/plugins/.github',
      1 => 'wp-content/plugins/.gitlab',
      2 => 'wp-content/plugins/.circleci',
      3 => 'wp-content/plugins/.wordpress-org',
      4 => 'wp-content/plugins/node_modules',
      5 => 'wp-content/plugins/docs',
      6 => 'wp-content/plugins/doc',
      7 => 'wp-content/plugins/tests',
      8 => 'wp-content/plugins/test',
      9 => 'wp-content/plugins/__tests__',
      10 => 'wp-content/plugins/examples',
      11 => 'wp-content/plugins/example',
      12 => 'wp-content/plugins/demo',
      13 => 'wp-content/plugins/screenshots',
      14 => 'wp-content/themes/.github',
      15 => 'wp-content/themes/.gitlab',
      16 => 'wp-content/themes/.circleci',
      17 => 'wp-content/themes/.wordpress-org',
      18 => 'wp-content/themes/node_modules',
      19 => 'wp-content/themes/docs',
      20 => 'wp-content/themes/doc',
      21 => 'wp-content/themes/tests',
      22 => 'wp-content/themes/test',
      23 => 'wp-content/themes/__tests__',
      24 => 'wp-content/themes/examples',
      25 => 'wp-content/themes/example',
      26 => 'wp-content/themes/demo',
      27 => 'wp-content/themes/screenshots',
      28 => 'wp-content/mu-plugins/.github',
      29 => 'wp-content/mu-plugins/.gitlab',
      30 => 'wp-content/mu-plugins/.circleci',
      31 => 'wp-content/mu-plugins/.wordpress-org',
      32 => 'wp-content/mu-plugins/node_modules',
      33 => 'wp-content/mu-plugins/docs',
      34 => 'wp-content/mu-plugins/doc',
      35 => 'wp-content/mu-plugins/tests',
      36 => 'wp-content/mu-plugins/test',
      37 => 'wp-content/mu-plugins/__tests__',
      38 => 'wp-content/mu-plugins/examples',
      39 => 'wp-content/mu-plugins/example',
      40 => 'wp-content/mu-plugins/demo',
      41 => 'wp-content/mu-plugins/screenshots',
    ),
    'managed_sanitize_files' => 
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
  ),
  'github' => 
  array (
    'api_base' => 'https://api.github.com',
  ),
  'automation' => 
  array (
    'base_branch' => NULL,
    'dry_run' => false,
    'managed_kinds' => 
    array (
      0 => 'plugin',
      1 => 'theme',
      2 => 'mu-plugin-package',
    ),
  ),
  'security' => 
  array (
    'managed_release_min_age_hours' => 0,
    'github_release_verification' => 'checksum-sidecar-optional',
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
        'min_release_age_hours' => NULL,
        'verification_mode' => 'inherit',
        'checksum_asset_pattern' => NULL,
        'credential_key' => NULL,
        'provider' => NULL,
        'provider_product_id' => NULL,
      ),
      'policy' => 
      array (
        'class' => 'local-owned',
        'allow_runtime_paths' => 
        array (
        ),
        'strip_paths' => 
        array (
        ),
        'strip_files' => 
        array (
        ),
        'sanitize_paths' => 
        array (
        ),
        'sanitize_files' => 
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
      'version' => '10.7.0',
      'checksum' => 'sha256:66c69876d11457d5fe926f9f26292fceabc79f511372a3ebd3c8bcdb822904b5',
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
        'min_release_age_hours' => NULL,
        'verification_mode' => 'inherit',
        'checksum_asset_pattern' => NULL,
        'credential_key' => NULL,
        'provider' => NULL,
        'provider_product_id' => NULL,
      ),
      'policy' => 
      array (
        'class' => 'managed-upstream',
        'allow_runtime_paths' => 
        array (
        ),
        'strip_paths' => 
        array (
        ),
        'strip_files' => 
        array (
        ),
        'sanitize_paths' => 
        array (
        ),
        'sanitize_files' => 
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
      'version' => '15.7.1',
      'checksum' => 'sha256:3cd1b4c1bb83fd70f9713e0ce68cca2c1cff4d05e3db3dce89dbca65b3f469c1',
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
        'min_release_age_hours' => NULL,
        'verification_mode' => 'inherit',
        'checksum_asset_pattern' => NULL,
        'credential_key' => NULL,
        'provider' => NULL,
        'provider_product_id' => NULL,
      ),
      'policy' => 
      array (
        'class' => 'managed-upstream',
        'allow_runtime_paths' => 
        array (
        ),
        'strip_paths' => 
        array (
        ),
        'strip_files' => 
        array (
        ),
        'sanitize_paths' => 
        array (
        ),
        'sanitize_files' => 
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
        'min_release_age_hours' => NULL,
        'verification_mode' => 'inherit',
        'checksum_asset_pattern' => NULL,
        'credential_key' => NULL,
        'provider' => NULL,
        'provider_product_id' => NULL,
      ),
      'policy' => 
      array (
        'class' => 'managed-upstream',
        'allow_runtime_paths' => 
        array (
        ),
        'strip_paths' => 
        array (
        ),
        'strip_files' => 
        array (
        ),
        'sanitize_paths' => 
        array (
        ),
        'sanitize_files' => 
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
        'min_release_age_hours' => NULL,
        'verification_mode' => 'inherit',
        'checksum_asset_pattern' => NULL,
        'credential_key' => NULL,
        'provider' => NULL,
        'provider_product_id' => NULL,
      ),
      'policy' => 
      array (
        'class' => 'managed-upstream',
        'allow_runtime_paths' => 
        array (
        ),
        'strip_paths' => 
        array (
        ),
        'strip_files' => 
        array (
        ),
        'sanitize_paths' => 
        array (
        ),
        'sanitize_files' => 
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
        'min_release_age_hours' => NULL,
        'verification_mode' => 'inherit',
        'checksum_asset_pattern' => NULL,
        'credential_key' => NULL,
        'provider' => NULL,
        'provider_product_id' => NULL,
      ),
      'policy' => 
      array (
        'class' => 'local-owned',
        'allow_runtime_paths' => 
        array (
        ),
        'strip_paths' => 
        array (
        ),
        'strip_files' => 
        array (
        ),
        'sanitize_paths' => 
        array (
        ),
        'sanitize_files' => 
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
        'min_release_age_hours' => NULL,
        'verification_mode' => 'inherit',
        'checksum_asset_pattern' => NULL,
        'credential_key' => NULL,
        'provider' => NULL,
        'provider_product_id' => NULL,
      ),
      'policy' => 
      array (
        'class' => 'local-owned',
        'allow_runtime_paths' => 
        array (
        ),
        'strip_paths' => 
        array (
        ),
        'strip_files' => 
        array (
        ),
        'sanitize_paths' => 
        array (
        ),
        'sanitize_files' => 
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
        'min_release_age_hours' => NULL,
        'verification_mode' => 'inherit',
        'checksum_asset_pattern' => NULL,
        'credential_key' => NULL,
        'provider' => NULL,
        'provider_product_id' => NULL,
      ),
      'policy' => 
      array (
        'class' => 'local-owned',
        'allow_runtime_paths' => 
        array (
        ),
        'strip_paths' => 
        array (
        ),
        'strip_files' => 
        array (
        ),
        'sanitize_paths' => 
        array (
        ),
        'sanitize_files' => 
        array (
        ),
      ),
    ),
  ),
);
