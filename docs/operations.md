# Ongoing Operations

This document is for downstream users working with `wp-core-base` on an ongoing basis.

## Core Commands

```bash
php tools/wporg-updater/bin/wporg-updater.php help
php tools/wporg-updater/bin/wporg-updater.php doctor
php tools/wporg-updater/bin/wporg-updater.php doctor --github
php tools/wporg-updater/bin/wporg-updater.php stage-runtime --output=.wp-core-base/build/runtime
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
- support topics listed after the release timestamp for wordpress.org plugins
- whether the dependency is actually safe to overwrite

For WordPress core PRs, pay attention to:

- release notes
- patch versus minor versus major scope
- any site-specific compatibility concerns

## Blocked PRs

Blocked PRs are intentional.

The framework updates patch releases in place on the same line, but opens separate blocked PRs for later minor or major releases if an older PR is still unresolved.

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

### GitHub env missing

That is expected outside GitHub unless you are explicitly validating the GitHub workflow contract locally.
