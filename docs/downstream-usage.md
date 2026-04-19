# Downstream Usage

This guide is for downstream users who already understand the basics and need the operational model in more detail.

If you need the terminology first, read [concepts.md](concepts.md). If you are evaluating fit rather than implementing, read [evaluation-guide.md](evaluation-guide.md).
If you want task-based authoring commands, read [managing-dependencies.md](managing-dependencies.md).

## The Manifest Is The Contract

The downstream source of truth is `.wp-core-base/manifest.php`.

The manifest defines:

- repository profile
- content roots
- whether WordPress core is managed or external
- runtime staging rules
- kind-level sync, staging, and validation scope
- every dependency the updater is allowed to touch

The updater does not infer managed dependencies by scanning folders.

For day-to-day work, the recommended interface is still command-driven:

- `add-dependency`
- `remove-dependency`
- `list-dependencies`

Use manual manifest editing for advanced policy changes, not for every normal plugin or MU plugin addition.

Framework version pinning is separate from runtime ownership. Downstreams should also keep `.wp-core-base/framework.php` so the installed `wp-core-base` version, vendored path, and framework-managed workflow checksums are explicit.

## Framework Version Pinning

Use `.wp-core-base/framework.php` for:

- the pinned installed framework version
- the source repository for framework releases
- the vendored install path, usually `vendor/wp-core-base`
- checksums for framework-managed scaffold files

That file is what `framework-sync` updates when a newer `wp-core-base` release is installed into the downstream repo.

If the downstream repo already ignores `/vendor/`, keep the exception narrow so only the framework snapshot becomes repo-owned:

```gitignore
/vendor/*
!/vendor/wp-core-base
!/vendor/wp-core-base/**
```

That is preferred over unignoring the whole `vendor/` tree. It keeps `framework-sync` reviewable in Git while avoiding accidental commits of unrelated Composer-installed packages.

## Framework Self-Update

`framework-sync` is the framework-level equivalent of dependency sync.

It:

- checks the authoritative `wp-core-base` release source declared in `.wp-core-base/framework.php` for a newer framework version
- updates the vendored framework snapshot
- refreshes framework-managed workflows when they still match the last managed version
- leaves locally customized workflow files untouched and reports that drift in the PR

The scaffolded downstream setup includes a weekly `wp-core-base` self-update workflow.

The framework release source is singular. If upstream ever moves the official framework release source to another Git platform, downstreams will follow the source recorded in their installed `.wp-core-base/framework.php` only after they adopt the migration release that updates that metadata. The framework does not maintain parallel legacy-source discovery.

## Dependency Classes

Each dependency should fall into one of these classes:

- `managed-upstream`
- `managed-private`
- `local-owned`
- `ignored`

These map to manifest values like this:

- `management: managed` + `source: wordpress.org` => `managed-upstream`
- `management: managed` + `source: github-release` => `managed-private`
- `management: managed` + `source: premium` => `managed-premium`
- `management: local` + `source: local` => `local-owned`
- `management: ignored` + `source: local` => `ignored`

## Source Types

Today the framework supports automated updates from:

- `WordPress.org`
- `github-release`
- `gitlab-release`
- `premium`

Hosted release support is release-backed. The upstream project must publish stable GitHub or GitLab Releases. Raw tags without Releases are not treated as the source of truth.

For private GitHub dependencies, the manifest should point to an environment variable through `source_config.github_token_env`.
For private GitLab dependencies, the manifest should point to an environment or CI/CD variable through `source_config.gitlab_token_env`.

Hosted release-backed dependencies may also opt into two download-time trust controls:

- release cooldowns through `security.managed_release_min_age_hours` or `source_config.min_release_age_hours`
- checksum-sidecar verification through `security.github_release_verification`, `source_config.verification_mode`, and `source_config.checksum_asset_pattern`

Use those only after checking the real upstream release assets. The framework binds the checksum sidecar to the ZIP filename, so the checksum file must contain a line for the exact asset being downloaded.

For AI coding agents working in a downstream repo, the safe sequence is:

1. inspect the hosted Release assets
2. confirm the ZIP asset glob
3. confirm the checksum sidecar asset glob
4. update the manifest
5. run `doctor --repo-root=.`
6. run `sync`

If upstream does not publish a checksum sidecar, keep verification optional or disabled for that dependency.

Premium workflow sources use one fixed env var or GitHub secret:

- `WP_CORE_BASE_PREMIUM_CREDENTIALS_JSON`

Current premium position:

- Generic premium path: `source: premium` with `source_config.provider`
- The provider key must be registered in `.wp-core-base/premium-providers.php`
- The concrete adapter class lives in the downstream repo, not in framework core
- `wp-core-base` itself does not ship vendor-specific premium adapters

## Managed Versus Local

Use `managed` when:

- the framework may replace the directory from an upstream archive
- you want update PRs for that dependency
- you can accept that local changes in that directory are unsafe

Use `local` when:

- the dependency is part of the runtime
- the code is owned by your project
- the framework must never overwrite it

`local` is a normal downstream ownership model. It is expected that many real projects will keep substantial custom runtime code in `local` entries.

This framework is tooling, not a repo-ownership layer. Manual additions are normal as long as they are modeled clearly in the manifest.

Use `ignored` when:

- the path should be documented in the manifest
- but it should stay out of update automation and runtime staging

## File-Level Runtime Entries

Use these kinds when a whole directory is not the right shape:

- `mu-plugin-file`
- `runtime-file`
- `runtime-directory`

This is especially useful for single-file MU plugins in `mu-plugins/`.

## Strict Versus Relaxed Ownership

`runtime.manifest_mode` controls how the framework treats undeclared runtime paths under the configured plugin, theme, and MU plugin roots.

- `strict`: undeclared paths are errors and are not staged
- `relaxed`: undeclared clean paths are reported and may still be staged as a migration aid

Use `strict` as the steady-state default. Use `relaxed` only while migrating mixed-source repositories toward explicit manifest ownership.

## Source-Clean Versus Staged-Clean

`runtime.validation_mode` controls where cleanliness is enforced.

- `source-clean`: repo paths themselves must already be deployable
- `staged-clean`: local paths may keep strip-on-stage files, but staged output must be clean

Use `staged-clean` when local-owned code legitimately contains source-adjacent files like `README.md`, tests, or build metadata.

## Strip-On-Stage

Use strip-on-stage when local source trees are intentionally richer than the runtime payload.

Available knobs:

- `runtime.strip_paths`
- `runtime.strip_files`
- `dependencies[].policy.strip_paths`
- `dependencies[].policy.strip_files`

Managed dependencies should still arrive runtime-ready. Strip-on-stage is primarily for `local` code.

## Managed Sanitation

Managed dependencies have a separate normalization path.

Available knobs:

- `runtime.managed_sanitize_paths`
- `runtime.managed_sanitize_files`
- `dependencies[].policy.sanitize_paths`
- `dependencies[].policy.sanitize_files`

Use these when a release-backed managed dependency is legitimate runtime code but still includes predictable non-runtime files such as `README*`, `composer.json`, `package.json`, or test directories.

The managed checksum stored in the manifest represents the sanitized runtime snapshot. That means `doctor`, `sync`, and `stage-runtime` all verify the same normalized tree.

## Ownership Roots

`runtime.ownership_roots` defines where undeclared runtime-path inspection runs.

Defaults cover:

- plugins
- themes
- MU plugins

You can extend them with content-root paths like `cms/languages` or `cms/shared-assets`.

## Kind-Level Controls

The framework separates three scopes:

- `automation.managed_kinds`
- `runtime.staged_kinds`
- `runtime.validated_kinds`

That lets a downstream project do things like:

- manage plugins automatically
- stage MU plugin files
- validate local themes
- keep custom runtime files out of updater automation

That separation is the core model of the framework: it manages selected dependencies, but it does not try to own all runtime code in the repository.

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

## Admin Governance

Scaffolded downstreams also get a framework-managed MU plugin plus a generated data file under the configured `mu_plugins_root`.

That governance layer projects manifest ownership into runtime so wp-admin can distinguish:

- workflow-managed plugins
- local project-owned plugins
- ignored entries

For workflow-managed plugins, the admin UI becomes intentionally less misleading:

- WordPress update offers for those plugins are suppressed
- manual `Update now` style actions are removed when present
- auto-update controls are replaced with explanatory text
- the plugin row shows a `Managed by wp-core-base workflows` label

If the downstream site already suppresses update UI globally, the governance component stays passive and informational. It does not try to re-enable native WordPress update behavior.

## Helper Commands

Useful migration helpers:

```bash
php tools/wporg-updater/bin/wporg-updater.php suggest-manifest
php tools/wporg-updater/bin/wporg-updater.php format-manifest
```

## Daily Commands

Most downstream teams only need these commands:

```bash
php tools/wporg-updater/bin/wporg-updater.php doctor
php tools/wporg-updater/bin/wporg-updater.php doctor --automation
php tools/wporg-updater/bin/wporg-updater.php stage-runtime --output=.wp-core-base/build/runtime
php tools/wporg-updater/tests/run.php
```

If `wp-core-base` is vendored, run the same commands from the vendored path and pass `--repo-root=.`

Framework version checks use:

```bash
php vendor/wp-core-base/tools/wporg-updater/bin/wporg-updater.php framework-sync --repo-root=. --check-only
```

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
  AGENTS.md
  .wp-core-base/framework.php
  .wp-core-base/manifest.php
  .wp-core-base/premium-providers.php
  .wp-core-base/USAGE.md
  .github/workflows/
  vendor/wp-core-base/
```

## Examples

- example downstream manifest (GitHub-first): [examples/downstream-manifest.php](examples/downstream-manifest.php)
- example downstream manifest (GitLab-first): [examples/downstream-manifest-gitlab.php](examples/downstream-manifest-gitlab.php)
- example scheduled/manual updates workflow: [examples/downstream-workflow.yml](examples/downstream-workflow.yml)
- example merged-PR reconciliation workflow: [examples/downstream-updates-reconcile-workflow.yml](examples/downstream-updates-reconcile-workflow.yml)
- example framework self-update workflow: [examples/downstream-framework-self-update-workflow.yml](examples/downstream-framework-self-update-workflow.yml)
- example blocker workflow: [examples/downstream-pr-blocker-workflow.yml](examples/downstream-pr-blocker-workflow.yml)
- example validation workflow: [examples/downstream-validate-runtime-workflow.yml](examples/downstream-validate-runtime-workflow.yml)

## What To Read Next

- step-by-step onboarding: [getting-started.md](getting-started.md)
- schema details: [manifest-reference.md](manifest-reference.md)
- migration planning: [migration-guide.md](migration-guide.md)
