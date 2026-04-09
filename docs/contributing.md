# Contributing To wp-core-base

This guide is for contributors, maintainers, and the repository author.

If you are a downstream user, return to [../README.md](../README.md).

## Project Responsibility

`wp-core-base` is a reusable upstream, not a site-specific application.

Contributors should preserve:

- a clean upstream/downstream boundary
- explicit runtime contracts
- explicit dependency ownership
- clear user documentation separated from maintainer detail

## Documentation Boundaries

- `README.md` is for downstream users
- `docs/getting-started.md` is for downstream onboarding
- `docs/contributing.md` is for maintainers and contributors
- `docs/architecture.md` is the maintainer map of subsystems and invariants
- `docs/security-model.md` is the maintainer trust-boundary and release-trust reference
- `docs/automation-overview.md` is for technical internals

Do not move contributor-only detail back into `README.md` unless downstream users truly need it to adopt the framework.

## Verification

Before shipping changes, run:

```bash
php tools/wporg-updater/tests/run.php
php tools/wporg-updater/bin/wporg-updater.php doctor --repo-root=.
php tools/wporg-updater/bin/wporg-updater.php stage-runtime --repo-root=. --output=.wp-core-base/build/runtime
php tools/wporg-updater/bin/wporg-updater.php release-verify --repo-root=.
php scripts/ci/check_workflow_examples_and_permissions.php
```

Also syntax-check touched PHP files with `php -l`.

The CI workflow also runs PHPStan and workflow linting. Treat those as release-blocking signals, not optional cleanup work.

## Baseline Changes

When changing the bundled baseline:

1. keep `.wp-core-base/manifest.php` aligned
2. update managed dependency checksums if managed trees changed
3. keep the `Current Baseline` section in `README.md` accurate
4. make sure the repository still passes `doctor` and `stage-runtime`

## Scaffolding Changes

If you change downstream scaffolding, keep these aligned:

- `tools/wporg-updater/templates/`
- `docs/examples/`
- `docs/getting-started.md`
- `docs/manifest-reference.md`

## Release Discipline

Treat tags as the contract with downstream users.

The framework release version is pinned in `.wp-core-base/framework.php`, not in the runtime manifest.

Downstream framework self-update depends on that version metadata and on the published vendorable release asset, so release hygiene matters directly for downstream automation.

Use [release-process.md](release-process.md) for the maintainer checklist.

## Support Expectations

- treat the CLI, manifest shape, framework metadata shape, and scaffolded workflow filenames as compatibility-sensitive
- prefer upstream fixes in `wp-core-base` over downstream workarounds when the framework contract is wrong or ambiguous
- if a change affects trust boundaries, release verification, or agent behavior, update the maintainer docs in the same change
