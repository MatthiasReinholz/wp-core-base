# Automation Overview

This document is for contributors and advanced downstream users who need the technical internals.

If you only need adoption guidance, start with [../README.md](../README.md) and [getting-started.md](getting-started.md).

## Architecture

The framework now revolves around a single manifest:

- `.wp-core-base/manifest.php`

That manifest drives:

- repository profile
- root paths
- core management mode
- runtime staging policy
- dependency ownership and update eligibility

The legacy `.github/wporg-updates.php` model is no longer the primary configuration surface.

## Commands

The CLI supports:

- `doctor`
- `sync`
- `stage-runtime`
- `scaffold-downstream`
- `suggest-manifest`
- `format-manifest`
- `pr-blocker`

## Sync Behavior

`sync` runs two reconcilers:

- WordPress core, when `core.mode` is `managed` and `core.enabled` is true
- managed dependencies from the manifest

Managed dependencies are explicit. Folder presence alone never makes something updateable.

`sync` only considers manifest entries that are:

- `management: managed`
- included in `automation.managed_kinds`

## Dependency Update Sources

Supported automated sources:

- `WordPress.org`
- `github-release`

GitHub source handling uses stable Releases as the source of truth. It does not infer release state from raw tags or commit history.

Private GitHub release assets are supported through:

- `source_config.github_repository`
- `source_config.github_release_asset_pattern`
- `source_config.github_token_env`

The download flow never forwards authorization headers to redirected CDN URLs.

## Runtime Hygiene

The runtime contract is enforced by:

- `RuntimeInspector`
- `RuntimeStager`

Key behavior:

- managed and local dependencies are checked for forbidden files and directories
- managed dependencies may be sanitized before validation and must match their sanitized manifest checksum
- `stage-runtime` assembles the runtime payload and validates the staged tree
- undeclared runtime paths are errors in `strict` mode and warnings in `relaxed` mode
- file-level runtime entries such as `mu-plugin-file` and `runtime-file` are supported
- content-root runtime directories can be modeled explicitly as `runtime-directory`
- staged-clean validation can strip configured files and directories from local code before final runtime validation

## Pull Request Behavior

Dependency PRs use manifest metadata and stable component keys.

Rules:

- same release line + newer patch => update existing PR in place
- newer minor or major while older PR still open => open a later blocked PR
- support topics refresh incrementally for WordPress.org plugins

## Scaffolding

`scaffold-downstream` renders:

- `.wp-core-base/manifest.php`
- `.github/workflows/wporg-updates.yml`
- `.github/workflows/wporg-update-pr-blocker.yml`
- `.github/workflows/wporg-validate-runtime.yml`

Profiles:

- `full-core`
- `content-only`
- `content-only-default`
- `content-only-migration`
- `content-only-local-mu`
- `content-only-image-first`

Scaffolded manifests include:

- `automation.managed_kinds`
- `runtime.manifest_mode`
- `runtime.validation_mode`
- `runtime.ownership_roots`
- `runtime.staged_kinds`
- `runtime.validated_kinds`
- `runtime.managed_sanitize_paths`
- `runtime.managed_sanitize_files`

## Managed Dependency Packaging Contract

Managed dependencies should arrive as runtime-ready artifacts.

Recommended discipline:

- publish release archives, not raw source checkouts, as deployable inputs
- exclude docs, tests, screenshots, and build-only files from managed runtime artifacts
- use managed sanitation only to normalize common upstream noise, not as a substitute for release packaging discipline
- use strip-on-stage primarily for `local` downstream-owned code, not as the normal packaging strategy for managed releases

## Tests

Local verification is intentionally lightweight and self-contained:

```bash
php tools/wporg-updater/tests/run.php
```

That test suite covers:

- manifest loading
- release parsing
- support-forum parsing
- runtime staging
- scaffolding
- migration guardrails
