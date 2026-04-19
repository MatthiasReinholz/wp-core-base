# Security Model

This document is for maintainers and evaluators of `wp-core-base`.

## Trust Boundaries

`wp-core-base` trusts:

- the committed repository state
- the manifest and framework metadata committed in Git
- published GitHub Releases and GitLab Releases used as supported release sources
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
- GitLab Releases
- downstream-registered premium providers

Hosted release downloads can be hardened with:

- `security.github_release_verification`
- `security.managed_release_min_age_hours`
- `source_config.verification_mode`
- `source_config.checksum_asset_pattern`
- `source_config.min_release_age_hours`

Rules:

- do not require checksum-sidecar verification unless the upstream actually publishes a checksum sidecar asset
- do not guess checksum asset patterns
- do not treat redirected CDN URLs as trusted origins for auth forwarding

The repo-level `security.github_release_verification` key keeps its historical name for backward compatibility, but it currently applies to both `github-release` and `gitlab-release` dependencies that inherit verification mode from the repo default.

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

### Key Rotation

Framework signature verification supports multiple public keys selected by `key_id`.

Candidate verification keys are resolved from:

- the explicit key passed to `release-verify --public-key` (when provided)
- the default key at `tools/wporg-updater/keys/framework-release-public.pem`
- rotated key files matching `tools/wporg-updater/keys/framework-release-public-*.pem`
- optional extra paths from `WP_CORE_BASE_RELEASE_PUBLIC_KEY_PATHS` (comma-separated absolute paths)

Rotation procedure:

1. Add the new public key as `tools/wporg-updater/keys/framework-release-public-<yyyymm>.pem`.
2. Start signing new release checksums with the corresponding private key.
3. Verify release artifacts in CI with `release-verify`; verification selects the matching key via signature `key_id`.
4. Keep at least one prior key published until all supported release lines have moved to the new key.
5. Promote the new key to `framework-release-public.pem` only after old signatures are no longer needed.

Revocation procedure:

1. Remove compromised keys from `tools/wporg-updater/keys/` and any `WP_CORE_BASE_RELEASE_PUBLIC_KEY_PATHS` values.
2. Re-sign current release checksums with a trusted key.
3. Publish a security advisory noting the revoked key identifier(s) and replacement key identifier.

Future design work for explicit expiry metadata and committed revocation-list policy is documented in `docs/security-key-lifecycle-rfc.md`.

## Secret Handling

Secrets belong in environment variables, not in the manifest.

Important examples:

- `GITHUB_TOKEN`
- `GITLAB_TOKEN`
- `WP_CORE_BASE_PREMIUM_CREDENTIALS_JSON`
- `WP_CORE_BASE_RELEASE_PRIVATE_KEY_PEM`
- `WP_CORE_BASE_RELEASE_PRIVATE_KEY_PASSPHRASE`

Local release keys should not live in tracked repository paths. The ignored `tools/wporg-updater/.tmp/` path is for local scratch material only and must never be treated as release input.
