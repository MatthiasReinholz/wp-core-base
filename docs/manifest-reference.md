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
- `manifest_mode`
- `staged_kinds`
- `validated_kinds`
- `forbidden_paths`
- `forbidden_files`
- `allow_runtime_paths`

These define the runtime hygiene contract for staging and validation.

`manifest_mode` may be:

- `strict`: undeclared runtime paths under the managed roots are validation errors and are not staged
- `relaxed`: undeclared clean runtime paths under the managed roots are reported and may be staged as a migration aid

## `github`

Keys:

- `api_base`

Use `getenv('GITHUB_API_URL') ?: 'https://api.github.com'` if you want GitHub Enterprise compatibility.

## `automation`

Keys:

- `base_branch`
- `dry_run`
- `managed_kinds`

`managed_kinds` limits what `sync` may update. A dependency must be both `management: managed` and listed in `automation.managed_kinds` before the updater will touch it.

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

- `kind` must be one of:
  - `plugin`
  - `theme`
  - `mu-plugin-package`
  - `mu-plugin-file`
  - `runtime-file`
- `management` must be `managed`, `local`, or `ignored`
- `source` must be `wordpress.org`, `github-release`, or `local`
- `managed` entries must define `version` and `checksum`
- `local` entries may define `version`, but do not need `checksum`
- `ignored` entries are excluded from runtime staging
- directory kinds require `main_file`
- file kinds may omit `main_file`; when omitted, the file at `path` is the runtime entry

## Kind-Level Controls

The manifest separates update control from staging and validation:

- `automation.managed_kinds` controls which managed kinds `sync` may overwrite
- `runtime.staged_kinds` controls which declared kinds `stage-runtime` will copy
- `runtime.validated_kinds` controls which declared kinds get runtime hygiene and checksum enforcement

This lets a downstream project stage `local` MU plugin files, for example, without allowing updater automation to manage them.

## Managed Versus Local

`local` is a first-class workflow, not a fallback.

Use `local` for project-owned runtime code such as:

- custom plugins
- custom themes
- MU plugin packages
- MU plugin files
- explicitly tracked runtime files

`sync` never mutates `local` entries.

## Private GitHub Dependencies

For a private GitHub release-backed dependency, use:

- `source: 'github-release'`
- `source_config.github_repository`
- `source_config.github_release_asset_pattern`
- `source_config.github_token_env`

The token value itself should stay in environment or repository secrets, not in the manifest.

## Runtime Ownership Modes

Use `strict` when you want every runtime path under the managed roots to be declared in the manifest.

Use `relaxed` when you are migrating an older repository and still need to surface undeclared runtime paths without blocking adoption immediately.

## Example

Use [examples/downstream-manifest.php](examples/downstream-manifest.php) as the starting point.
