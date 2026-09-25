# Release Process

This document is for maintainers of `wp-core-base`.

## Goal

Each release should be a deliberate, validated framework state that downstream users can pin and adopt through normal Git review.

## Release Identity

`wp-core-base` uses dual versioning:

- framework releases use SemVer tags such as `v1.0.0`
- the bundled WordPress baseline remains separate metadata in `.wp-core-base/framework.php` and in the release notes

The source of truth for framework release identity is `.wp-core-base/framework.php`.
It records exactly one authoritative official release source at a time.

## Backward Compatibility

The multi-host refactor keeps GitHub behavior as the compatibility baseline:

- GitHub remains the default automation provider
- existing `github-release` dependency definitions remain valid
- legacy framework metadata that only records `repository` still loads
- the framework release source stays singular, and the current official source remains GitHub Releases

## Required Release Files

Each release must include:

- `.wp-core-base/framework.php`
- `docs/releases/<version>.md`

The release-notes file must contain:

- `Summary`
- `Downstream Impact`
- `Migration Notes`
- `Downstream Workflow Changes`
- `Required Downstream Actions`
- `Bundled Baseline`

## Maintainer Flow

1. run the manual `prepare-wp-core-base-release` workflow
2. update the README baseline section and the release-note bundled-baseline section together when bundled versions changed
3. review the generated `release/vX.Y.Z` pull request like any normal code change
4. call out any framework-managed workflow or pipeline template changes under `Downstream Workflow Changes`
5. list the actual downstream rollout steps, if any, under `Required Downstream Actions`
6. merge that release PR only after the normal CI checks pass on the protected default branch
7. `finalize-wp-core-base-release` verifies the merged release PR and its required CI run, then creates and pushes the annotated tag automatically and publishes the GitHub Release asset
8. use `release-wp-core-base` only as the manual recovery workflow for an already existing tag

Do not cut ad hoc tags by hand.

## Verification

Before publishing, the repo must pass:

```bash
php tools/wporg-updater/tests/run.php
php tools/wporg-updater/bin/wporg-updater.php doctor --repo-root=.
php tools/wporg-updater/bin/wporg-updater.php stage-runtime --repo-root=. --output=.wp-core-base/build/runtime
php tools/wporg-updater/bin/wporg-updater.php release-verify --repo-root=.
php scripts/ci/verify_downstream_fixture.php --profile=full-core
php scripts/ci/verify_downstream_fixture.php --profile=content-only
```

`release-verify` checks:

- `.wp-core-base/framework.php` exists and is coherent
- the framework version is valid SemVer
- the matching `docs/releases/<version>.md` file exists
- required release-note sections are present
- the bundled WordPress baseline is mentioned in the release notes
- the public contract is coherent across README, framework metadata, manifest-managed dependency versions, and the current release notes
- when `--artifact`, `--checksum-file`, and `--signature-file` are provided, the checksum sidecar signature verifies against the framework release public key before the artifact checksum is trusted
- the built vendored snapshot checksum matches and the artifact installs into a temporary downstream copy

