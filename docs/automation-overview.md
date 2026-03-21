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
- `pr-blocker`

## Sync Behavior

`sync` runs two reconcilers:

- WordPress core, when `core.mode` is `managed` and `core.enabled` is true
- managed dependencies from the manifest

Managed dependencies are explicit. Folder presence alone never makes something updateable.

## Dependency Update Sources

Supported automated sources:

- `wordpress.org`
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
- managed dependencies must match their manifest checksum
- `stage-runtime` assembles the runtime payload and validates the staged tree

## Pull Request Behavior

Dependency PRs use manifest metadata and stable component keys.

Rules:

- same release line + newer patch => update existing PR in place
- newer minor or major while older PR still open => open a later blocked PR
- support topics refresh incrementally for wordpress.org plugins

## Scaffolding

`scaffold-downstream` renders:

- `.wp-core-base/manifest.php`
- `.github/workflows/wporg-updates.yml`
- `.github/workflows/wporg-update-pr-blocker.yml`
- `.github/workflows/wporg-validate-runtime.yml`

Profiles:

- `full-core`
- `content-only`

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
