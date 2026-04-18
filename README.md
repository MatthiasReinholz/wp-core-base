# wp-core-base

`wp-core-base` is a reusable WordPress foundation for teams that want their WordPress code, dependency snapshots, and update flow to live in Git.

Requires PHP 8.1 or newer. The framework is tested on PHP 8.1, 8.3, and 8.4.

It supports two downstream styles:

- `full-core`: the downstream repository contains WordPress core
- `content-only`: the downstream repository contains only its content tree and treats WordPress core as external

This README is written for people adopting `wp-core-base` in their own WordPress projects. If you are contributing to `wp-core-base` itself, use [docs/contributing.md](docs/contributing.md).

If you are an AI agent or you are asking an AI agent to evaluate or implement the framework, start with [AGENTS.md](/Users/matthias/DEV/wp-core-base/AGENTS.md).

## What You Get

- a versioned WordPress base repository
- an explicit manifest at `.wp-core-base/manifest.php`
- explicit framework release metadata at `.wp-core-base/framework.php`
- scheduled GitHub update PRs for WordPress core and managed dependencies
- scheduled GitHub PRs when a newer `wp-core-base` framework release is available
- support for WordPress.org, GitHub Release, and downstream-registered premium plugin sources
- support for project-owned custom code as first-class `local` runtime entries
- optional staged-clean runtime assembly for richer local source trees
- managed-dependency sanitation during update ingestion when upstream archives contain non-runtime metadata
- runtime staging for image-first or immutable deployment flows
- a framework-managed WordPress admin governance MU plugin that marks workflow-managed plugins and suppresses in-dashboard update actions for them
- no built-in WordPress `wp-cli` wrapper; use the shipped PHP entrypoints or your own shell alias around them

## Why This Is Valuable

`wp-core-base` is not just a starter repository. It gives a WordPress project a stronger operational model.

