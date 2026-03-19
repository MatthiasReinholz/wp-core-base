# wp-core-base

`wp-core-base` is a versioned WordPress base that you can use as the starting point or upstream dependency for a WordPress project.

It is designed for people who want WordPress code to live in Git, be versioned intentionally, and optionally receive update pull requests for WordPress core and selected wordpress.org plugins.

This README is the entry point for users of the base. If you want to work on `wp-core-base` itself as a contributor, maintainer, or repository author, use [docs/contributing.md](docs/contributing.md).

## What This Means In Practice

You can use `wp-core-base` in several different ways:

- as the starting codebase for a brand-new WordPress project
- as an upstream dependency for an existing Git-managed WordPress project
- as the automation source for an existing WordPress repository, even if you still deploy by FTP or another manual flow

`wp-core-base` does not force one hosting or deployment model. It manages code and update flow. Your deployment can still be Git-based, FTP-based, manual, or CI-driven.

## Before You Start

Two points matter up front:

- GitHub is not required to use the code base itself.
- GitHub is required if you want the automated pull-request workflow, because that automation is built around GitHub Actions and GitHub pull requests.

If you do not use GitHub today, you can still use `wp-core-base` as a versioned WordPress base. You would just manage updates manually until or unless you move your source repository to GitHub.

If GitHub itself is new to you, start with [docs/getting-started.md](docs/getting-started.md). That guide explains what GitHub is doing in this setup and what stays unchanged in local development and deployment.

If you already have `wp-core-base` inside a downstream repository and want the quickest safe setup path, [docs/getting-started.md](docs/getting-started.md) now includes a scaffolding flow for the required config and workflow files.

## Choose Your Starting Path

If you are new to this, start with the path that matches your situation:

- Brand-new WordPress project: [docs/getting-started.md#brand-new-wordpress-project](docs/getting-started.md#brand-new-wordpress-project)
- Existing WordPress project already in Git: [docs/getting-started.md#existing-wordpress-project-already-in-git](docs/getting-started.md#existing-wordpress-project-already-in-git)
- Existing WordPress site that is still deployed by FTP or another manual flow: [docs/getting-started.md#existing-wordpress-site-with-ftp-or-manual-deployment](docs/getting-started.md#existing-wordpress-site-with-ftp-or-manual-deployment)
- Local development setup: [docs/getting-started.md#local-development](docs/getting-started.md#local-development)
- Deployment and architecture choices: [docs/deployment-models.md](docs/deployment-models.md)

If you already understand the basics and want the more advanced dependency model, use [docs/downstream-usage.md](docs/downstream-usage.md).

## If You Are Unsure, Start Like This

If you are completely new to this style of workflow, the safest order is:

1. get the WordPress project into Git
2. get it working locally
3. decide whether you want to adopt `wp-core-base` as code, automation, or both
4. move the repository to GitHub only when you are ready for automated pull requests

That path keeps the learning curve manageable and works for both fresh projects and legacy FTP-based sites.

## Current Baseline

The current bundled baseline in this repository is:

- the exact WordPress core and plugin versions currently committed to this Git repository
- WordPress core `6.9.4`
- Akismet `5.6`
- WooCommerce `10.6.1`
- Jetpack `15.6`
- Contact Form 7 `6.1.5`
- Redirection `5.7.5`

## Documentation Map

Use the document that matches your role and level of detail:

- Start here if you are adopting the base: [docs/getting-started.md](docs/getting-started.md)
- Day-to-day usage after adoption: [docs/operations.md](docs/operations.md)
- Compare deployment and architecture options: [docs/deployment-models.md](docs/deployment-models.md)
- Advanced dependency usage: [docs/downstream-usage.md](docs/downstream-usage.md)
- Workflow example for downstream repositories: [docs/examples/downstream-workflow.yml](docs/examples/downstream-workflow.yml)
- Blocker workflow example for downstream repositories: [docs/examples/downstream-pr-blocker-workflow.yml](docs/examples/downstream-pr-blocker-workflow.yml)
- Example downstream update config: [docs/examples/downstream-wporg-updates.php](docs/examples/downstream-wporg-updates.php)
- Contributors, maintainers, and repository author: [docs/contributing.md](docs/contributing.md)
- Technical internals and automation behavior: [docs/automation-overview.md](docs/automation-overview.md)

## Contributing

If you are changing `wp-core-base` itself, do not use this README as the contributor guide.

Use:

- [docs/contributing.md](docs/contributing.md)

If you want a quick local health check after cloning, run:

```bash
php tools/wporg-updater/bin/wporg-updater.php doctor
```
