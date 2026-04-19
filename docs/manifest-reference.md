# Manifest Reference

This document is for downstream users configuring `.wp-core-base/manifest.php`.

Framework release pinning lives separately in `.wp-core-base/framework.php`. The manifest is only for runtime ownership, update scope, and staging policy.

If you need plain-language definitions before the schema details, read [concepts.md](concepts.md).
If you want the routine add/remove workflow, read [managing-dependencies.md](managing-dependencies.md).

The manifest remains the source of truth, but common entry creation does not need to be hand-written. Prefer the CLI for normal tasks such as adding a plugin, MU plugin file, or runtime directory.

## Mental Model

Treat these as separate decisions:

- `automation.provider`: where update PR or MR automation runs
- dependency `source`: where a managed dependency release archive comes from
- `.wp-core-base/framework.php` `release_source`: where the framework itself is officially published

They are intentionally orthogonal. A downstream can run automation on GitLab, consume a managed dependency from GitHub Releases, and still follow the current official `wp-core-base` framework release source.

## Top-Level Keys

The manifest returns a PHP array with these sections:

- `profile`
- `paths`
- `core`
- `runtime`
- `github`
- `gitlab`
- `automation`
- `security`
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

## `gitlab`

Keys:

- `api_base`

Use `getenv('CI_API_V4_URL') ?: 'https://gitlab.com/api/v4'` for GitLab.com or GitLab CI. Override it for self-managed GitLab instances when `gitlab-release` dependencies should default to a different host.

## `automation`

Keys:

- `provider`
- `api_base`
- `base_branch`
- `dry_run`
- `managed_kinds`

`provider` may be:

- `github`
- `gitlab`

`api_base` should point at the automation host API for the selected provider.

Examples:

- GitHub.com: `getenv('GITHUB_API_URL') ?: 'https://api.github.com'`
- GitLab.com: `getenv('CI_API_V4_URL') ?: 'https://gitlab.com/api/v4'`

`managed_kinds` limits what `sync` may update. A dependency must be both `management: managed` and listed in `automation.managed_kinds` before the updater will touch it.

## `security`

Keys:

- `managed_release_min_age_hours`
- `github_release_verification`

`managed_release_min_age_hours` defaults to `0`. Set it when you want `sync` to ignore very fresh upstream releases until they have aged for the configured number of hours.

`github_release_verification` may be:

- `none`
- `checksum-sidecar-optional`
- `checksum-sidecar-required`

This setting applies to hosted release dependencies that inherit verification mode from the repo-level default. The key name is kept for backward compatibility, but it currently covers both `github-release` and `gitlab-release`.

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
        'gitlab_project' => null,
        'gitlab_release_asset_pattern' => null,
        'gitlab_token_env' => null,
        'gitlab_api_base' => null,
        'min_release_age_hours' => null,
        'verification_mode' => 'inherit',
        'checksum_asset_pattern' => null,
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
- `source` must be `wordpress.org`, `github-release`, `gitlab-release`, `premium`, or `local`
- `managed` entries must define `version` and `checksum`
- `local` entries may define `version`, but do not need `checksum`
- `ignored` entries are excluded from runtime staging
- `plugin`, `theme`, and `mu-plugin-package` entries require `main_file`
- `mu-plugin-file`, `runtime-file`, and `runtime-directory` entries may omit `main_file`
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

Dependency-level `policy.strip_paths` entries are relative to that dependency's root path, not the repository root. For a dependency at `cms/plugins/project-plugin`, a policy entry of `build` means `cms/plugins/project-plugin/build`.

Strip-on-stage is supported for `local` entries. Use it when local source trees are intentionally richer than the final runtime payload.

## Managed Sanitation

Use `runtime.managed_sanitize_paths` and `runtime.managed_sanitize_files` for global managed-dependency sanitation rules.

Use `dependencies[].policy.sanitize_paths` and `dependencies[].policy.sanitize_files` for managed dependency-specific sanitation rules.

Dependency-level `policy.sanitize_paths` entries are relative to that dependency's root path, not the repository root. Wildcards such as `**/docs` are evaluated inside the dependency tree.

