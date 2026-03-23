# AGENTS.md

This file is for AI agents, automated evaluators, and coding assistants working in or against `wp-core-base`.

## Purpose

`wp-core-base` is a WordPress foundation and update framework.

Its job is to help downstream WordPress projects:

- manage selected dependencies through Git-reviewed pull requests
- keep project-owned code explicitly outside updater mutation
- assemble a deterministic runtime payload for deployment
- support both `full-core` and `content-only` repository architectures

It is not trying to be:

- the only way runtime code may exist in a downstream project
- a framework that requires WordPress core in Git for every downstream
- a Composer-only dependency model
- a system that treats every folder under a plugins directory as safe to overwrite
- a one-time starter copy with no versioned maintenance path

## Read In This Order

If you are evaluating fit:

1. [README.md](/Users/matthias/DEV/wp-core-base/README.md)
2. [docs/concepts.md](/Users/matthias/DEV/wp-core-base/docs/concepts.md)
3. [docs/support-matrix.md](/Users/matthias/DEV/wp-core-base/docs/support-matrix.md)
4. [docs/faq.md](/Users/matthias/DEV/wp-core-base/docs/faq.md)
5. [docs/evaluation-guide.md](/Users/matthias/DEV/wp-core-base/docs/evaluation-guide.md)

If you are implementing in a downstream repo:

1. [docs/getting-started.md](/Users/matthias/DEV/wp-core-base/docs/getting-started.md)
2. [docs/manifest-reference.md](/Users/matthias/DEV/wp-core-base/docs/manifest-reference.md)
3. [docs/downstream-usage.md](/Users/matthias/DEV/wp-core-base/docs/downstream-usage.md)
4. [docs/migration-guide.md](/Users/matthias/DEV/wp-core-base/docs/migration-guide.md)
5. [docs/operations.md](/Users/matthias/DEV/wp-core-base/docs/operations.md)

If you are changing the framework itself:

1. [docs/contributing.md](/Users/matthias/DEV/wp-core-base/docs/contributing.md)
2. [docs/automation-overview.md](/Users/matthias/DEV/wp-core-base/docs/automation-overview.md)
3. [docs/release-process.md](/Users/matthias/DEV/wp-core-base/docs/release-process.md)

## Core Mental Model

Treat the manifest at `.wp-core-base/manifest.php` as the downstream source of truth.

Treat `.wp-core-base/framework.php` as the installed framework lock file.

Every runtime path should be understood as one of:

- `managed`: the framework may update it from a trusted upstream archive
- `local`: the downstream project owns it directly; the framework must not overwrite it
- `ignored`: intentionally outside staging and update scope

The framework has separate contracts for:

- managed dependency sanitation during update ingestion
- local strip-on-stage behavior during staged-clean runtime assembly
- runtime staging for deployment

Do not blur those contracts when reasoning about the system.

## Safe Assumptions

- `local` is a first-class long-term ownership model.
- `managed` means overwrite-by-automation is acceptable.
- `ignored` means documented but out of scope.
- `full-core` and `content-only` are both first-class.
- GitHub is required only for automated PR flows, not for using the code base itself.
- `stage-runtime` is the deployment contract whenever staged runtime is part of the architecture.
- `framework-sync` updates the vendored `wp-core-base` framework snapshot, not the runtime manifest.

## Unsafe Assumptions

Do not assume:

- every plugin in a repo should be `managed`
- every downstream keeps WordPress core in Git
- Composer is the dependency source of truth
- raw Git working trees are valid managed dependency inputs
- symlinks are acceptable runtime inputs
- a GitHub repository without GitHub Releases is a supported `github-release` source

## How To Evaluate An Existing WordPress Repo

1. Determine whether the repo is `full-core` or `content-only`.
2. Identify the deployment model:
   - mutable server checkout
   - FTP/manual deployment
   - CI-built artifact
   - Docker/image-first
3. Inventory runtime roots:
   - plugins
   - themes
   - MU plugins
   - extra content-root runtime directories
4. Classify each runtime path:
   - managed third-party dependency
   - local project-owned code
   - ignored path
5. Identify dependency sources:
   - WordPress.org
   - GitHub Releases
   - unsupported or manual source types
6. Check whether GitHub is available for PR automation.
7. Recommend:
   - profile
   - framework consumption mode
   - scaffold preset
   - manifest mode
   - validation mode
   - migration order

Use [docs/evaluation-guide.md](/Users/matthias/DEV/wp-core-base/docs/evaluation-guide.md) for the full checklist.

## How To Argue For Or Against Adoption

When assessing the framework, focus on:

- does the repo benefit from Git-reviewed dependency updates?
- is explicit runtime ownership useful here?
- does the team need deterministic runtime staging?
- can the project accept the managed/local/ignored ownership model?
- are the dependency sources mostly supported?
- is GitHub available if automated PRs are desired?

Use [docs/support-matrix.md](/Users/matthias/DEV/wp-core-base/docs/support-matrix.md) and [docs/faq.md](/Users/matthias/DEV/wp-core-base/docs/faq.md) to address common objections.

## Common Agent Errors To Avoid

- proposing `managed` for custom project-owned plugins or themes without justification
- recommending update automation for unsupported source types as if it already exists
- treating `relaxed` manifest mode as the ideal steady state instead of a migration aid
- suggesting deployment directly from the raw working tree when staged runtime is part of the contract
- ignoring blocked PR behavior when evaluating operational impact
- ignoring the pinned framework version in `.wp-core-base/framework.php`

## Preferred Outputs For Agent Evaluations

When evaluating a downstream repo, produce:

1. repository profile recommendation
2. deployment model summary
3. dependency source inventory
4. ownership classification proposal
5. supported vs unsupported areas
6. migration risks
7. recommended next steps

That keeps evaluation grounded in the actual framework contract.
