# Migration Guide

This document is for downstream users moving from older setups into the current manifest-driven framework.

## Upgrading An Existing Framework Installation

Read the installed version and distribution path in `.wp-core-base/framework.php`, then review the release notes for every version you are crossing. Framework updates replace the vendored tooling and refresh unmodified framework-managed files. They do not upgrade your site's WordPress core, plugins, database or project-owned runtime code. Baseline versions recorded by the framework describe the optional starter repository; your runtime manifest remains authoritative.

Start from a clean, committed downstream checkout. For the standard vendor path, preflight with the currently installed client:

```bash
php vendor/wp-core-base/tools/wporg-updater/bin/wporg-updater.php framework-sync --repo-root=. --check-only --fail-on-skipped-managed-files --json
```

Review `refreshed_files`, `removed_files` and `skipped_files` before creating or merging the framework update PR. The strict preflight intentionally fails when customized managed files need attention. Reconcile those changes with your customization; do not overwrite local workflows blindly. Adjust the command path when your distribution lives elsewhere. For a source checkout, use `php tools/wporg-updater/bin/wporg-updater.php` instead.

Verified release and installer tests cover the v1.4.8 installer and the current installer across `full-core` and `content-only`, on GitHub and GitLab. That is a tested compatibility boundary, not a guarantee for every historical release or customized installation. Older clients must first have the correct independently trusted signing key and support the signed release format. Review their intervening release notes and rehearse in a disposable clone; do not bypass signature checks to make an old client accept a release.

When crossing these versions, include the corresponding migration actions:

| Starting point | Required review or action |
| --- | --- |
| v1.4.8 installer or a manual ZIP extraction that loses file modes | After installation, run `chmod +x vendor/wp-core-base/bin/wp-core-base` and commit the executable-bit change before using the direct launcher. The PHP-prefixed maintenance command remains usable. Newer installers restore this mode automatically. |
| Before v1.5.0 | Fix duplicate dependency identities and unknown security/source configuration keys. Keep provider-specific configuration in `extensions`. Pass boolean flags without values and remove options belonging to other commands. Review the tooling-only artifact change and workflow quoting changes in [v1.5.0](releases/1.5.0.md). |
| Before v1.6.1, using GitHub automation | Review the job-level cleanup/sync concurrency change in [v1.6.1](releases/1.6.1.md), including customized reconciliation workflows skipped by the installer. Upgrading does not replay cancelled historical cleanup jobs; use the [individual recovery procedure](operations.md#blocked-prs) where needed. |
| Before v1.6.4, with existing automation PRs | Review PRs whose recorded branch differs from the actual head or whose final metadata is malformed. Correct the metadata or recreate the PR through the updater; the new guard intentionally refuses a conflicting branch. See [v1.6.4](releases/1.6.4.md). |
| Custom premium providers | Rehearse catalog and release resolution with the installed adapter and its real API format. Return a non-empty version and an explicit timezone-bearing timestamp; malformed dates still fail validation. See the [provider contract](adding-premium-provider.md#method-contracts). |

After the update and any required manual reconciliation, run:

```bash
php vendor/wp-core-base/tools/wporg-updater/bin/wporg-updater.php doctor --repo-root=. --automation --json
php vendor/wp-core-base/tools/wporg-updater/bin/wporg-updater.php stage-runtime --repo-root=. --output=.wp-core-base/build/runtime --json
```

Use `doctor --json` without `--automation` for a repository that does not configure PR automation. Review the update diff, require your normal downstream checks, and merge before deploying. For `content-only` or external core, test the separately supplied core against the selected plugins; filesystem checks cannot verify that external layer. A real core/plugin/database migration requires its own backup, deployment rehearsal and rollback plan, as described in [baseline upgrades](baseline-upgrades.md).

## From `.github/wporg-updates.php`

The old plugin config file is no longer the primary configuration surface.

Move to:

- `.wp-core-base/manifest.php`

Migration order:

1. choose `full-core` or `content-only`
2. define your roots in `paths`
3. define whether core is `managed` or `external`
4. convert each old managed plugin entry into a dependency entry
5. classify repo-owned runtime code as `local`
6. run `doctor`
7. run `stage-runtime`

## From A Standard WordPress-Root Repo

If your repo already contains `wp-admin`, `wp-includes`, and `wp-content`, choose `full-core`.

Suggested order:

1. create `.wp-core-base/manifest.php`
2. declare every managed or local runtime dependency
3. keep `core.mode` as `managed`
4. run `doctor`
5. enable the update workflows

## From A Content-Only Or Image-First Repo

If your repo contains only a content tree such as `cms/`, choose `content-only`.

Suggested order:

1. scaffold a `content-only` manifest
2. set `core.mode` to `external`
3. declare managed third-party dependencies explicitly
4. declare repo-owned plugins, themes, MU packages, and MU plugin files as `local`
5. stage runtime output and point your image build at that staged directory

## From Mixed Source Trees

If your runtime currently mixes:

- repo-owned custom code
- WordPress.org snapshots
- GitHub-sourced private plugins
- symlinks or submodules

then normalize it in this order:

1. replace shipped symlinks with real runtime code
2. move release-backed third-party code into `managed`
3. move project-owned code into `local`
4. mark anything intentionally out of scope as `ignored`
5. validate with `doctor` and `stage-runtime`

If the repo has too much undeclared runtime code to switch directly to strict ownership, start with:

- `runtime.manifest_mode: relaxed`
- `php tools/wporg-updater/bin/wporg-updater.php suggest-manifest`

Then move paths into explicit `managed`, `local`, or `ignored` entries until you can switch back to `strict`.

If local source trees contain deployment-irrelevant files that you still want to keep in Git, consider:

- `runtime.validation_mode: staged-clean`
- `runtime.strip_paths`
- `runtime.strip_files`
- dependency-level `policy.strip_paths` and `policy.strip_files`

If managed release archives contain predictable non-runtime extras, normalize them during ingestion instead of keeping the raw archive tree in Git:

- `runtime.managed_sanitize_paths`
- `runtime.managed_sanitize_files`
- dependency-level `policy.sanitize_paths`
- dependency-level `policy.sanitize_files`

That keeps the committed managed snapshot aligned with what `stage-runtime` will actually ship.

## What To Avoid

- relying on folder discovery instead of manifest entries
- keeping local patches inside managed dependency trees
- assuming MU plugins must be updater-managed
- shipping runtime artifacts directly from the raw working tree when `stage-runtime` is part of your contract
