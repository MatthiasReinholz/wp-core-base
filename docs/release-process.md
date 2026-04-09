# Release Process

This document is for maintainers of `wp-core-base`.

## Goal

Each release should be a deliberate, validated framework state that downstream users can pin and adopt through normal Git review.

## Release Identity

`wp-core-base` uses dual versioning:

- framework releases use SemVer tags such as `v1.0.0`
- the bundled WordPress baseline remains separate metadata in `.wp-core-base/framework.php` and in the release notes

The source of truth for framework release identity is `.wp-core-base/framework.php`.

## Required Release Files

Each release must include:

- `.wp-core-base/framework.php`
- `docs/releases/<version>.md`

The release-notes file must contain:

- `Summary`
- `Downstream Impact`
- `Migration Notes`
- `Bundled Baseline`

## Maintainer Flow

1. run the manual `prepare-wp-core-base-release` workflow
2. review the generated `release/vX.Y.Z` pull request like any normal code change
3. merge that release PR only after the normal CI checks pass on the protected default branch
4. `finalize-wp-core-base-release` verifies the merged release PR and its required CI run, then creates and pushes the annotated tag automatically and publishes the GitHub Release asset
5. use `release-wp-core-base` only as the manual recovery workflow for an already existing tag

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

## GitHub Flow

The release flow is intentionally staged:

- `prepare-wp-core-base-release` derives the version bump, refreshes an existing release branch when appropriate, updates `.wp-core-base/framework.php`, scaffolds `docs/releases/<version>.md` when needed, and opens `release/vX.Y.Z`
- `finalize-wp-core-base-release` reacts only to a merged release PR into `main`, verifies that the exact merged commit already passed `wp-core-base CI` on `main`, creates the annotated tag from the merge commit, builds the vendorable snapshot through `build-release-artifact`, and publishes `wp-core-base-vendor-snapshot.zip` plus its SHA-256 checksum file
- `finalize-wp-core-base-release` also signs the checksum sidecar and publishes the detached signature `wp-core-base-vendor-snapshot.zip.sha256.sig`
- both publish workflows verify that the GitHub Release assets match the freshly built local snapshot after publication
- `release-wp-core-base` is the manual recovery workflow for publishing a GitHub Release from an already existing tag after a failed finalize run, including checksum-sidecar signing and asset freshness checks against the current tag build

This keeps release intent reviewable in a PR instead of bundling version bumps, tagging, and publishing into one manual step.

The artifact builder applies explicit exclusions for non-release material such as temp paths, CI-only scripts, and framework tests so the vendored snapshot boundary stays predictable.

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

## Branch Protection Expectations

The default branch should require:

- the main CI workflow
- passing runtime validation
- passing tests
- passing release metadata verification

Release publishing should happen only from the default branch state that already passed those checks.
The publish workflows enforce that requirement directly by checking the successful `wp-core-base CI` push run for the exact merged release commit instead of assuming branch protection was configured correctly.
