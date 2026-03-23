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

1. prepare a normal PR that updates:
   - `.wp-core-base/framework.php`
   - `docs/releases/<version>.md`
   - any user-facing baseline references that changed
2. merge that PR only after the normal CI checks pass on the protected default branch
3. publish the release through the manual GitHub Actions release workflow

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

The release workflow is intentionally hybrid-gated:

- CI is automated
- publishing is manual through `workflow_dispatch`
- the workflow checks out the protected default branch, re-runs the release gates, creates the annotated tag, and publishes the GitHub Release

The published release attaches the vendorable snapshot asset `wp-core-base-vendor-snapshot.zip`.

## Branch Protection Expectations

The default branch should require:

- the main CI workflow
- passing runtime validation
- passing tests
- passing release metadata verification

Release publishing should happen only from the default branch state that already passed those checks.
