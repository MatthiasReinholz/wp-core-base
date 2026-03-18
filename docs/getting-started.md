# Getting Started

This guide is for people who want to adopt `wp-core-base` in a real WordPress project.

If you are contributing to `wp-core-base` itself, use [contributing.md](contributing.md) instead.

## The Mental Model

`wp-core-base` is about source code management, versioning, and update flow.

It is not your hosting provider and it is not your database.

In practical terms:

- your WordPress code lives in Git
- your project can use `wp-core-base` as its baseline or as its update-tooling source
- if you use GitHub, the automation can open update pull requests for you
- deployment to the actual server is still your choice

That means you can keep a Git-based deployment, a CI/CD deployment, or even an FTP-based deployment while still using this project.

## What You Need

For basic use:

- a Git repository for your WordPress code
- a local development environment that can run WordPress
- a decision about whether you want to use `wp-core-base` as code, automation, or both

For automated update pull requests:

- a GitHub repository
- GitHub Actions enabled
- comfort with reviewing and merging pull requests

The config examples also support GitHub Enterprise environments that expose `GITHUB_API_URL`.

If you do not use GitHub, you can still use `wp-core-base`, but the automated PR feature does not apply.

## If GitHub Is New To You

GitHub plays three roles in this project:

- it stores the Git repository remotely
- it runs scheduled automation through GitHub Actions
- it provides pull requests so updates can be reviewed before merge

It does not have to be your deployment method.

You can use GitHub for source control and reviews while still:

- developing locally
- deploying manually
- deploying through FTP or SFTP
- using a separate hosting workflow

If you are new to GitHub, the simplest path is usually:

1. get the project working locally
2. push it to a GitHub repository
3. turn on the update workflow later

## Choose The Path That Matches You

