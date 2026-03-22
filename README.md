# wp-core-base

`wp-core-base` is a reusable WordPress foundation for teams that want their WordPress code, dependency snapshots, and update flow to live in Git.

It supports two downstream styles:

- `full-core`: the downstream repository contains WordPress core
- `content-only`: the downstream repository contains only its content tree and treats WordPress core as external

This README is written for people adopting `wp-core-base` in their own WordPress projects. If you are contributing to `wp-core-base` itself, use [docs/contributing.md](docs/contributing.md).

## What You Get

- a versioned WordPress base repository
- an explicit manifest at `.wp-core-base/manifest.php`
- scheduled GitHub update PRs for WordPress core and managed dependencies
- support for WordPress.org and GitHub Release backed dependencies
- support for project-owned custom code as first-class `local` runtime entries
- optional staged-clean runtime assembly for richer local source trees
- runtime staging for image-first or immutable deployment flows

## Start Here

Choose the path that matches your project:

- brand-new WordPress project: [docs/getting-started.md#full-core-project-from-scratch](docs/getting-started.md#full-core-project-from-scratch)
- content-only or image-first downstream: [docs/getting-started.md#content-only-project-from-scratch](docs/getting-started.md#content-only-project-from-scratch)
- existing Git-managed WordPress project: [docs/getting-started.md#existing-git-managed-project](docs/getting-started.md#existing-git-managed-project)
- existing FTP or manual deployment workflow: [docs/getting-started.md#existing-ftp-or-manual-deployment](docs/getting-started.md#existing-ftp-or-manual-deployment)
- local development and validation: [docs/getting-started.md#local-development](docs/getting-started.md#local-development)

If you need help choosing an architecture first, read [docs/deployment-models.md](docs/deployment-models.md).

## GitHub And Non-GitHub Use

You do not need GitHub to use `wp-core-base` as a code base.

You do need GitHub if you want the scheduled pull-request automation, because that part of the framework is built on GitHub Actions and GitHub pull requests.

That means these are all valid:

- GitHub for source control and CI/CD deployment
- GitHub for source control, with FTP or SFTP deployment
- GitHub for source control, with manual deployment
- no GitHub yet, with manual adoption of tagged releases

## Recommended First Commands

If `wp-core-base` is the current repository:

```bash
php tools/wporg-updater/bin/wporg-updater.php doctor
php tools/wporg-updater/bin/wporg-updater.php stage-runtime --output=.wp-core-base/build/runtime
```

If you are onboarding a downstream repository and want the framework to generate the initial manifest and workflows:

```bash
php vendor/wp-core-base/tools/wporg-updater/bin/wporg-updater.php scaffold-downstream --repo-root=. --profile=content-only-default --content-root=cms
php vendor/wp-core-base/tools/wporg-updater/bin/wporg-updater.php doctor --repo-root=. --github
```

Use `full-core` instead of `content-only` if the downstream repository stores WordPress core in Git.

The framework is intentionally selective: it can manage chosen dependencies for updates while leaving your custom plugins, themes, MU plugins, runtime files, and runtime directories owned directly by the downstream project.

## Current Baseline

This repository currently ships:

- WordPress core `6.9.4`
- Akismet `5.6`
- WooCommerce `10.6.1`
- Jetpack `15.6`
- Contact Form 7 `6.1.5`
- Redirection `5.7.5`
- Twenty Twenty-Three `1.6`
- Twenty Twenty-Four `1.4`
- Twenty Twenty-Five `1.4`

These versions describe the code committed in this repository, not a floating latest channel.

## Documentation Map

- onboarding and implementation: [docs/getting-started.md](docs/getting-started.md)
- deployment and architecture choices: [docs/deployment-models.md](docs/deployment-models.md)
- advanced downstream usage: [docs/downstream-usage.md](docs/downstream-usage.md)
- ongoing operations: [docs/operations.md](docs/operations.md)
- manifest reference: [docs/manifest-reference.md](docs/manifest-reference.md)
- migration guidance: [docs/migration-guide.md](docs/migration-guide.md)
- example downstream manifest and workflows: [docs/examples/](docs/examples/)
- contributor guide: [docs/contributing.md](docs/contributing.md)
- automation internals: [docs/automation-overview.md](docs/automation-overview.md)

## Contributing

If you are changing `wp-core-base` itself, do not use this README as the maintainer guide.

Use [docs/contributing.md](docs/contributing.md). That document covers verification, release discipline, audience separation, and the repository’s contributor responsibilities.
