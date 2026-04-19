# Getting Started

This guide is for downstream users of `wp-core-base`.

If you are contributing to `wp-core-base` itself, use [contributing.md](contributing.md).
If you want the vocabulary before the setup steps, read [concepts.md](concepts.md).
If you want the routine add/remove dependency workflow, read [managing-dependencies.md](managing-dependencies.md).

## Choose Your Starting Point

- use [Full-Core Project From Scratch](#full-core-project-from-scratch) if your downstream repository should contain WordPress core
- use [Content-Only Project From Scratch](#content-only-project-from-scratch) if your downstream repository should contain only `wp-content` or another content tree such as `cms/`
- use [Existing Git-Managed Project](#existing-git-managed-project) if you already have WordPress in Git
- use [Existing FTP Or Manual Deployment](#existing-ftp-or-manual-deployment) if your deployment still happens by file transfer or manual upload

## What The Framework Controls

`wp-core-base` manages:

- repository structure
- dependency metadata through `.wp-core-base/manifest.php`
- framework version metadata through `.wp-core-base/framework.php`
- update pull requests
- runtime staging for build or deployment pipelines

`wp-core-base` does not force:

- a specific host
- a specific local dev stack
- a specific deployment method
- Composer as your source of truth
- your custom code to become updater-managed

Project-owned plugins, themes, MU plugins, and runtime files can stay downstream-owned as `local` entries.

That also applies to runtime directories such as `cms/languages` or shared content-root asset trees.

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

7. if you want automated PRs, scaffold or enable the GitHub workflows or GitLab pipeline for your chosen automation host

The release version of the framework itself is tracked separately in `.wp-core-base/framework.php`.

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
php vendor/wp-core-base/tools/wporg-updater/bin/wporg-updater.php scaffold-downstream --repo-root=. --profile=content-only-default --content-root=cms
php vendor/wp-core-base/tools/wporg-updater/bin/wporg-updater.php doctor --repo-root=. --automation
```

If the project is image-first, keeps core external, expects substantial `local` code, and wants staged-clean validation by default, start with:

```bash
php vendor/wp-core-base/tools/wporg-updater/bin/wporg-updater.php scaffold-downstream --repo-root=. --profile=content-only-image-first --content-root=cms
php vendor/wp-core-base/tools/wporg-updater/bin/wporg-updater.php doctor --repo-root=. --automation
```

If the project already has a strong PR build workflow that runs `doctor`, `stage-runtime`, and image or smoke checks, use the compact image-first profile instead:

```bash
php vendor/wp-core-base/tools/wporg-updater/bin/wporg-updater.php scaffold-downstream --repo-root=. --profile=content-only-image-first-compact --content-root=cms
php vendor/wp-core-base/tools/wporg-updater/bin/wporg-updater.php doctor --repo-root=. --automation
```

Then:

1. classify every runtime dependency in `.wp-core-base/manifest.php`
2. use `management: managed` for dependencies the updater may overwrite
3. use `management: local` for repo-owned code that the updater must never replace
4. use `management: ignored` only for paths you intentionally want outside runtime staging
5. keep `runtime.manifest_mode` at `strict` if you want full manifest ownership from day one, or set it to `relaxed` temporarily while migrating mixed-source trees
6. keep `validation_mode` at `source-clean` if your source trees are already runtime-ready, or switch to `staged-clean` when local code needs strip-on-stage rules
7. leave managed dependencies runtime-ready when possible, but use managed sanitation rules when upstream release archives carry predictable non-runtime files
8. run:

```bash
php vendor/wp-core-base/tools/wporg-updater/bin/wporg-updater.php stage-runtime --repo-root=. --output=.wp-core-base/build/runtime
```

9. point your image build at the staged runtime directory instead of the raw working tree

If you want ongoing upstream framework maintenance, keep the scaffolded `.wp-core-base/framework.php` file and the `wp-core-base` self-update workflow enabled.

Scaffolding also writes `.wp-core-base/USAGE.md`, `.wp-core-base/premium-providers.php`, and a downstream `AGENTS.md`. Treat those as the local entry points for routine dependency authoring, premium-provider registration, and agentic coding inside the project repo.

The standalone `wp-core-base Runtime Validation` workflow is the default because it gives downstreams a small canonical runtime-contract check even when they do not yet have a mature PR build pipeline. The scaffold also writes a separate merged-PR reconciliation workflow so scheduled/manual update runs stay distinct from post-merge queue unblocking. If your main PR workflow already runs `doctor` and `stage-runtime`, the compact image-first scaffold profile is usually the better fit.

After scaffolding, mark `wp-core-base Runtime Validation` (or your equivalent workflow that runs `doctor --automation` + `stage-runtime`) as a required branch-protection check before merges. This prevents manifest-only or payload-only merges from bypassing the runtime checksum contract.

If your repo already has a blanket `/vendor/` ignore from historical Composer usage, do not unignore the whole directory. Keep the exception narrow so only `vendor/wp-core-base` becomes repo-owned:

```gitignore
/vendor/*
!/vendor/wp-core-base
!/vendor/wp-core-base/**
```

That keeps framework self-update PRs reviewable without accidentally committing unrelated vendor packages.

Use `local` freely. It is the intended way to keep custom plugins, themes, MU plugin files, and other downstream-owned runtime code in the project.

Managed dependencies follow a different contract: the updater may normalize them during `sync`, and their manifest checksum represents the sanitized runtime snapshot that the repo should keep in Git.

For routine entry creation, prefer the CLI over hand-authoring manifest arrays:

```bash
vendor/wp-core-base/bin/wp-core-base add-dependency --repo-root=. --source=local --kind=plugin --path=cms/plugins/project-plugin
vendor/wp-core-base/bin/wp-core-base list-dependencies --repo-root=.
```

If you are migrating a mixed repository, these helper commands are useful:

```bash
php vendor/wp-core-base/tools/wporg-updater/bin/wporg-updater.php suggest-manifest --repo-root=.
php vendor/wp-core-base/tools/wporg-updater/bin/wporg-updater.php format-manifest --repo-root=.
```

## Existing Git-Managed Project

There are two common adoption patterns.

### Adopt The Base Structure

Choose this when your repository is already close to a standard WordPress layout and you want `wp-core-base` to act as the foundation of the code base itself.

Suggested order:

1. create an adoption branch
2. decide whether `full-core` or `content-only` matches your architecture
3. bring in `wp-core-base` through your preferred dependency strategy
4. create or migrate to `.wp-core-base/manifest.php` and `.wp-core-base/framework.php`
5. declare every managed and local dependency explicitly
6. run `doctor` and `stage-runtime`
7. enable GitHub or GitLab automation only after the manifest is correct

### Adopt The Automation First

Choose this when your repo layout is already the one you want and you mainly need update PR automation and runtime staging.

Suggested order:

1. vendor or otherwise make `wp-core-base` available in the repository
2. run `scaffold-downstream`
3. fill in `.wp-core-base/manifest.php`
4. keep `.wp-core-base/framework.php` pinned to the vendored framework version
5. run `doctor --automation`
6. test one dry-run or one manual workflow dispatch before enabling the schedule

## Existing FTP Or Manual Deployment

You can keep FTP or manual deployment and still use `wp-core-base`.

The important shift is that Git becomes the source of truth for code, even if deployment is still manual.

Recommended order:

1. put the site code into Git
2. make the project reproducible locally
3. choose `full-core` or `content-only`
4. create the manifest
5. adopt GitHub or GitLab only when you want automated pull requests
6. keep deploying by FTP, SFTP, rsync, or manual upload if that still fits your team

GitHub and GitLab are optional for the code base itself. One of them becomes required only if you want the automated update PR flow.

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
bin/wp-core-base list-dependencies
php tools/wporg-updater/bin/wporg-updater.php doctor
php tools/wporg-updater/bin/wporg-updater.php stage-runtime --output=.wp-core-base/build/runtime
php tools/wporg-updater/bin/wporg-updater.php framework-sync --check-only
```

If `wp-core-base` is vendored into another repository, run the same commands from that vendored path and pass `--repo-root=.`

If PHP is not installed locally yet, see [local-prerequisites.md](local-prerequisites.md).

Two practical defaults for new downstreams:

- keep `runtime.manifest_mode` as `strict`
- use `local` for custom project code rather than trying to force it through update automation

## Git Host Basics For Teams New To Hosted Automation

GitHub or GitLab is used here for three things:

- remote Git hosting
- scheduled automation through GitHub Actions or GitLab CI
- reviewable update PRs

Neither host has to be your deployment tool.

You can use either host while still:

- building Docker images elsewhere
- deploying by FTP or SFTP
- deploying manually from a local workstation
- using another CI platform for final release delivery

If you choose GitLab as the automation host, set a masked CI/CD variable named `GITLAB_TOKEN` with `api` and `write_repository` access before enabling the scaffolded pipeline. GitLab CI already provides the project identity variables that `wp-core-base` consumes.

## What To Read Next

- concepts and glossary: [concepts.md](concepts.md)
- FAQ and objections: [faq.md](faq.md)
- evaluation checklist: [evaluation-guide.md](evaluation-guide.md)
- support boundaries: [support-matrix.md](support-matrix.md)
- architecture choices: [deployment-models.md](deployment-models.md)
- dependency and manifest design: [downstream-usage.md](downstream-usage.md)
- day-to-day usage: [operations.md](operations.md)
- manifest schema: [manifest-reference.md](manifest-reference.md)
- migration help: [migration-guide.md](migration-guide.md)
