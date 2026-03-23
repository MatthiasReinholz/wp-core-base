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
4. `finalize-wp-core-base-release` creates and pushes the annotated tag automatically
5. `release-wp-core-base` runs from that tag and publishes the GitHub Release asset

Do not cut ad hoc tags by hand.

## Verification

Before publishing, the repo must pass:

```bash
php tools/wporg-updater/tests/run.php
php tools/wporg-updater/bin/wporg-updater.php doctor --repo-root=.
php tools/wporg-updater/bin/wporg-updater.php stage-runtime --repo-root=. --output=.wp-core-base/build/runtime
php tools/wporg-updater/bin/wporg-updater.php release-verify --repo-root=.
```

`release-verify` checks:

- `.wp-core-base/framework.php` exists and is coherent
- the framework version is valid SemVer
- the matching `docs/releases/<version>.md` file exists
- required release-note sections are present
- the bundled WordPress baseline is mentioned in the release notes

## GitHub Flow

The release flow is intentionally staged:

- `prepare-wp-core-base-release` derives the version bump, updates `.wp-core-base/framework.php`, scaffolds `docs/releases/<version>.md` when needed, and opens `release/vX.Y.Z`
- `finalize-wp-core-base-release` reacts only to a merged release PR into `main` and creates the annotated tag from the merge commit
- `release-wp-core-base` runs only from pushed SemVer tags, re-runs the release gates, and publishes the vendorable snapshot asset `wp-core-base-vendor-snapshot.zip`

This keeps release intent reviewable in a PR instead of bundling version bumps, tagging, and publishing into one manual step.

## Branch Protection Expectations

The default branch should require:

- the main CI workflow
- passing runtime validation
- passing tests
- passing release metadata verification

Release publishing should happen only from the default branch state that already passed those checks.
