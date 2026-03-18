# Contributing To wp-core-base

This guide is for contributors, maintainers, and the repository author of `wp-core-base`.

If you are only using `wp-core-base` as a dependency in another WordPress project, go back to [../README.md](../README.md) or use [downstream-usage.md](downstream-usage.md). This document is not written for downstream users.

## Project Responsibility

`wp-core-base` exists to maintain a clean, versioned WordPress base that downstream repositories can consume.

Contributors should optimize for:

- a clean upstream/downstream boundary
- predictable tagged releases
- reliable update automation
- minimal ambiguity about what belongs in the base and what belongs in downstream projects

## Repository Boundaries

The repository should remain a reusable base, not a site-specific application.

That means:

- WordPress core lives at the repository root
- reusable automation lives in `.github/` and `tools/`
- documentation for users and contributors lives in `docs/`
- site-specific code should not be added here unless it is intentionally part of the reusable base

If a change only makes sense for one downstream project, it probably does not belong in `wp-core-base`.

## Audience Separation

The documentation is intentionally split by audience:

- `README.md` is the entry point for downstream users
- `docs/getting-started.md` is the first-stop guide for new adopters
- `docs/deployment-models.md` explains GitHub, FTP, and deployment architecture choices
- `docs/downstream-usage.md` is for people consuming the base
- `docs/contributing.md` is for contributors and maintainers
- `docs/release-process.md` is the maintainer checklist for tagged releases
- `docs/automation-overview.md` contains the technical details that should not clutter the main README

Do not move maintainer-only implementation detail back into the main README unless that detail is truly necessary for downstream users.

## Typical Change Types

Most contributor work falls into one of these categories:

- updating the bundled WordPress core baseline
- changing the curated plugin baseline
- improving the updater automation
- improving contributor or user documentation
- preparing and publishing released tags

Each change should preserve the clarity of the base as a reusable upstream.

## Local Verification

Before shipping changes to the updater, run:

```bash
php tools/wporg-updater/tests/run.php
```

At minimum, contributors should also syntax-check touched PHP files with `php -l`.

Convenience targets are available:

```bash
make doctor
make verify
make sync-dry-run
```

`make sync-dry-run` still expects a GitHub-style environment because sync mode talks to the GitHub API even when mutation is disabled.

If you change the downstream onboarding flow, also verify:

- `php tools/wporg-updater/bin/wporg-updater.php help`
- `php tools/wporg-updater/bin/wporg-updater.php doctor --github`
- `php tools/wporg-updater/bin/wporg-updater.php scaffold-downstream --repo-root=/tmp/example --force`

## Release Discipline

Treat tags and GitHub Releases as the contract with downstream users.

Recommended discipline:

- tag every merged WordPress core baseline update that should be consumable downstream
- tag curated plugin baseline changes only when they are intentionally part of the reusable base
- use tags that reflect the bundled core version, for example `v6.9.4.0`
- publish release notes that explain what downstream users are adopting

Use [release-process.md](release-process.md) as the actual maintainer checklist when cutting a release.

Downstream users should not need to inspect raw commits to understand whether a release matters to them.

## Automation Responsibilities

If you change the updater behavior, also review:

- PR body usefulness
- label consistency
- draft and blocker behavior
- safety around replacing WordPress core or plugin files
- whether the change belongs in user-facing docs, contributor docs, or the technical overview

Technical internals live in [automation-overview.md](automation-overview.md).

If you change the scaffolded downstream files, keep these in sync:

- `docs/examples/`
- `tools/wporg-updater/templates/`
- the guidance in `docs/getting-started.md`

## GitHub Repository Setup

The automation expects:

- GitHub Actions enabled
- a usable `GITHUB_TOKEN`
- branch protection configured to require the `WordPress.org Update PR Blocker` check if queued PR blocking is desired

These are maintainer concerns. They do not belong in the main README for dependency users.
