# Downstream Usage

This guide is for downstream users who already understand the basics and need the operational model in more detail.

## The Manifest Is The Contract

The downstream source of truth is `.wp-core-base/manifest.php`.

The manifest defines:

- repository profile
- content roots
- whether WordPress core is managed or external
- runtime staging rules
- every dependency the updater is allowed to touch

The updater does not infer managed dependencies by scanning folders.

## Dependency Classes

Each dependency should fall into one of these classes:

- `managed-upstream`
- `managed-private`
- `local-owned`
- `ignored`

These map to manifest values like this:

- `management: managed` + `source: wordpress.org` => `managed-upstream`
- `management: managed` + `source: github-release` => `managed-private`
- `management: local` + `source: local` => `local-owned`
- `management: ignored` + `source: local` => `ignored`

## Source Types

Today the framework supports automated updates from:

- `wordpress.org`
- `github-release`

GitHub support is release-backed. The repository must publish stable GitHub Releases. Raw tags without Releases are not treated as the source of truth.

For private GitHub dependencies, the manifest should point to an environment variable through `source_config.github_token_env`.

## Managed Versus Local

Use `managed` when:

- the framework may replace the directory from an upstream archive
- you want update PRs for that dependency
- you can accept that local changes in that directory are unsafe

Use `local` when:

- the dependency is part of the runtime
- the code is owned by your project
- the framework must never overwrite it

Use `ignored` when:

- the path should be documented in the manifest
- but it should stay out of update automation and runtime staging

## Runtime Staging

`stage-runtime` assembles a clean runtime payload into a staging directory.

Typical command:

```bash
php tools/wporg-updater/bin/wporg-updater.php stage-runtime --output=.wp-core-base/build/runtime
```

Use the staged directory as the input to:

- Docker `COPY`
- packaging pipelines
- immutable artifact builds

Do not build release artifacts from the raw repository tree when runtime staging is part of your contract.

## Daily Commands

Most downstream teams only need these commands:

```bash
php tools/wporg-updater/bin/wporg-updater.php doctor
php tools/wporg-updater/bin/wporg-updater.php doctor --github
php tools/wporg-updater/bin/wporg-updater.php stage-runtime --output=.wp-core-base/build/runtime
php tools/wporg-updater/tests/run.php
```

If `wp-core-base` is vendored, run the same commands from the vendored path and pass `--repo-root=.`

## Downstream Dependency Strategies

There are two common ways to consume `wp-core-base`.

### As The Base Repository

Use this when `wp-core-base` should define the actual foundation of the downstream repo.

Typical choices:

- start from a release tag
- use Git subtree
- use Git submodule if your team already handles submodules comfortably

### As Tooling Inside Another Repo

Use this when your repo layout already exists and you mainly want the updater, manifest model, and runtime staging.

Typical layout:

```text
project/
  .wp-core-base/manifest.php
  .github/workflows/
  vendor/wp-core-base/
```

## Examples

- example downstream manifest: [examples/downstream-manifest.php](examples/downstream-manifest.php)
- example sync workflow: [examples/downstream-workflow.yml](examples/downstream-workflow.yml)
- example blocker workflow: [examples/downstream-pr-blocker-workflow.yml](examples/downstream-pr-blocker-workflow.yml)
- example validation workflow: [examples/downstream-validate-runtime-workflow.yml](examples/downstream-validate-runtime-workflow.yml)

## What To Read Next

- step-by-step onboarding: [getting-started.md](getting-started.md)
- schema details: [manifest-reference.md](manifest-reference.md)
- migration planning: [migration-guide.md](migration-guide.md)