- New project from scratch: go to [Brand-New WordPress Project](#brand-new-wordpress-project)
- Existing project already in Git: go to [Existing WordPress Project Already In Git](#existing-wordpress-project-already-in-git)
- Existing site still deployed manually or by FTP: go to [Existing WordPress Site With FTP Or Manual Deployment](#existing-wordpress-site-with-ftp-or-manual-deployment)
- Need to understand local development: go to [Local Development](#local-development)
- Need to understand deployment choices: read [deployment-models.md](deployment-models.md)

## Fastest Safe Automation Setup

If your main goal is to get the GitHub automation wired correctly without copying hidden files by hand, use the scaffolder first.

If `wp-core-base` is the repository itself:

```bash
php tools/wporg-updater/bin/wporg-updater.php scaffold-downstream --repo-root=.
php tools/wporg-updater/bin/wporg-updater.php doctor --repo-root=. --github
```

If `wp-core-base` is vendored inside another repository at `vendor/wp-core-base`:

```bash
php vendor/wp-core-base/tools/wporg-updater/bin/wporg-updater.php scaffold-downstream --repo-root=. --tool-path=vendor/wp-core-base
php vendor/wp-core-base/tools/wporg-updater/bin/wporg-updater.php doctor --repo-root=. --github
```

That flow creates:

- `.github/wporg-updates.php`
- `.github/workflows/wporg-updates.yml`
- `.github/workflows/wporg-update-pr-blocker.yml`

Then review the generated config, enable only the plugins you actually manage, and commit the files into the downstream repository.

## Brand-New WordPress Project

If you are starting fresh, you have two realistic options.

### Simple path for beginners

Use `wp-core-base` as the starting codebase for your new repository.

Practical steps:

1. Create a new repository for your site.
2. Start from a tagged release of `wp-core-base`.
3. Add your site-specific theme, plugins, configuration, and deployment setup.
4. Run the site locally.
5. If you want automated update PRs, move the project to GitHub and add the downstream workflow and config examples from this repository.

This is the easiest mental model if you are new to Git-based WordPress workflows.

### Linked upstream path for long-term reuse

If you already know you want an ongoing upstream relationship, use one of the dependency models in [downstream-usage.md](downstream-usage.md), usually a Git subtree or Git submodule.

Practical steps:

1. Create your downstream repository.
2. Choose Git subtree or Git submodule.
3. Bring `wp-core-base` in at a released tag.
4. Add your project-specific code and deployment logic in the downstream repository.
5. Document for your team how future upstream pulls should happen.

That makes future upstream pulls more structured, but it is a little more advanced.

## Existing WordPress Project Already In Git

If your WordPress project already lives in Git, you do not have to replace everything at once.

You usually have two adoption choices:

### Adopt the base as a code dependency

Use this when:

- your existing project is still structurally close to a standard WordPress codebase
- you want an explicit upstream/downstream relationship
- you are comfortable reorganizing your repository around a maintained base

Use [downstream-usage.md](downstream-usage.md) for the advanced dependency models.

Practical migration flow:

1. Create a branch for the adoption work.
2. Compare your current repository structure to the standard WordPress structure in `wp-core-base`.
3. Decide whether the project is close enough to adopt the base cleanly.
4. Move any local patches out of plugin directories that you want the updater to manage.
5. Introduce the base through the dependency model you choose.
6. Test locally before changing deployment.

### Adopt only the update automation

Use this when:

- your existing repository already has the code layout you want
- you mainly want the WordPress core and plugin update PR workflow
- you do not want to replace your project structure with the `wp-core-base` structure

In that case, your downstream repository owns:

- its own codebase
- its own `.github/wporg-updates.php` configuration
- its own workflow file
- its own blocker workflow file if you want queued later PRs

The updater code itself can come from `wp-core-base`.

Start with:

- [examples/downstream-workflow.yml](examples/downstream-workflow.yml)
- [examples/downstream-pr-blocker-workflow.yml](examples/downstream-pr-blocker-workflow.yml)
- [examples/downstream-wporg-updates.php](examples/downstream-wporg-updates.php)
- [downstream-usage.md](downstream-usage.md)

Practical migration flow:

1. Keep your existing repository structure.
2. Add a downstream workflow based on the example file.
3. Add the blocker workflow if you want later minor and major PRs to queue cleanly.
4. Add a downstream `.github/wporg-updates.php` file based on the example config.
5. Make the updater code available in the repository, for example through a subtree, submodule, or vendored copy of `wp-core-base`.
6. Run the workflow in dry-run mode first if you want a cautious rollout.

## Existing WordPress Site With FTP Or Manual Deployment

This is a common case, and it is important not to confuse deployment with source control.

You can keep FTP deployment and still use `wp-core-base`.

The practical migration path is:

1. Make the current site code your source of truth in Git.
2. Set up a local development environment so you can test changes safely.
3. Decide whether you want to adopt `wp-core-base` as code, automation, or both.
4. If you want automated update PRs, move the source repository to GitHub.
5. Keep deploying however you want, including FTP, SFTP, or manual upload, after reviewed changes are merged.

Important:

- GitHub is used for source management and pull requests in this model.
- FTP is still just the way files reach your server.
- GitHub does not automatically deploy anything unless you add a deployment workflow.

If you are coming from a live-only site with no Git history at all, do not start by turning on automation. Start by putting the code into Git and making the project reproducible locally.

Then add automation.

## Local Development

`wp-core-base` works like a normal WordPress codebase in local development.

The exact local stack is your choice. Common options include:

- Local
- DDEV
- Docker
- MAMP
- Valet
- any standard PHP and MySQL setup that can run WordPress

Typical local flow:

1. Clone the repository.
2. Create `wp-config.php` from `wp-config-sample.php`.
3. Point it to a local database.
4. Run the code in your preferred local stack.
5. Make and test changes locally.
6. Commit the changes into Git.

If the repository is on GitHub, you can then push and use the automated PR flow. If it is not on GitHub, you can still work locally and manage updates manually.

## Enabling The GitHub Automation

If you want automatic WordPress core and plugin update pull requests, the minimum path is:

1. Put the project in a GitHub repository.
2. Run the scaffolder, or copy:
   - [examples/downstream-workflow.yml](examples/downstream-workflow.yml)
   - [examples/downstream-pr-blocker-workflow.yml](examples/downstream-pr-blocker-workflow.yml)
   - [examples/downstream-wporg-updates.php](examples/downstream-wporg-updates.php)
3. Run `doctor --github`.
4. If you want a cautious first run, enable dry-run mode temporarily.
5. Enable GitHub Actions.
6. Review the pull requests the automation opens.

The workflow example shows where to add `WPORG_UPDATE_DRY_RUN: 1` for an initial rollout.

If you want later minor and major update PRs to stay queued behind earlier open PRs, add the blocker workflow and make `WordPress.org Update PR Blocker` a required status check in branch protection.

## If You Do Not Want To Use GitHub

You can still use `wp-core-base` as:

- a WordPress baseline
- a versioned release source
- a local development starting point

What you do not get without GitHub is:

- GitHub Actions scheduling
- GitHub pull requests
- automated PR updates and blocker behavior

In that case, you would use the repository more like a maintained upstream codebase and manage adoption manually.

## What To Read Next

- Day-to-day use after setup: [operations.md](operations.md)
- Deployment patterns and GitHub versus FTP: [deployment-models.md](deployment-models.md)
- Advanced dependency models: [downstream-usage.md](downstream-usage.md)
- Technical internals: [automation-overview.md](automation-overview.md)
