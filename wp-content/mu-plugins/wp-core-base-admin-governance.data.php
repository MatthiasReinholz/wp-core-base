<?php

declare(strict_types=1);

return array (
  'manifest_checksum' => 'sha256:957272addfccf2556492b14ed4f3c7b1df0685f3e07ae31a83c1608f0e48d028',
  'plugins' =>
  array (
    'akismet/akismet.php' =>
    array (
      'component_key' => 'plugin:local:akismet',
      'management' => 'local',
      'source' => 'local',
      'workflow_managed' => false,
      'label' => 'Local code managed in-repo',
    ),
    'contact-form-7/wp-contact-form-7.php' =>
    array (
      'component_key' => 'plugin:wordpress.org:contact-form-7',
      'management' => 'managed',
      'source' => 'wordpress.org',
      'workflow_managed' => true,
      'label' => 'Managed by wp-core-base workflows',
    ),
    'jetpack/jetpack.php' =>
    array (
      'component_key' => 'plugin:wordpress.org:jetpack',
      'management' => 'managed',
      'source' => 'wordpress.org',
      'workflow_managed' => true,
      'label' => 'Managed by wp-core-base workflows',
    ),
    'redirection/redirection.php' =>
    array (
      'component_key' => 'plugin:wordpress.org:redirection',
      'management' => 'managed',
      'source' => 'wordpress.org',
      'workflow_managed' => true,
      'label' => 'Managed by wp-core-base workflows',
    ),
    'woocommerce/woocommerce.php' =>
    array (
      'component_key' => 'plugin:wordpress.org:woocommerce',
      'management' => 'managed',
      'source' => 'wordpress.org',
      'workflow_managed' => true,
      'label' => 'Managed by wp-core-base workflows',
    ),
  ),
  'mu_plugins' =>
  array (
  ),
);
