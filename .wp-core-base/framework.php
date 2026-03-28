<?php

declare(strict_types=1);

return array (
  'repository' => 'MatthiasReinholz/wp-core-base',
  'version' => '1.3.2',
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
      '.github/workflows/wporg-update-pr-blocker.yml' => 'sha256:1b0fc1a0e4ded7a05d4eba125dc5c2a872d5d7b504f626632770796efdaee6cc',
      '.github/workflows/wporg-updates-reconcile.yml' => 'sha256:1f4a3e0a61c117b86160f327593ea92bab269478967d2670faef1100b837535b',
      '.github/workflows/wporg-updates.yml' => 'sha256:c8e4762557dce205198151e81b788edc039f1604461890ed4b60d7995a35e49e',
      '.github/workflows/wporg-validate-runtime.yml' => 'sha256:84e04c185c2ec0d6a3dbe19e932bc454333e6aca0070f9697a72a64e22dee675',
      'wp-content/mu-plugins/wp-core-base-admin-governance.php' => 'sha256:c78013d7f970c203241e8dd4140e1c767281612e6d194907086490c5b5e8ffdf',
    ),
  ),
);
