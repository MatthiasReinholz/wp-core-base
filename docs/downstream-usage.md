# Downstream Usage

This guide is for users who already understand the basic onboarding paths and want the more advanced dependency models.

If you are just getting started, use [getting-started.md](getting-started.md) first.

## Two Valid Adoption Patterns

There are two different ways to use `wp-core-base` downstream, and it is important not to mix them up.

### 1. Base-Code Dependency

In this model, `wp-core-base` is part of the actual code baseline of your downstream WordPress project.

Use this when:

- you want an explicit upstream WordPress base
- you want to consume upstream tags and releases intentionally
- you are comfortable structuring your project around an upstream dependency

This is the model to choose when `wp-core-base` is truly part of your project foundation.

### 2. Automation-Only Dependency

In this model, your downstream project keeps its own existing code layout, and `wp-core-base` is used mainly as the source of the updater tooling.

Use this when:

- your repository already has the code structure you want
- you mainly want the update PR automation
- you do not want to reorganize the downstream project around the full base

In that setup, your downstream repository still owns:

- its own application code
- its own `.github/wporg-updates.php` file
- its own workflows

The updater code is simply executed from a vendored copy of `wp-core-base`.

## Advanced Dependency Options

### Git Subtree

Use a subtree when:

- you want the base committed directly into your project history
- local downstream customization is common
- your team prefers a single-repository experience

This is the best general-purpose option for most teams that want an ongoing upstream relationship.

### Git Submodule

Use a submodule when:

- you want the strongest pinning to released upstream tags
- you want the upstream base to remain visibly separate
- your team already understands submodule workflows

This is a stronger separation model, but it is more operationally demanding.

### Template Or Copy-Based Bootstrap

Use this when:

- you want a simple starting point
- you do not need a strong upstream relationship right away

This is fine for bootstrapping, but it is not the best long-term dependency model if you expect to keep pulling upstream improvements.

## Downstream-Owned Files

A downstream repository that uses the automation should treat these files as its own responsibility:

- `.github/wporg-updates.php`
- `.github/workflows/...`
- deployment configuration
- project-specific themes, plugins, and application code

The example files in this repository are a starting point, not something you should treat as hidden framework internals.

Managed plugins can come from:

- `wordpress.org`
- public GitHub repositories that publish stable GitHub Releases

Use:

- [examples/downstream-wporg-updates.php](examples/downstream-wporg-updates.php)
- [examples/downstream-workflow.yml](examples/downstream-workflow.yml)
- [examples/downstream-pr-blocker-workflow.yml](examples/downstream-pr-blocker-workflow.yml)

If you want those files generated for you instead of copied by hand, use `scaffold-downstream`.

Examples:

```bash
php tools/wporg-updater/bin/wporg-updater.php scaffold-downstream --repo-root=.
php vendor/wp-core-base/tools/wporg-updater/bin/wporg-updater.php scaffold-downstream --repo-root=. --tool-path=vendor/wp-core-base
```

For GitHub-sourced plugins, use:

- `source: 'github'`
- `github_repository`
- optionally `github_release_asset_pattern` if you want a release asset instead of the release zipball
- optionally `github_archive_subdir` if the plugin code lives below the repository root

## Example Layouts

### Full Base Dependency

In this model, the downstream repository itself usually contains the WordPress root:

```text
downstream-project/
  .github/
  wp-admin/
  wp-content/
  wp-includes/
  project-specific-files/
```

The upstream relationship is defined by your Git strategy, usually subtree or submodule, not by a visible `vendor/` directory.

### Automation-Only Dependency

One practical layout:

```text
downstream-project/
  .github/
  wp-admin/
  wp-content/
  wp-includes/
  vendor/
    wp-core-base/
```

Then the downstream workflow runs the updater from the vendored `wp-core-base` path while targeting the downstream repository root through `WPORG_REPO_ROOT`.

Example:

```bash
WPORG_REPO_ROOT="$GITHUB_WORKSPACE" php vendor/wp-core-base/tools/wporg-updater/bin/wporg-updater.php sync
```

## Release Consumption

Downstream projects should consume tags and releases intentionally rather than tracking arbitrary in-progress commits.

The intended tag format is:

- `v6.9.4.0`
- `v6.9.4.1`
- `v6.9.5.0`

The first three segments track the bundled WordPress core version. The final segment tracks base-repository revisions on top of that core baseline.

## Recommendation

Good defaults:

- beginner or mixed-skill team: start simple, then move toward subtree if a long-term upstream link becomes important
- advanced team with strong Git discipline: subtree or submodule
- existing custom WordPress repo that mainly wants update PRs: automation-only dependency

## What To Read Next

- First-time adoption: [getting-started.md](getting-started.md)
- Ongoing use and troubleshooting: [operations.md](operations.md)
- Deployment choices, including FTP-based flows: [deployment-models.md](deployment-models.md)
- Technical internals: [automation-overview.md](automation-overview.md)