When the bundled core or plugin baseline changes, also run the database-backed [upgrade and rollback fixture](baseline-upgrades.md#repository-upgrade-fixture) for both profiles and order-storage modes. Fresh-install smoke checks alone do not establish that an existing store survives a database migration.

## GitHub Flow

The release flow is intentionally staged:

- `prepare-wp-core-base-release` derives the version bump, refreshes an existing release branch when appropriate, updates `.wp-core-base/framework.php`, scaffolds `docs/releases/<version>.md` when needed, and opens `release/vX.Y.Z`
- `finalize-wp-core-base-release` reacts only to a merged release PR into `main`, verifies that the exact merged commit already passed `wp-core-base CI` on `main`, creates the annotated tag from the merge commit, builds the vendorable snapshot through `build-release-artifact`, and publishes `wp-core-base-vendor-snapshot.zip` plus its SHA-256 checksum file
- `finalize-wp-core-base-release` also signs the checksum sidecar and publishes the detached signature `wp-core-base-vendor-snapshot.zip.sha256.sig`
- both publish workflows verify the uploaded draft assets against the freshly built snapshot before publication
- `release-wp-core-base` is the manual recovery workflow for publishing a GitHub Release from an already existing tag after a failed finalize run, including checksum-sidecar signing and asset freshness checks against the current tag build

This keeps release intent reviewable in a PR instead of bundling version bumps, tagging, and publishing into one manual step.

Official artifacts are built from an exact immutable Git commit through an explicit allowlist. Dirty, untracked, ignored, runtime-core, plugin, test, private-key, cache, and local configuration inputs are excluded. The retained snapshot root and asset name remain compatible with the v1.4.8 installer, while the payload contains only tooling, templates, public keys, documentation, and framework/runtime metadata.

Each artifact includes `.wp-core-base/release-inventory.json` with source revision and per-file hashes, sizes, and normalized modes. Sorted ZIP entries use a fixed timestamp normalized in UTC, normalized modes, and stored compression for reproducibility across compression-library versions. The verifier independently compares the inventory to extracted contents before installation. Historical full snapshots remain accepted.

The publication helper creates a draft, uploads assets, verifies the uploaded bytes and metadata, and only then publishes it. A resource journal records positive creation receipts: the exact tag object and numeric release ID. Existing releases and tags are never deleted or overwritten by a rerun. An identical published release is a no-op; inconsistent existing releases require explicit operator recovery. Failed publication never automatically deletes a remote release or tag. GitHub release deletion has no conditional state check: a release observed as a draft can be published by another actor before a delete arrives. Positive receipts therefore support operator recovery, not permission for automatic deletion. An ambiguous network outcome preserves state and the journal rather than guessing whether a mutation succeeded.

`build-release-artifact --source-revision=<commit>` selects the immutable input explicitly. `--fixture` is solely for a non-Git development fixture, cannot replace an official build, and records `fixture` provenance. Release tooling never silently falls back to working-tree inputs.

## Authoritative Source Changes

The framework release source is intentionally singular.

- `.wp-core-base/framework.php` records exactly one authoritative official release source at a time
- downstream `framework-sync` follows the source recorded in the installed framework metadata
- current upstream publication remains GitHub-specific until maintainers intentionally migrate that official source

If the authoritative source ever moves to a different Git platform, treat it as a coordinated trust migration:

1. prepare and independently verify the future source host, API base, repository/project identity, public keys, and publication flow
2. announce the migration through the currently trusted source while keeping that release's embedded source identity unchanged
3. each downstream explicitly reviews and commits the new `release_source` coordinates in its installed `.wp-core-base/framework.php`; preserve the trusted key overlap and record the operator's verification
4. publish a matching, non-downgrade release on the new source and run normal signed preflight against that deliberately configured source
5. maintain an announced migration window for downstreams that still follow the old source

A normal framework update cannot silently change its authoritative source or asset name: the signed payload must match the installed trust configuration. Merely adopting a release that embeds different coordinates will fail identity validation. The framework does not discover parallel legacy sources or infer permission to migrate them.

## Release Signing

Framework release provenance now uses a detached signature over the checksum sidecar:

- the vendored snapshot remains `wp-core-base-vendor-snapshot.zip`
- the checksum sidecar remains `wp-core-base-vendor-snapshot.zip.sha256`
- the detached signature is `wp-core-base-vendor-snapshot.zip.sha256.sig`
- the verification public key lives at `tools/wporg-updater/keys/framework-release-public.pem`

The publish workflows require these GitHub Actions secrets:

- `WP_CORE_BASE_RELEASE_PRIVATE_KEY_PEM`
- `WP_CORE_BASE_RELEASE_PRIVATE_KEY_PASSPHRASE` if the private key is encrypted

Downstream `framework-sync` now verifies the detached signature before trusting the checksum sidecar. A checksum file from the release origin is no longer sufficient by itself.

### Signing Key Rotation Runbook

Use this procedure when rotating framework release signing keys:

1. Generate the new keypair outside the repository and keep the private key in your secret manager.
2. Commit only the new public key as `tools/wporg-updater/keys/framework-release-public-<yyyymm>.pem`.
3. Publish a bridge release containing the new public key while still signing with the previous trusted key. Allow downstreams to adopt that release; clients that skip it need an independently verified key update before they can trust the later signer.
4. After the announced migration window, configure release workflows to sign with the new private key secret.
5. Run `release-verify` against a signed artifact and confirm verification succeeds with the new key.
6. Keep the prior public key committed during the overlap window so existing release lines remain verifiable.
7. After the overlap window ends, update `tools/wporg-updater/keys/framework-release-public.pem` to the active key and remove fully retired rotated public keys.

Key selection order during verification:

- `--public-key-file` CLI override (if passed)
- `tools/wporg-updater/keys/framework-release-public.pem`
- `tools/wporg-updater/keys/framework-release-public-*.pem`
- absolute paths from `WP_CORE_BASE_RELEASE_PUBLIC_KEY_PATHS` (comma-separated)

Emergency rotation (suspected compromise):

1. Remove compromised public keys from committed key paths and any `WP_CORE_BASE_RELEASE_PUBLIC_KEY_PATHS` values.
2. Rotate signing secrets to a known-good private key.
3. Prepare and verify a new corrective release signed by the trusted key. Existing published releases remain immutable under the normal publication helper; any exceptional historical-asset repair needs separate, explicit operator review.
4. Publish a security advisory with revoked key ID(s), replacement key ID, and affected version range.

## Branch Protection Expectations

The default branch should require:

- the main CI workflow
- passing runtime validation
- passing tests
- passing release metadata verification

Release publishing should happen only from the default branch state that already passed those checks.
The publish workflows enforce that requirement directly by checking the successful `wp-core-base CI` push run for the exact merged release commit instead of assuming branch protection was configured correctly.

## Exact-revision security review

Both publication workflows run `scripts/ci/check_security_review.php` before building or signing the framework artifact. They retain `contents: write` for publishing and grant `security-events: read` for scanner evidence. Normal PR CI runs the offline register and source-hash checks without scanner access.

Local source check:

```bash
php scripts/ci/check_security_review.php
```

For the live check, check out the proposed immutable commit and supply its full SHA:

```bash
php scripts/ci/check_security_review.php --github --repo=MatthiasReinholz/wp-core-base --commit="$(git rev-parse HEAD)" --wait-seconds=1200
```

The live reader requires authenticated GitHub CLI access to Actions and code scanning. In Actions it binds the register to `GITHUB_REPOSITORY`; local use requires that environment identity or an explicit `--repo`, with conflicting overrides rejected. It checks the source register and reviewed files against committed bytes, the repository identity, and the latest exact-commit scanner run on the default branch. Every required analysis must come from that run attempt or later, without errors or warnings. Paginated inventories must be complete and internally consistent. If a newer default-branch scan replaces the alert instances before an older revision is published, the older revision fails the exact-SHA check even if it has a successful historical analysis. Obtain fresh scanner evidence for that exact intended revision on the default-branch ref, or prepare a new reviewed release; do not substitute later-branch evidence. Pending scans may wait for the configured interval; failed scans, incomplete evidence, unexpected alerts or changed findings fail closed. A failed check may be rerun after evidence is available; do not bypass it. If repeating the scanner, rerun all jobs: repeating only failed jobs can leave one category older than the new attempt and does not satisfy freshness.

The register at `.github/security-review.json` is a reviewed-open-findings record, not a security exception that dismisses alerts. It binds findings to exact locations and source hashes. Renew it only after reviewing changed sources and scanner results; a previously recorded alert disappearing also requires review. See the [baseline review](security-reviews/2026-09-25-baseline.md). Successful output explicitly retains `runtime_security_cleared: false` and appears in the publication job summary.

This policy belongs to this source repository, not to downstream consumers. Recovery for a historical tag still requires exact-revision evidence; a tag without the register or current matching scanner instances cannot satisfy the new gate. Prefer a newly reviewed corrective release instead of weakening the gate or replacing published assets.

## Recovery evidence

A failed publication prints the journal path and its non-secret JSON contents to the job log. Both publication workflows also preserve that JSON in an always-run job-summary step because hosted runner files disappear after the job. Inspect its `created_tag_object`, `created_release_id`, `ambiguous`, and `published` fields together with current remote state before making a manual repair. The helper preserves remote releases and tags on every failure, including confirmed creation followed by a later error. Inspect current state and coordinate with other publishers before any explicit operator repair; a draft read alone does not prove deletion remains safe. Preserve a release whose status cannot be established. Successful framework installation commits before backup cleanup; a cleanup warning does not reverse the install. If any restore fails, recovery paths are retained and reported for inspection.

See [immutable artifact decisions](decisions/003-release-artifacts.md) and [filesystem recovery decisions](decisions/001-filesystem-recovery.md).


## Distribution compatibility and measurements

The framework ZIP keeps the `wp-core-base-vendor-snapshot.zip` asset name and `wp-core-base/` root while removing the optional runtime starter payload. Compatibility tests exercise the v1.4.8 installer with the smaller payload across both repository profiles and automation hosts, and the current consumer with a historical full snapshot. A framework update changes tooling and framework metadata; its recorded WordPress/plugin baseline does not install those versions into a downstream runtime.

ZIP entry modes do not guarantee extracted filesystem modes: PHP's `ZipArchive::extractTo` discards executable attributes. The current installer explicitly restores only the approved `bin/wp-core-base` launcher to `0755` before replacing the vendor tree, and release verification invokes that launcher directly. An old v1.4.8 installer cannot perform this new repair; its migration requires `chmod +x vendor/wp-core-base/bin/wp-core-base` after installation and committing the mode change. The exact legacy compatibility tests include that documented repair before direct CLI execution.

Use a tagged source checkout or Git source archive when a new full-core project needs the optional WordPress/plugin starter. GitHub's automatically generated source archives are source snapshots, not the signed framework ZIP. Verify source identity separately and run the normal runtime checks before deployment.

For repeatable package-cost measurements, use the maintainer harness described in [evaluating alternatives](evaluating-alternatives.md#measuring-package-cost). Keep raw results and artifact hashes with release evidence; do not interpret extraction timing as application throughput.
