<?php

declare(strict_types=1);

return array (
  'repository' => 'MatthiasReinholz/wp-core-base',
  'version' => '1.1.0',
  'release_channel' => 'stable',
  'distribution' => 
  array (
    'mode' => 'vendor-snapshot',
    'path' => '.',
    'asset_name' => 'wp-core-base-vendor-snapshot.zip',
  ),
  'baseline' => 
  array (
    'wordpress_core' => '6.9.4',
    'managed_components' => 
    array (
      0 => 
      array (
        'name' => 'WooCommerce',
        'version' => '10.6.1',
        'kind' => 'plugin',
      ),
      1 => 
      array (
        'name' => 'Jetpack',
        'version' => '15.6',
        'kind' => 'plugin',
      ),
      2 => 
      array (
        'name' => 'Contact Form 7',
        'version' => '6.1.5',
        'kind' => 'plugin',
      ),
      3 => 
      array (
        'name' => 'Redirection',
        'version' => '5.7.5',
        'kind' => 'plugin',
      ),
    ),
  ),
  'scaffold' => 
  array (
    'managed_files' => 
    array (
      '.github/workflows/wp-core-base-self-update.yml' => 'sha256:4c0ab44280dbc5034949c05e021edfb81b6d5968fc71ec1107ec09b0ca61917d',
      '.github/workflows/wporg-update-pr-blocker.yml' => 'sha256:fc94926c42731f7b8f24b5297f9b5804dd16931a028d0c51f9356f0ad38dd2e2',
      '.github/workflows/wporg-updates.yml' => 'sha256:6b584c770412b609c5a6f133f394b9130de5a3175fff163799bd8b880ee852c4',
      '.github/workflows/wporg-validate-runtime.yml' => 'sha256:7c24158389778abfce6f9f5a715cdab6646ea7e0d272c499e56985cbbbe930e1',
    ),
  ),
);
