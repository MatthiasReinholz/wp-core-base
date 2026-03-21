# Manifest Reference

This document is for downstream users configuring `.wp-core-base/manifest.php`.

## Top-Level Keys

The manifest returns a PHP array with these sections:

- `profile`
- `paths`
- `core`
- `runtime`
- `github`
- `automation`
- `dependencies`

## `profile`

Allowed values:

- `full-core`
- `content-only`

## `paths`

Required keys:

- `content_root`
- `plugins_root`
- `themes_root`
- `mu_plugins_root`

Defaults for `full-core`:

- `wp-content`
- `wp-content/plugins`
- `wp-content/themes`
- `wp-content/mu-plugins`

Defaults for `content-only`:

- `cms`
- `cms/plugins`
- `cms/themes`
- `cms/mu-plugins`

## `core`

Keys:

- `mode`: `managed` or `external`
- `enabled`: `true` or `false`

Use `managed` only when the repo actually contains WordPress core.

## `runtime`

Keys:

- `stage_dir`
- `forbidden_paths`
- `forbidden_files`
- `allow_runtime_paths`

These define the runtime hygiene contract for staging and validation.

## `github`

Keys:

- `api_base`

Use `getenv('GITHUB_API_URL') ?: 'https://api.github.com'` if you want GitHub Enterprise compatibility.

## `automation`

Keys:

- `base_branch`
- `dry_run`

## Dependency Entry Shape

Each dependency entry supports:

```php
[
    'name' => 'Contact Form 7',
    'slug' => 'contact-form-7',
    'kind' => 'plugin',
    'management' => 'managed',
    'source' => 'wordpress.org',
    'path' => 'cms/plugins/contact-form-7',
    'main_file' => 'wp-contact-form-7.php',
    'version' => '6.1.5',
    'checksum' => 'sha256:...',
    'archive_subdir' => '',
    'extra_labels' => ['plugin:contact-form-7'],
    'source_config' => [
        'github_repository' => null,
        'github_release_asset_pattern' => null,
        'github_token_env' => null,
    ],
    'policy' => [
        'class' => 'managed-upstream',
        'allow_runtime_paths' => [],
    ],
]
```

## Rules

- `kind` must be `plugin`, `theme`, or `mu-plugin-package`
- `management` must be `managed`, `local`, or `ignored`
- `source` must be `wordpress.org`, `github-release`, or `local`
- `managed` entries must define `version` and `checksum`
- `local` entries may define `version`, but do not need `checksum`
- `ignored` entries are excluded from runtime staging

## Private GitHub Dependencies

For a private GitHub release-backed dependency, use:

- `source: 'github-release'`
- `source_config.github_repository`
- `source_config.github_release_asset_pattern`
- `source_config.github_token_env`

The token value itself should stay in environment or repository secrets, not in the manifest.

## Example

Use [examples/downstream-manifest.php](examples/downstream-manifest.php) as the starting point.
