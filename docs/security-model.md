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
- generic JSON metadata endpoints
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

`generic-json` is currently metadata-only. It can resolve and download installable archives when the endpoint advertises a valid release timestamp, but it does not support checksum-sidecar hardening or release-side provenance checks today.

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

The official release ZIP contains framework tooling and metadata, templates, public keys, and documentation. An explicit allowlist excludes runtime core/plugins, private keys, temporary paths, CI-only material, and framework tests. The optional starter baseline remains in the tagged source repository. A generated inventory is checked independently against extracted files.

### Key Rotation

Framework signature verification supports multiple public keys selected by `key_id`.

Candidate verification keys are resolved from:

- the explicit key passed to `release-verify --public-key-file` (when provided)
- the default key at `tools/wporg-updater/keys/framework-release-public.pem`
- rotated key files matching `tools/wporg-updater/keys/framework-release-public-*.pem`
- optional extra paths from `WP_CORE_BASE_RELEASE_PUBLIC_KEY_PATHS` (comma-separated absolute paths)

Rotation procedure:

1. Add the new public key as `tools/wporg-updater/keys/framework-release-public-<yyyymm>.pem`.
2. Publish a bridge release containing the new public key while still signing with the previously trusted key. Downstreams must adopt that key-bearing release or receive the new key through an independently verified trust update.
3. Start signing later release checksums with the corresponding new private key after the migration window.
4. Verify release artifacts in CI with `release-verify`; verification selects the matching key via signature `key_id`.
5. Keep at least one prior key published until all supported release lines have moved to the new key.
6. Promote the new key to `framework-release-public.pem` only after old signatures are no longer needed.

Revocation procedure:

1. Remove compromised keys from `tools/wporg-updater/keys/` and any `WP_CORE_BASE_RELEASE_PUBLIC_KEY_PATHS` values.
2. Publish a new corrective release signed with a trusted key, and distribute the key removal through an independently trusted channel. An already installed client must receive the trust change; publishing a replacement key does not revoke its existing local key automatically.
3. Publish a security advisory noting the revoked key identifier(s) and replacement key identifier.

Future design work for explicit expiry metadata and committed revocation-list policy is documented in [security-key-lifecycle-rfc.md](security-key-lifecycle-rfc.md).

## Secret Handling

Secrets belong in environment variables, not in the manifest.

Important examples:

- `GITHUB_TOKEN`
- `GITLAB_TOKEN`
- `WP_CORE_BASE_PREMIUM_CREDENTIALS_JSON`
- `WP_CORE_BASE_RELEASE_PRIVATE_KEY_PEM`
- `WP_CORE_BASE_RELEASE_PRIVATE_KEY_PASSPHRASE`

Local release keys should not live in tracked repository paths. The ignored `tools/wporg-updater/.tmp/` path is for local scratch material only and must never be treated as release input.

## Managed Pull Request Branch Cleanup

Automation branch deletion is allowed only after a pull request close decision.
The cleanup path re-reads manually closed pull requests from the hosting API and
requires a recognized automation label, valid framework metadata, a
same-repository head, exact metadata/head branch agreement, the expected managed
branch prefix, and a head that is neither the base nor default branch. The host
must supply a PR head SHA, which must match the remote branch; deletion uses a
Git force-with-lease so a concurrent force-push is rejected atomically.

The GitHub `pull_request_target` cleanup job checks out the trusted repository
default branch explicitly and never executes code from the pull request head.
Its permissions are limited to reading pull-request metadata and deleting the
managed Git ref. Review approval, workflow authorization, and merging remain
separate human-controlled actions.

## Filesystem, transport, and recovery contracts

Staging validates canonical relative paths against repository metadata, core, framework tooling, configured distribution, and runtime source roots, including symlink ancestors. It builds privately and publishes only a complete validated output; validation failure retains the prior output, and failed restoration preserves recovery backups. A private marked temporary workspace has an ownership identity and active-operation lock. The janitor never deletes old prefix-named directories solely because of their name or age, and it excludes preserved recovery workspaces.

All repository-dependent CLI commands share one checkout lock and load mutable state after acquiring it; help is exempt. The lock coordinates framework commands in that checkout, not arbitrary editors or separate clones. Remote Git writes and local Git recovery use exact expected revisions; unexpected concurrent edits and ambiguous push outcomes retain recovery evidence. Recovery attempts all recorded restores, reports failures, and keeps backups if any restore fails. See [filesystem decisions](decisions/001-filesystem-recovery.md) and [Git mutation decisions](decisions/002-mutations-and-git.md).

Asset transport checks every HTTPS redirect hop and never restores credentials after an origin change. The signed framework payload must also match the advertised version, configured authoritative repository/API origin, and asset name; a valid signature alone does not authorize a different release. See [network trust decisions](decisions/004-network-trust.md).

For vulnerability reporting and supported security response expectations, see [SECURITY.md](../SECURITY.md).

A download-host allowlist authorizes a destination, not credential forwarding. GitHub and GitLab asset requests bind initial credentials to the configured API origin, including its port. Cross-origin redirects retain only `Accept`, `Accept-Encoding`, `Accept-Language`, and `User-Agent` from caller headers. Ordinary API GETs do not follow redirects implicitly; sidecar GETs opt in with a one MiB response limit. Non-HTTPS, user-information-bearing, malformed, looping, or over-budget redirects fail without retrying the policy violation. See [premium provider credential origins](adding-premium-provider.md#credential-origins).

Publication reruns do not replace existing published bytes. On failure, the publisher preserves remote releases and tags and reports its receipt journal for operator recovery. It does not attempt deletion based on a draft observation, because release publication can race that check and the API offers no conditional delete. Security corrections or key changes normally need a new verified release; any exceptional repair to an existing release requires an explicit, separately reviewed operator action. See [release recovery](release-process.md#recovery-evidence).
