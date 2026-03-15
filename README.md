# wp-core-base

`wp-core-base` is a clean WordPress core mirror with automation for keeping both WordPress core and selected wordpress.org plugins current through GitHub pull requests.

It is designed to serve two roles:

- a maintained base repository that tracks official WordPress releases
- a reusable foundation that downstream WordPress projects can consume through a clear versioning strategy

The bundled WordPress core in this working copy is `6.9.4`, extracted from the official `latest.tar.gz` archive on March 14, 2026.

The baseline plugin bundle currently includes:

- Akismet from WordPress core
- WooCommerce
- Jetpack

## What It Does

- checks official WordPress core releases on a schedule
- checks configured wordpress.org plugins on a schedule
- opens rich PRs with exact release timestamps
- updates existing PRs when new patch releases land on the same line
- opens separate blocked PRs for later minor and major releases
- labels releases as patch, minor, major, security or bugfix, feature, and support-signal related
- lists wordpress.org support topics opened after a plugin release timestamp
- keeps later queued PRs blocked until earlier queued PRs are merged

## Repository Model

This repository should stay a clean core mirror plus automation files.

That means:

- WordPress core files live at the repository root
- repository-specific automation lives in `.github/` and `tools/`
- custom application code should not be added directly here unless it is part of the reusable base

That separation keeps WordPress core replacement safe and makes versioned releases meaningful for downstream projects.

For extra safety, the core updater only replaces the core-owned top-level files and the bundled entries shipped inside `wp-content/plugins` and `wp-content/themes`. It does not intentionally sweep unrelated custom content.

## Layout

- [`.github/workflows/wporg-updates.yml`](/Users/matthias/DEV/wp-core-base/.github/workflows/wporg-updates.yml): scheduled reconciliation workflow
- [`.github/workflows/wporg-update-pr-blocker.yml`](/Users/matthias/DEV/wp-core-base/.github/workflows/wporg-update-pr-blocker.yml): required status check for queued PR blocking
- [`.github/wporg-updates.php`](/Users/matthias/DEV/wp-core-base/.github/wporg-updates.php): runtime config for core and plugin updates
- [`tools/wporg-updater/bin/wporg-updater.php`](/Users/matthias/DEV/wp-core-base/tools/wporg-updater/bin/wporg-updater.php): updater entry point
- [`tools/wporg-updater/src`](/Users/matthias/DEV/wp-core-base/tools/wporg-updater/src): PHP modules for WordPress.org, GitHub, reconciliation, and rendering
- [`tools/wporg-updater/tests/run.php`](/Users/matthias/DEV/wp-core-base/tools/wporg-updater/tests/run.php): parser and classifier checks

## Configuration

Configuration lives in [`.github/wporg-updates.php`](/Users/matthias/DEV/wp-core-base/.github/wporg-updates.php).

Core updates are enabled by default:

```php
'core' => [
    'enabled' => true,
],
```

Managed plugins are explicit allowlist entries:

```php
[
    'slug' => 'woocommerce',
    'path' => 'wp-content/plugins/woocommerce',
    'main_file' => 'woocommerce.php',
    'enabled' => true,
    'support_max_pages' => 60,
    'extra_labels' => ['plugin:woocommerce'],
]
```

Each plugin entry needs:

- `slug`
- `path`
- `main_file`
- `enabled`
- optional `support_max_pages`
- optional `extra_labels`

`support_max_pages` lets high-volume plugins such as WooCommerce and Jetpack raise the crawl ceiling without forcing that higher limit onto every smaller plugin.

## PR Content

Plugin PRs include:

- installed version
- target version
- release timestamp
- relevant changelog section
- all support topics opened after the release timestamp
- derived labels

Core PRs include:

- installed core version
- target core version
- release timestamp
- release announcement link
- download link
- summarized official release notes from the WordPress releases feed
- derived labels

## Labels

The automation ensures these labels exist:

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

Rules:

- patch releases always get `type:security-bugfix`
- minor and major releases get `type:security-bugfix` when the changelog or release notes suggest fixes or security work
- minor and major releases get `type:feature` when the changelog or release notes suggest feature work
- plugin support labels are derived from topic count and topic-title keywords after the release timestamp

## PR Queueing

- same target version, new support data: update the PR body and labels only
- newer patch on the same line: update the existing PR branch, title, body, and labels
- newer minor or major: open a new self-contained PR from the default branch
- later PRs remain blocked until earlier queued PRs are merged

The blocking is logical, not stacked Git history. Later PRs are still based on the default branch, but the required blocker check keeps them in draft until their predecessors have merged.

## Using This As A Dependency

Detailed guidance lives in [docs/downstream-usage.md](/Users/matthias/DEV/wp-core-base/docs/downstream-usage.md), with a CI example at [docs/examples/downstream-workflow.yml](/Users/matthias/DEV/wp-core-base/docs/examples/downstream-workflow.yml).

There are three realistic consumption models for downstream WordPress projects:

1. Template repository
   Best for bootstrapping a new site repo once.
   Weakness: downstream repos do not inherit future changes automatically.

2. Git subtree
   Best when downstream repos want to vendor this base directly into their own history while still pulling future tagged updates.
   Strength: easy downstream customization, no nested repository UX.
   Weakness: update flow needs explicit subtree pulls.

3. Git submodule pinned to release tags
   Best when downstream repos want this base to stay clearly versioned and externally managed.
   Strength: strong separation and explicit version pinning.
   Weakness: more operational overhead for teams unfamiliar with submodules.

For most teams, the cleanest practical model is:

- keep `wp-core-base` as the canonical released base
- tag and release it whenever core or curated base changes land
- consume it downstream either as a Git subtree or as a submodule pinned to tags
- layer project-specific code on top in the downstream repository

## Release Strategy

Recommended release discipline for this repo:

- tag every merged core release update
- tag curated plugin baseline changes only when they are intended to be part of the reusable base
- use semantic Git tags that track the included WordPress core version, for example `v6.9.4.0`
- publish GitHub Releases with notes that summarize:
  - included core version
  - included managed plugin versions
  - notable security or bugfix labels
  - downstream upgrade guidance

## Local Verification

Run the parser and classifier checks:

```bash
php tools/wporg-updater/tests/run.php
```

The full updater expects a GitHub Actions environment with:

- a real Git repository
- `GITHUB_TOKEN`
- branch protection configured to require `WordPress.org Update PR Blocker`

## Important Limitations

- plugin automation is only safe for wordpress.org plugins mirrored from upstream
- plugin support scanning can still fail intentionally if the support backlog exceeds the configured scan depth for that plugin
- core replacement assumes this repository stays a clean core mirror plus automation files
- this repository is not intended to be a fully customized site repo
