# Deployment Models

This document is for downstream users deciding how `wp-core-base` fits into their infrastructure.

## Two Repository Profiles

`wp-core-base` supports two first-class downstream repository profiles:

- `full-core`
- `content-only`

Choose the profile based on what lives in Git, not based on how you deploy.

## Profile: full-core

Use `full-core` when the downstream repository contains WordPress core itself.

Typical structure:

```text
project/
  wp-admin/
  wp-includes/
  wp-content/
  .wp-core-base/manifest.php
```

Good fit for:

- standard WordPress repos
- teams that want WordPress core versioned in Git
- projects where deployment uses the repository contents directly

## Profile: content-only

Use `content-only` when the downstream repository contains only the content layer and treats WordPress core as external.

Typical structure:

```text
project/
  cms/
    plugins/
    themes/
    mu-plugins/
  .wp-core-base/manifest.php
```

Good fit for:

- Docker or image-first delivery
- immutable runtime builds
- platform-managed WordPress core
- repos that use a custom content root such as `cms/`

## Content-Only Core Loading

`content-only` changes what the framework owns. It stages a runtime payload, but it does not install or bootstrap WordPress core.

Three common loading patterns are:

1. platform-managed core with a separate content mount, where the platform or base image provides WordPress core and the repo only stages the content tree
2. image-built core with staged content, where CI copies the staged runtime payload into an image that already contains WordPress core
3. externally mounted core and content, where the web server or orchestrator keeps WordPress core outside the repo and maps the staged content payload into the runtime docroot

The responsibility boundary stays the same in all three cases:

- `wp-core-base` owns the manifest, dependency updates, runtime hygiene, and `stage-runtime`
- the image, host, or platform owns WordPress core installation and bootstrap
- the downstream repo owns the content tree, including custom plugins, themes, MU plugins, and other declared runtime paths

For multisite, treat this as a repository-wide posture. Keep the content root, ownership roots, and governance settings aligned across the network instead of trying to vary them site by site.

There is no built-in WordPress `wp-cli` wrapper in this framework. Use the provided PHP entrypoints or your own shell alias around them if you want a convenience command.

## Common Real-World Architectures

### GitHub + CI/CD Deployment

Recommended profile:

- `full-core` if WordPress core lives in the repo
- `content-only` if core comes from an image or base layer

This is the most integrated setup for update PRs, validation, and release automation.

### GitHub + FTP Or SFTP Deployment

Recommended profile:

- whichever profile matches what you store in Git

This is a valid and supported setup. GitHub handles review and automation; FTP or SFTP still handles delivery to the server.

### GitHub + Docker Image Build

Recommended profile:

- usually `content-only`

The intended flow is:

1. update PR lands in the repository
2. CI runs `doctor`
3. CI runs `stage-runtime`
4. the image build copies the staged runtime payload into the final image

This still does not bootstrap WordPress core for you. The image or platform layer owns that part of the contract.

### No GitHub Yet

Recommended profile:

- either profile, depending on your repo

You can still use the repository structure, manifest, and staged runtime approach without GitHub. You just will not get automated PR creation until you move the source repository to GitHub.

## Recommendation Matrix

- standard WordPress project in Git: `full-core`
- image-first project with external WordPress core: `content-only`
- FTP-based team modernizing gradually: whichever profile matches what is in Git today
- custom content root such as `cms/`: `content-only`

## What To Read Next

- onboarding: [getting-started.md](getting-started.md)
- advanced dependency usage: [downstream-usage.md](downstream-usage.md)
- runtime and update operations: [operations.md](operations.md)
