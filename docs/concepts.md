# Concepts

This document is for readers who want the mental model before they read the detailed docs.

If you want step-by-step setup, start with [getting-started.md](/Users/matthias/DEV/wp-core-base/docs/getting-started.md).

## What `wp-core-base` Is

`wp-core-base` is a WordPress foundation and update framework.

It helps a WordPress project:

- keep dependency changes in Git
- review dependency updates through pull requests
- separate framework-managed code from project-owned code
- build a clean runtime payload for deployment

## Downstream

A downstream is a WordPress project that uses `wp-core-base`.

The framework repository itself is the upstream. Your actual WordPress project is the downstream.

## Profiles

There are two supported repository profiles.

### `full-core`

The downstream repository contains WordPress core itself.

Typical shape:

- `wp-admin/`
- `wp-includes/`
- `wp-content/`

### `content-only`

The downstream repository contains only the content layer and treats WordPress core as external.

Typical shape:

- `cms/`
- `wp-content/`
- or another custom content root

This is common in image-first and platform-driven setups.

## Manifest

The manifest lives at `.wp-core-base/manifest.php`.

It is the source of truth for:

- architecture profile
- content paths
- core mode
- runtime rules
- dependency ownership
- update eligibility

The framework does not infer managed dependencies from folder presence alone.

## Dependency Ownership

Every dependency should be one of three ownership modes.

### `managed`

The framework may overwrite this dependency from an upstream archive.

Use this when:

- you want update PRs
- local patches in that path are not part of the contract

### `local`

The downstream project owns this code directly.

Use this when:

- the project writes or maintains the code itself
- the framework must never replace it automatically

`local` is a first-class steady-state model, not a temporary migration status.

### `ignored`

The path is intentionally outside framework staging and update behavior.

Use this when:

- you want it documented
- but you do not want the framework to manage or stage it

## Source Types

A managed dependency also has a source type.

Supported automated source types today:

- `WordPress.org`
- `github-release`
- `gitlab-release`
- `generic-json`

Not every possible WordPress dependency source is supported by automation.

## Managed Versus Local

The single most important concept is this:

`wp-core-base` manages selected dependencies. It does not try to own all runtime code in the project.

That means:

- third-party plugins can be `managed`
- custom plugins can stay `local`
- custom themes can stay `local`
- MU plugins can stay `local`
- runtime files and runtime directories can stay `local`

## Runtime Staging

`stage-runtime` assembles a clean runtime payload for deployment.

Use it when:

- building Docker images
- producing immutable release artifacts
- enforcing a clean deployable runtime tree

This is different from the raw repository tree. The repository may contain metadata or source-adjacent files that do not belong in the shipped runtime.

## Validation Modes

### `source-clean`

The repo tree itself must already be deployable.

### `staged-clean`

The source tree may contain allowed extras, but the staged runtime output must be clean.

This is mainly relevant for `local` project-owned code.

## Managed Sanitation Versus Local Strip-On-Stage

These are related but different.

### Managed sanitation

Used for `managed` dependencies.

It normalizes accepted upstream archives during `sync` before they are committed into the repo.

### Local strip-on-stage

Used for `local` code.

It allows the downstream repo to keep richer source trees while stripping non-runtime files during runtime staging.

Do not treat these as the same mechanism.

## Manifest Modes

### `strict`

Undeclared runtime paths under the ownership roots are errors and are not staged.

### `relaxed`

Undeclared clean runtime paths are reported and may be staged as a migration aid.

`relaxed` is for adoption and migration, not the ideal long-term state.

## Ownership Roots

Ownership roots tell the framework where it should look for undeclared runtime paths.

Defaults usually include:

- plugins root
- themes root
- MU plugins root

You can extend them with extra runtime-bearing directories under the content root.

## PR Blocker Behavior

The framework uses one PR per dependency.

If a dependency already has an open PR:

- newer patch release on the same line: update the existing PR
- newer minor or major release: open another PR and block it behind the older one

That keeps PRs focused while avoiding one moving target.

## Supported Deployment Styles

The framework can work with:

- GitHub + CI/CD
- GitLab + CI/CD
- GitHub + FTP or SFTP deployment
- GitLab + FTP or SFTP deployment
- GitHub + manual deployment
- GitLab + manual deployment
- local/manual usage without hosted automation

GitHub or GitLab is required only for the automated PR workflows.
