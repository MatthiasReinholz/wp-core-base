# Manifest Reference

This document is for downstream users configuring `.wp-core-base/manifest.php`.

Framework release pinning lives separately in `.wp-core-base/framework.php`. The manifest is only for runtime ownership, update scope, and staging policy.

If you need plain-language definitions before the schema details, read [concepts.md](concepts.md).
If you want the routine add/remove workflow, read [managing-dependencies.md](managing-dependencies.md).

The manifest remains the source of truth, but common entry creation does not need to be hand-written. Prefer the CLI for normal tasks such as adding a plugin, MU plugin file, or runtime directory.

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
- `validation_mode`
- `ownership_roots`
- `staged_kinds`
- `validated_kinds`
- `forbidden_paths`
- `forbidden_files`
- `allow_runtime_paths`
- `strip_paths`
- `strip_files`
- `managed_sanitize_paths`
- `managed_sanitize_files`

These define the runtime hygiene contract for staging and validation.

`manifest_mode` may be:

- `strict`: undeclared runtime paths under the managed roots are validation errors and are not staged
- `relaxed`: undeclared clean runtime paths under the managed roots are reported and may be staged as a migration aid

`validation_mode` may be:

- `source-clean`: source paths must already be runtime-clean
- `staged-clean`: local source paths may contain strip-on-stage files, but staged output must be clean

`managed_sanitize_paths` and `managed_sanitize_files` define which non-runtime files the framework may remove from managed dependencies during `sync` before validation, replacement, and checksum calculation.

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
        'credential_key' => null,
        'provider' => null,
        'provider_product_id' => null,
    ],
    'policy' => [
        'class' => 'managed-upstream',
        'allow_runtime_paths' => [],
        'sanitize_paths' => [],
        'sanitize_files' => [],
    ],
]
```

Routine authoring commands:

```bash
vendor/wp-core-base/bin/wp-core-base add-dependency --repo-root=. --source=local --kind=plugin --path=cms/plugins/project-plugin
vendor/wp-core-base/bin/wp-core-base remove-dependency --repo-root=. --slug=project-plugin --kind=plugin
vendor/wp-core-base/bin/wp-core-base list-dependencies --repo-root=.
```

## Rules

- `kind` must be one of:
  - `plugin`
  - `theme`
  - `mu-plugin-package`
  - `mu-plugin-file`
  - `runtime-file`
  - `runtime-directory`
- `management` must be `managed`, `local`, or `ignored`
- `source` must be `wordpress.org`, `github-release`, `premium`, or `local`
- `managed` entries must define `version` and `checksum`
- `local` entries may define `version`, but do not need `checksum`
- `ignored` entries are excluded from runtime staging
- directory kinds require `main_file`
- file kinds may omit `main_file`; when omitted, the file at `path` is the runtime entry
- `runtime-directory` entries may be `local` or `ignored`, but are not updater-managed today
- `extra_labels` are normalized to GitHub's 50-character label limit; overlong labels are shortened deterministically with a hash suffix so workflow runs do not fail on long slugs

## Kind-Level Controls

The manifest separates update control from staging and validation:

- `automation.managed_kinds` controls which managed kinds `sync` may overwrite
- `runtime.staged_kinds` controls which declared kinds `stage-runtime` will copy
- `runtime.validated_kinds` controls which declared kinds get runtime hygiene and checksum enforcement

This lets a downstream project stage `local` MU plugin files, for example, without allowing updater automation to manage them.

## Ownership Roots

`runtime.ownership_roots` controls where undeclared runtime-path detection runs.

Defaults:

- `plugins_root`
- `themes_root`
- `mu_plugins_root`

You can add extra content roots such as:

- `cms/languages`
- `cms/shared-assets`

Under custom ownership roots, undeclared directories are inferred as `runtime-directory` and undeclared files are inferred as `runtime-file`.

## Strip-On-Stage Rules

Use `runtime.strip_paths` and `runtime.strip_files` for global strip-on-stage rules.

Use `dependencies[].policy.strip_paths` and `dependencies[].policy.strip_files` for local dependency-specific strip rules.

Strip-on-stage is supported for `local` entries. Use it when local source trees are intentionally richer than the final runtime payload.

## Managed Sanitation

Use `runtime.managed_sanitize_paths` and `runtime.managed_sanitize_files` for global managed-dependency sanitation rules.

Use `dependencies[].policy.sanitize_paths` and `dependencies[].policy.sanitize_files` for managed dependency-specific sanitation rules.

Managed sanitation applies during `sync` before the dependency is validated, copied into the repo, and checksummed. The manifest checksum for a managed dependency is therefore the checksum of the sanitized runtime tree, not the raw upstream archive.

Ideal packaging is still preferred: managed artifacts should already be runtime-ready. Sanitation exists to normalize common WordPress ecosystem extras such as `README*`, build metadata, or test directories when they appear in otherwise valid release archives.

## Managed Versus Local

`local` is a first-class workflow, not a fallback.

Use `local` for project-owned runtime code such as:

- custom plugins
- custom themes
- MU plugin packages
- MU plugin files
- explicitly tracked runtime files
- runtime directories such as `cms/languages`

`sync` never mutates `local` entries.

## Private GitHub Dependencies

For a private GitHub release-backed dependency, use:

- `source: 'github-release'`
- `source_config.github_repository`
- `source_config.github_release_asset_pattern`
- `source_config.github_token_env`

The token value itself should stay in environment or repository secrets, not in the manifest.

## Premium Managed Dependencies

Premium managed plugin sources use one fixed env-var or GitHub secret contract:

- `WP_CORE_BASE_PREMIUM_CREDENTIALS_JSON`

Supported premium source modes:

- `premium` with `source_config.provider: your-provider`

Use `source_config.credential_key` only when the credential lookup key should differ from the dependency `component_key`.

Use `source_config.provider_product_id` only when your custom provider adapter needs a stable product identifier.

Premium providers are registered separately in `.wp-core-base/premium-providers.php`.

The manifest never stores:

- premium license keys
- site-linked API tokens
- signed download URLs

Those values live only in `WP_CORE_BASE_PREMIUM_CREDENTIALS_JSON`.

## Runtime Ownership Modes

Use `strict` when you want every runtime path under the managed roots to be declared in the manifest.

Use `relaxed` when you are migrating an older repository and still need to surface undeclared runtime paths without blocking adoption immediately.

## Helper Commands

Useful CLI helpers:

- `suggest-manifest`
- `format-manifest`

## Example

Use [examples/downstream-manifest.php](examples/downstream-manifest.php) as the starting point.
