# Security Model

This document is for maintainers and evaluators of `wp-core-base`.

## Trust Boundaries

`wp-core-base` trusts:

- the committed repository state
- the manifest and framework metadata committed in Git
- published GitHub Releases used as supported release sources
- detached signature verification for framework release checksum sidecars

It does not trust:

- raw Git tags without releases as managed dependency inputs
- live Git working trees as managed dependency inputs
- symlinked runtime trees
- unsigned framework checksum sidecars

## Managed Download Model

Managed dependencies may come from:

- WordPress.org
- GitHub Releases
- downstream-registered premium providers

GitHub release downloads can be hardened with:

- `security.github_release_verification`
- `security.managed_release_min_age_hours`
- `source_config.verification_mode`
- `source_config.checksum_asset_pattern`
- `source_config.min_release_age_hours`

Rules:

- do not require checksum-sidecar verification unless the upstream actually publishes a checksum sidecar asset
- do not guess checksum asset patterns
- do not treat redirected CDN URLs as trusted origins for auth forwarding

## Runtime Integrity

Runtime integrity depends on:

- strict separation of `managed`, `local`, and `ignored`
- runtime hygiene checks
- symlink rejection
- sanitized checksums for managed dependencies
- staged runtime validation before deployment

## Framework Release Trust

Framework releases use:

- `wp-core-base-vendor-snapshot.zip`
- `wp-core-base-vendor-snapshot.zip.sha256`
- `wp-core-base-vendor-snapshot.zip.sha256.sig`

`release-verify` validates:

- framework metadata coherence
- release notes completeness
- bundled baseline coherence
- artifact checksum
- detached signature
- downstream installation of the published snapshot

The release snapshot intentionally excludes temp paths, CI-only material, and framework tests.

## Secret Handling

Secrets belong in environment variables, not in the manifest.

Important examples:

- `GITHUB_TOKEN`
- `WP_CORE_BASE_PREMIUM_CREDENTIALS_JSON`
- `WP_CORE_BASE_RELEASE_PRIVATE_KEY_PEM`
- `WP_CORE_BASE_RELEASE_PRIVATE_KEY_PASSPHRASE`

Local release keys should not live in tracked repository paths. The ignored `tools/wporg-updater/.tmp/` path is for local scratch material only and must never be treated as release input.
