<?php

declare(strict_types=1);

return array (
  'manifest_checksum' => 'sha256:dd70717b41571ee3c017027576a3566391ddd5d2433da92faa289a6b7c50db26',
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
