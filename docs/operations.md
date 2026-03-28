# Ongoing Operations

This document is for downstream users working with `wp-core-base` on an ongoing basis.

If you are evaluating whether the framework fits your repo, start with [evaluation-guide.md](evaluation-guide.md). If you are new to the terminology, read [concepts.md](concepts.md).

## Core Commands

```bash
php tools/wporg-updater/bin/wporg-updater.php help
php tools/wporg-updater/bin/wporg-updater.php doctor
php tools/wporg-updater/bin/wporg-updater.php doctor --github
php tools/wporg-updater/bin/wporg-updater.php stage-runtime --output=.wp-core-base/build/runtime
php tools/wporg-updater/bin/wporg-updater.php framework-sync --check-only
php tools/wporg-updater/tests/run.php
```

If `wp-core-base` is vendored, run these commands from the vendored path and pass `--repo-root=.`

## Recommended Operating Routine

1. keep `.wp-core-base/manifest.php` accurate
2. run `doctor` after manifest changes
3. run `stage-runtime` before changing your build or deployment contract
4. review update PRs like normal code changes
5. merge approved PRs
6. deploy through your existing process

## Reviewing Update PRs

For dependency update PRs, pay attention to:

- release scope
- release notes
- support topics listed after the release timestamp for WordPress.org plugins
- whether the dependency is actually safe to overwrite

For WordPress core PRs, pay attention to:

- release notes
- patch versus minor versus major scope
- any site-specific compatibility concerns

For framework update PRs, pay attention to:

- current and target `wp-core-base` version
- the bundled WordPress baseline before and after the update
- release-note sections from `wp-core-base` itself
- any scaffolded workflow files that were intentionally skipped because they were locally customized

Framework update PRs are separate from dependency and core PRs. They update the vendored framework snapshot and `.wp-core-base/framework.php`, not your runtime dependency manifest.

## Blocked PRs

Blocked PRs are intentional.

The framework updates patch releases in place on the same line, but opens separate blocked PRs for later minor or major releases if an older PR is still unresolved.

When one automation PR merges, the reconciliation workflow also refreshes the remaining open dependency PRs onto the new base branch state. That keeps manifest checksums and other baseline metadata current across sibling PRs.

The dependency updater also keeps one live PR per dependency/version pair. If duplicate PRs exist for the same target version, the updater keeps one canonical PR and closes the others as superseded. If a previously open PR is already satisfied on the base branch, it is closed automatically as stale/no-op.

WordPress core and framework self-update PRs follow the same rule: one live PR per target version, with stale/no-op PRs closed during reconciliation instead of left open.

That queueing behavior depends on the blocker workflow.

## Runtime Validation In CI

The intended CI contract is:

1. run `doctor --github`
2. run `stage-runtime`
3. build or deploy from the staged runtime payload

If your project is image-first, treat the staged runtime directory as the build input.

## Common Failure Modes

### `doctor` reports checksum drift

That means a managed dependency tree does not match the manifest checksum. Fix the tree or regenerate the managed dependency snapshot intentionally.

### `doctor` reports forbidden runtime files

That means a staged dependency contains files or directories outside your runtime hygiene policy. Remove or reclassify them intentionally.

### Sync fails for a managed dependency

Check:

- the manifest entry
- the main file path
- the source type
- GitHub release configuration if the source is `github-release`

### Framework self-update skips a workflow file

That means a scaffolded workflow no longer matches the last framework-managed checksum in `.wp-core-base/framework.php`.

The framework leaves that file untouched on purpose. Review the new version in the vendored snapshot and update the downstream workflow manually if you want to pick up the upstream changes.

### GitHub env missing

That is expected outside GitHub unless you are explicitly validating the GitHub workflow contract locally.
