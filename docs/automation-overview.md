# Automation Overview

This document is for contributors and advanced users who need the implementation details behind `wp-core-base`.

If you only want to use the repository as a dependency, start with [../README.md](../README.md), [getting-started.md](getting-started.md), and [downstream-usage.md](downstream-usage.md).

## What The Repository Does

`wp-core-base` combines three things:

- a WordPress core mirror that can be versioned and released
- a curated baseline of selected wordpress.org plugins
- automation that raises GitHub pull requests when WordPress core or managed plugins have upstream updates

## Repository Model

The repository is intentionally organized so that reusable upstream concerns stay separate from downstream application concerns.

The important paths are:

- `.github/workflows/wporg-updates.yml`
- `.github/workflows/wporg-update-pr-blocker.yml`
- `.github/wporg-updates.php`
- `tools/wporg-updater/bin/wporg-updater.php`
- `tools/wporg-updater/src/`
- `tools/wporg-updater/templates/`
- `docs/examples/`
- `tools/wporg-updater/tests/run.php`

WordPress core remains at the repository root. The automation is layered around that baseline instead of being mixed into a site-specific application structure.

## Configuration

Runtime configuration lives in `.github/wporg-updates.php`.

The configuration currently covers:

- base branch selection
- global support-forum crawl limits
- GitHub API base URL
- dry-run behavior
- WordPress core update handling
- managed plugin allowlist entries

Each managed plugin entry provides:

- wordpress.org slug
- repository path
- main plugin file
- enabled state
- optional plugin-specific support-forum crawl limit
- optional extra labels

Managed plugins are explicit. The updater does not try to guess which folders in `wp-content/plugins` should be treated as managed wordpress.org dependencies.

## Downstream Scaffolding

The CLI also includes a `scaffold-downstream` mode.

That mode writes downstream-owned starter files for:

- `.github/wporg-updates.php`
- `.github/workflows/wporg-updates.yml`
- `.github/workflows/wporg-update-pr-blocker.yml`

The rendered files come from `tools/wporg-updater/templates/` and `docs/examples/`. If you change that downstream bootstrap path, keep those sources aligned.

## Pull Request Behavior

For plugin updates, the updater:

- reads the installed plugin version from the repository
- queries wordpress.org for the latest version and metadata
- collects the relevant changelog section
- collects support topics opened after the release timestamp
- classifies the release and applies labels
- opens or refreshes a GitHub pull request

For WordPress core updates, the updater:

- reads the installed core version
- queries the WordPress core release API
- collects release metadata and release-note content
- classifies the release and applies labels
- opens or refreshes a GitHub pull request

## PR Refresh Rules

The automation distinguishes between three cases:

- same target version, but new support-forum data: refresh the body and labels only
- newer patch release on the same line: update the existing PR in place
- newer minor or major release while an older PR is still open: open a new blocked PR

Later PRs are logically blocked, not stacked through git history. The blocker workflow keeps later PRs in draft until earlier ones are resolved.

## Labels

The automation maintains these shared labels:

- `automation:plugin-update`
- `component:wordpress-core`
- `source:wordpress.org`
- `release:patch`
- `release:minor`
- `release:major`
- `type:security-bugfix`
- `type:feature`
- `support:new-topics`
- `support:regression-signal`
- `status:blocked`

Plugins can also define extra labels such as `plugin:woocommerce` or `plugin:jetpack`.

## Support-Forum Scanning

Support-topic collection is intentionally cautious:

- it prefers the wordpress.org support feed when that feed fully covers the relevant time window
- it falls back to support-forum crawling when the feed is not sufficient
- it keeps crawl limits bounded so that high-volume support forums do not silently produce partial results
- it refreshes existing PRs incrementally so the system does not have to recrawl the full post-release history on every run

If a plugin forum exceeds the configured crawl limit, the run fails intentionally rather than pretending the results are complete.

## Safety Model

The automation assumes this repository remains a clean base repository.

Important consequences:

- WordPress core replacement is only safe when the repository stays close to an upstream core mirror
- managed plugin updates replace the full plugin directory from the official wordpress.org package
- local patches inside managed plugin directories are therefore unsafe unless you intentionally accept that tradeoff

## Verification

Local parser and classifier checks are available through:

```bash
php tools/wporg-updater/tests/run.php
```

Contributors should update documentation and tests together when changing updater behavior.
