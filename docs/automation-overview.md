# Automation Overview

This document is for contributors and advanced downstream users who need the technical internals.

If you only need adoption guidance, start with [../README.md](../README.md) and [getting-started.md](getting-started.md).

## Architecture

The framework now revolves around two explicit metadata files:

- `.wp-core-base/manifest.php`
- `.wp-core-base/framework.php`

That manifest drives:

- repository profile
- root paths
- core management mode
- runtime staging policy
- dependency ownership and update eligibility
- installed framework version and vendored distribution path

The legacy `.github/wporg-updates.php` model is no longer the primary configuration surface.

## Commands

The CLI supports:

- `doctor`
- `sync`
- `stage-runtime`
- `scaffold-downstream`
- `framework-sync`
- `refresh-admin-governance`
- `release-verify`
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

Dependency-source failures are isolated per managed dependency. If one plugin or theme source fails, `sync` still continues processing the remaining managed dependencies and reports the failed sources as warnings at the end of the run.

The recommended workflow pattern is:

- run `sync --report-json=... --fail-on-source-errors`
- keep healthy updates and PR refreshes from that same run
- publish the sync report into the GitHub Actions job summary
- open or update one deduplicated issue for ongoing source failures
- close that issue automatically once a later sync run is clean

The scaffolded automation now separates:

- scheduled or manual update runs
- merged-PR reconciliation runs

That keeps queued follow-up updates moving after a merge without mixing `pull_request_target` behavior into the scheduled/manual update workflow.

## Dependency Update Sources

Supported automated sources:

- `WordPress.org`
- `github-release`
- `premium`

GitHub source handling uses stable Releases as the source of truth. It does not infer release state from raw tags or commit history.

Private GitHub release assets are supported through:

- `source_config.github_repository`
- `source_config.github_release_asset_pattern`
- `source_config.github_token_env`

The download flow never forwards authorization headers to redirected CDN URLs.

Premium source handling uses one fixed credentials env var:

- `WP_CORE_BASE_PREMIUM_CREDENTIALS_JSON`

When you use `source: premium`, set `source_config.provider` to a provider key registered in `.wp-core-base/premium-providers.php`.

The manifest stores only source identity and lookup keys. It never stores premium license keys, site-linked API tokens, or signed download URLs.

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
- one dependency/version pair => one live PR; duplicate PRs for the same target are closed as superseded
- when the base branch changes after another automation PR merges, open dependency PRs are rebuilt onto the new base branch state so their manifest/checksum baseline stays current
- if the target version is already present on the base branch after reconciliation, the stale PR is closed instead of being kept as a no-op
- support topics refresh incrementally for WordPress.org plugins

Framework PRs use the same queueing behavior, but operate on the vendored `wp-core-base` snapshot and `.wp-core-base/framework.php`.

Core and framework automation now follow the same stale/no-op rules as dependency PRs:

- duplicate PRs for the same target version are collapsed to one canonical PR
- if reconciliation discovers that the base branch already contains the target version, the stale PR is closed automatically

Framework release artifacts are now also built through one explicit builder path instead of repeated workflow-local shell snippets. That keeps artifact exclusion rules and release hygiene consistent across CI, finalize, and manual recovery workflows.

## Scaffolding

`scaffold-downstream` renders:

- `.wp-core-base/manifest.php`
- `.wp-core-base/framework.php`
- `.github/workflows/wporg-updates.yml`
- `.github/workflows/wporg-updates-reconcile.yml`
- `.github/workflows/wporg-update-pr-blocker.yml`
- `.github/workflows/wporg-validate-runtime.yml`
- `.github/workflows/wp-core-base-self-update.yml`
- a framework-managed admin governance MU plugin loader
- a generated admin governance data file

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

The governance data file is also refreshed automatically after dependency authoring commands and framework self-updates so wp-admin can keep reflecting the manifest’s ownership model without reading `.wp-core-base/manifest.php` at runtime.

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
- framework metadata loading
- framework release-note validation
- release parsing
- support-forum parsing
- runtime staging
- scaffolding
- framework install behavior for vendored downstreams
- migration guardrails