- explicit dependency ownership through the manifest, so managed, local, and ignored runtime code are clearly separated. See [manifest-reference.md](/Users/matthias/DEV/wp-core-base/docs/manifest-reference.md).
- first-class support for both `full-core` and `content-only` downstreams, including image-first deployments. See [deployment-models.md](/Users/matthias/DEV/wp-core-base/docs/deployment-models.md).
- reviewable update PRs instead of silent in-dashboard changes, so every dependency change becomes a normal Git review event. See [operations.md](/Users/matthias/DEV/wp-core-base/docs/operations.md).
- richer PR context for reviewers, including release scope, release notes, release timestamp, and support-topic signals for WordPress.org plugins. See [operations.md](/Users/matthias/DEV/wp-core-base/docs/operations.md#reviewing-update-prs).
- intelligent PR lifecycle behavior: patch releases can refresh an existing PR, while later minor or major releases can queue behind unresolved work. See [automation-overview.md](/Users/matthias/DEV/wp-core-base/docs/automation-overview.md#pull-request-behavior).
- `wp-core-base` itself is versioned and updateable, so downstream repos can pin a framework release and receive dedicated framework-update PRs instead of treating the base as a one-time copy. See [downstream-usage.md](/Users/matthias/DEV/wp-core-base/docs/downstream-usage.md#framework-version-pinning).
- support for both WordPress.org and GitHub Release backed dependencies, including private GitHub release assets. See [downstream-usage.md](/Users/matthias/DEV/wp-core-base/docs/downstream-usage.md#source-types).
- a premium provider extension model, so downstream repos can register their own premium plugin source adapters instead of forcing premium updates through manual plugin dashboard workflows. See [adding-premium-provider.md](/Users/matthias/DEV/wp-core-base/docs/adding-premium-provider.md).
- local project-owned code remains first-class, so custom plugins, themes, MU plugins, runtime files, and runtime directories do not need to be forced through updater automation. See [downstream-usage.md](/Users/matthias/DEV/wp-core-base/docs/downstream-usage.md#managed-versus-local).
- normalized runtime snapshots, because managed dependencies can be sanitized during update ingestion and local code can use staged-clean strip rules when needed. See [manifest-reference.md](/Users/matthias/DEV/wp-core-base/docs/manifest-reference.md#managed-sanitation).
- deterministic runtime staging for Docker, immutable images, and other build pipelines, so deployments can use a clean staged payload instead of the raw working tree. See [downstream-usage.md](/Users/matthias/DEV/wp-core-base/docs/downstream-usage.md#runtime-staging).
- clearer operational ownership inside wp-admin, because the scaffolded governance MU plugin makes workflow-managed plugins visibly distinct from project-owned local code. See [downstream-usage.md](/Users/matthias/DEV/wp-core-base/docs/downstream-usage.md#admin-governance).
- migration support for real-world repos, including strict vs relaxed ownership modes, manifest suggestions, and scaffolding presets for common downstream patterns. See [getting-started.md](/Users/matthias/DEV/wp-core-base/docs/getting-started.md) and [migration-guide.md](/Users/matthias/DEV/wp-core-base/docs/migration-guide.md).

## Start Here

Choose the path that matches your project:

- brand-new WordPress project: [docs/getting-started.md#full-core-project-from-scratch](docs/getting-started.md#full-core-project-from-scratch)
- content-only or image-first downstream: [docs/getting-started.md#content-only-project-from-scratch](docs/getting-started.md#content-only-project-from-scratch)
- existing Git-managed WordPress project: [docs/getting-started.md#existing-git-managed-project](docs/getting-started.md#existing-git-managed-project)
- existing FTP or manual deployment workflow: [docs/getting-started.md#existing-ftp-or-manual-deployment](docs/getting-started.md#existing-ftp-or-manual-deployment)
- local development and validation: [docs/getting-started.md#local-development](docs/getting-started.md#local-development)

If you need help choosing an architecture first, read [docs/deployment-models.md](docs/deployment-models.md).

If you want the content-only core-loading patterns, read [docs/deployment-models.md#content-only-core-loading](docs/deployment-models.md#content-only-core-loading).

If you want the framework vocabulary first, read [docs/concepts.md](docs/concepts.md).

If you want the day-to-day dependency authoring workflow, read [docs/managing-dependencies.md](docs/managing-dependencies.md).

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
bin/wp-core-base list-dependencies
php tools/wporg-updater/bin/wporg-updater.php doctor
php tools/wporg-updater/bin/wporg-updater.php stage-runtime --output=.wp-core-base/build/runtime
```

`stage-runtime --output` must stay repo-relative to the current repository. Absolute paths and traversal-style overrides are rejected.

If you are onboarding a downstream repository and want the framework to generate the initial manifest and workflows:

```bash
php vendor/wp-core-base/tools/wporg-updater/bin/wporg-updater.php scaffold-downstream --repo-root=. --profile=content-only-default --content-root=cms
php vendor/wp-core-base/tools/wporg-updater/bin/wporg-updater.php doctor --repo-root=. --github
```

Use `full-core` instead of `content-only` if the downstream repository stores WordPress core in Git.
Use `content-only-image-first` if you want a stricter image-first preset with external core, `staged-clean` validation, and starter ownership roots for content repos.
Scaffolding writes both `.wp-core-base/manifest.php` and `.wp-core-base/framework.php`, along with the scheduled updates, merged-PR reconciliation, blocker, validation, and framework self-update workflows. It also writes a framework-managed admin governance MU plugin, local downstream guidance at `.wp-core-base/USAGE.md`, and a downstream `AGENTS.md` so humans and coding agents can discover the correct CLI-first workflow from inside the project repo.

If your downstream repo already ignores `/vendor/`, keep the ignore narrow so `vendor/wp-core-base` remains committed and self-updateable. See [downstream-usage.md](/Users/matthias/DEV/wp-core-base/docs/downstream-usage.md) for the recommended pattern.

The framework is intentionally selective: it can manage chosen dependencies for updates while leaving your custom plugins, themes, MU plugins, runtime files, and runtime directories owned directly by the downstream project. `local` is a normal long-term ownership model, not a migration workaround.

## Current Baseline

This repository currently ships:

- framework release `1.3.4`
- WordPress core `6.9.4`
- Akismet `5.6`
- WooCommerce `10.7.0`
- Jetpack `15.7.1`
- Contact Form 7 `6.1.5`
- Redirection `5.7.5`
- Twenty Twenty-Three `1.6`
- Twenty Twenty-Four `1.4`
- Twenty Twenty-Five `1.4`

These versions describe the code committed in this repository, not a floating latest channel.

## Documentation Map

- AI and agent entry point: [AGENTS.md](/Users/matthias/DEV/wp-core-base/AGENTS.md)
- concepts and glossary: [docs/concepts.md](docs/concepts.md)
- FAQ and objections: [docs/faq.md](docs/faq.md)
- evaluation checklist: [docs/evaluation-guide.md](docs/evaluation-guide.md)
- support boundaries: [docs/support-matrix.md](docs/support-matrix.md)
- onboarding and implementation: [docs/getting-started.md](docs/getting-started.md)
- dependency authoring tasks: [docs/managing-dependencies.md](docs/managing-dependencies.md)
- local PHP bootstrap notes: [docs/local-prerequisites.md](docs/local-prerequisites.md)
- deployment and architecture choices: [docs/deployment-models.md](docs/deployment-models.md)
- advanced downstream usage: [docs/downstream-usage.md](docs/downstream-usage.md)
- premium source and admin-governance boundaries: [docs/support-matrix.md](docs/support-matrix.md)
- ongoing operations: [docs/operations.md](docs/operations.md)
- manifest reference: [docs/manifest-reference.md](docs/manifest-reference.md)
- migration guidance: [docs/migration-guide.md](docs/migration-guide.md)
- example downstream manifest and workflows: [docs/examples/](docs/examples/)
- maintainer release flow: [docs/release-process.md](docs/release-process.md)
- contributor guide: [docs/contributing.md](docs/contributing.md)
- maintainer architecture: [docs/architecture.md](docs/architecture.md)
- automation internals: [docs/automation-overview.md](docs/automation-overview.md)
- security model: [docs/security-model.md](docs/security-model.md)

## Contributing

If you are changing `wp-core-base` itself, do not use this README as the maintainer guide.

Use [docs/contributing.md](docs/contributing.md). That document covers verification, release discipline, audience separation, and the repository’s contributor responsibilities.
