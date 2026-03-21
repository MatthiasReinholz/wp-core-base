# Getting Started

This guide is for downstream users of `wp-core-base`.

If you are contributing to `wp-core-base` itself, use [contributing.md](contributing.md).

## Choose Your Starting Point

- use [Full-Core Project From Scratch](#full-core-project-from-scratch) if your downstream repository should contain WordPress core
- use [Content-Only Project From Scratch](#content-only-project-from-scratch) if your downstream repository should contain only `wp-content` or another content tree such as `cms/`
- use [Existing Git-Managed Project](#existing-git-managed-project) if you already have WordPress in Git
- use [Existing FTP Or Manual Deployment](#existing-ftp-or-manual-deployment) if your deployment still happens by file transfer or manual upload

## What The Framework Controls

`wp-core-base` manages:

- repository structure
- dependency metadata through `.wp-core-base/manifest.php`
- update pull requests
- runtime staging for build or deployment pipelines

`wp-core-base` does not force:

- a specific host
- a specific local dev stack
- a specific deployment method
- Composer as your source of truth

## Full-Core Project From Scratch

Use this model when your downstream repository should contain WordPress core directly.

Typical steps:

1. create a new Git repository for your project
2. start from a tagged release of `wp-core-base`
3. keep WordPress core at the repo root
4. add your project-specific plugins, themes, and deployment files
5. update `.wp-core-base/manifest.php` so every managed or local runtime dependency is declared explicitly
6. run:

```bash
php tools/wporg-updater/bin/wporg-updater.php doctor
php tools/wporg-updater/bin/wporg-updater.php stage-runtime --output=.wp-core-base/build/runtime
```

7. if you want automated PRs, enable the GitHub workflows

Use this model when you want the simplest “WordPress in Git” mental model.

## Content-Only Project From Scratch

Use this model when WordPress core is external and your repository should contain only the content tree.

This is the right fit for:

- Docker or image-first deployments
- immutable runtime images
- projects where WordPress core comes from a base image or platform layer
- repos that use `cms/` instead of `wp-content/`

Fastest bootstrap:

```bash
php vendor/wp-core-base/tools/wporg-updater/bin/wporg-updater.php scaffold-downstream --repo-root=. --profile=content-only --content-root=cms
php vendor/wp-core-base/tools/wporg-updater/bin/wporg-updater.php doctor --repo-root=. --github
```

Then:

1. classify every runtime dependency in `.wp-core-base/manifest.php`
2. use `management: managed` for dependencies the updater may overwrite
3. use `management: local` for repo-owned code that the updater must never replace
4. use `management: ignored` only for paths you intentionally want outside runtime staging
5. run:

```bash
php vendor/wp-core-base/tools/wporg-updater/bin/wporg-updater.php stage-runtime --repo-root=. --output=.wp-core-base/build/runtime
```

6. point your image build at the staged runtime directory instead of the raw working tree

## Existing Git-Managed Project

There are two common adoption patterns.

### Adopt The Base Structure

Choose this when your repository is already close to a standard WordPress layout and you want `wp-core-base` to act as the foundation of the code base itself.

Suggested order:

1. create an adoption branch
2. decide whether `full-core` or `content-only` matches your architecture
3. bring in `wp-core-base` through your preferred dependency strategy
4. create or migrate to `.wp-core-base/manifest.php`
5. declare every managed and local dependency explicitly
6. run `doctor` and `stage-runtime`
7. enable GitHub automation only after the manifest is correct

### Adopt The Automation First

Choose this when your repo layout is already the one you want and you mainly need update PR automation and runtime staging.

Suggested order:

1. vendor or otherwise make `wp-core-base` available in the repository
2. run `scaffold-downstream`
3. fill in `.wp-core-base/manifest.php`
4. run `doctor --github`
5. test one dry-run or one manual workflow dispatch before enabling the schedule

## Existing FTP Or Manual Deployment

You can keep FTP or manual deployment and still use `wp-core-base`.

The important shift is that Git becomes the source of truth for code, even if deployment is still manual.

Recommended order:

1. put the site code into Git
2. make the project reproducible locally
3. choose `full-core` or `content-only`
4. create the manifest
5. adopt GitHub only when you want automated pull requests
6. keep deploying by FTP, SFTP, rsync, or manual upload if that still fits your team

GitHub is optional for the code base itself. It becomes required only if you want the automated update PR flow.

## Local Development

Local development is normal WordPress development.

Use whichever local stack your team already prefers, such as:

- Local
- DDEV
- Docker
- MAMP
- a plain PHP and MySQL stack

The framework-specific commands you will use most often are:

```bash
php tools/wporg-updater/bin/wporg-updater.php doctor
php tools/wporg-updater/bin/wporg-updater.php stage-runtime --output=.wp-core-base/build/runtime
php tools/wporg-updater/tests/run.php
```

If `wp-core-base` is vendored into another repository, run the same commands from that vendored path and pass `--repo-root=.`

## GitHub Basics For Teams New To GitHub

GitHub is used here for three things:

- remote Git hosting
- scheduled workflows through GitHub Actions
- reviewable update PRs

It does not have to be your deployment tool.

You can use GitHub while still:

- building Docker images elsewhere
- deploying by FTP or SFTP
- deploying manually from a local workstation
- using another CI platform for final release delivery

## What To Read Next

- architecture choices: [deployment-models.md](deployment-models.md)
- dependency and manifest design: [downstream-usage.md](downstream-usage.md)
- day-to-day usage: [operations.md](operations.md)
- manifest schema: [manifest-reference.md](manifest-reference.md)
- migration help: [migration-guide.md](migration-guide.md)
