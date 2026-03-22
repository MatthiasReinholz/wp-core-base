# Migration Guide

This document is for downstream users moving from older setups into the current manifest-driven framework.

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