Managed sanitation applies during `sync` before the dependency is validated, copied into the repo, and checksummed. The manifest checksum for a managed dependency is therefore the checksum of the sanitized runtime tree, not the raw upstream archive.

`sanitize_paths` entries may use a `**/name` form to remove matching nested subtrees anywhere inside the managed dependency root.

Ideal packaging is still preferred: managed artifacts should already be runtime-ready. Sanitation exists to normalize common WordPress ecosystem extras such as `README*`, build metadata, or test directories when they appear in otherwise valid release archives.

`dependencies[].policy.allow_runtime_paths` is also dependency-root-relative. Use it only for narrowly-scoped child paths that should bypass runtime-hygiene checks inside that one dependency. Do not use broad root-like values that suppress most hygiene enforcement.

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
- `source_config.min_release_age_hours`
- `source_config.verification_mode`
- `source_config.checksum_asset_pattern`

The token value itself should stay in environment or repository secrets, not in the manifest.

If the upstream project publishes a detached checksum sidecar, set:

- `source_config.github_release_asset_pattern` to the actual ZIP asset
- `source_config.checksum_asset_pattern` to the checksum sidecar asset

Then choose either:

- `source_config.verification_mode: checksum-sidecar-required`
- or leave `inherit` and set `security.github_release_verification`

`source_config.min_release_age_hours` overrides the repo-level cooldown for that one dependency.

Agent-safe rule:

- inspect the real upstream release assets before setting `github_release_asset_pattern` or `checksum_asset_pattern`
- use `checksum-sidecar-required` only when the checksum file exists and binds the digest to the ZIP filename
- do not invent glob patterns from the tag name alone

## Private GitLab Dependencies

For a private GitLab release-backed dependency, use:

- `source: 'gitlab-release'`
- `source_config.gitlab_project`
- `source_config.gitlab_release_asset_pattern`
- `source_config.gitlab_token_env`
- `source_config.gitlab_api_base` when the project is not on GitLab.com
- `source_config.min_release_age_hours`
- `source_config.verification_mode`
- `source_config.checksum_asset_pattern`

The token value itself should stay in environment or CI/CD variables, not in the manifest.

If the upstream project publishes a detached checksum sidecar, set:

- `source_config.gitlab_release_asset_pattern` to the actual ZIP asset
- `source_config.checksum_asset_pattern` to the checksum sidecar asset

Then choose either:

- `source_config.verification_mode: checksum-sidecar-required`
- or leave `inherit` and set `security.github_release_verification`

If you want to rely on GitLab CI's built-in job token for release access, set `source_config.gitlab_token_env` to `CI_JOB_TOKEN`.

Concrete hardened example:

```php
'security' => [
    'managed_release_min_age_hours' => 24,
    'github_release_verification' => 'checksum-sidecar-optional',
],
'dependencies' => [
    [
        'name' => 'Example Plugin',
        'slug' => 'example-plugin',
        'kind' => 'plugin',
        'management' => 'managed',
        'source' => 'github-release',
        'path' => 'cms/plugins/example-plugin',
        'main_file' => 'example-plugin.php',
        'version' => '1.2.3',
        'checksum' => 'sha256:...',
        'archive_subdir' => '',
        'extra_labels' => [],
        'source_config' => [
            'github_repository' => 'owner/example-plugin',
            'github_release_asset_pattern' => 'example-plugin-*.zip',
            'github_token_env' => null,
            'min_release_age_hours' => 48,
            'verification_mode' => 'checksum-sidecar-required',
            'checksum_asset_pattern' => 'example-plugin-*.zip.sha256',
            'credential_key' => null,
            'provider' => null,
            'provider_product_id' => null,
        ],
        'policy' => [
            'class' => 'managed-private',
            'allow_runtime_paths' => [],
            'strip_paths' => [],
            'strip_files' => [],
            'sanitize_paths' => [],
            'sanitize_files' => [],
        ],
    ],
],
```

## Premium Managed Dependencies

Premium managed plugin sources use one fixed env-var or CI/CD variable contract:

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

Use [examples/downstream-manifest.php](examples/downstream-manifest.php) as the GitHub-first starting point.
Use [examples/downstream-manifest-gitlab.php](examples/downstream-manifest-gitlab.php) when the downstream automation host is GitLab.
