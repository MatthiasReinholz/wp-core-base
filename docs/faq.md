# FAQ

This document answers common adoption questions and common objections.

## Do I need GitHub to use `wp-core-base`?

No.

You need GitHub only if you want the scheduled pull-request automation. The code base, manifest model, and runtime staging approach are still usable without GitHub.

## Do I need to commit WordPress core into my repo?

No.

Use:

- `full-core` if your repo should contain WordPress core
- `content-only` if core should stay external

## Do I need to use Composer?

No.

Composer is not the source of truth for this framework.

## Do I need to move all my custom code into framework-managed dependencies?

No.

Custom project-owned plugins, themes, MU plugins, runtime files, and runtime directories can stay `local`.

That is a normal long-term model.

## Why not just use WordPress auto-updates?

Because the framework is optimized for Git-reviewed change management.

It gives you:

- reviewable PRs
- explicit release context
- runtime validation
- a deterministic staged runtime payload
- a clear managed versus local ownership model

## Why separate `managed` and `local`?

Because they represent different ownership contracts.

- `managed` means overwrite-by-automation is acceptable
- `local` means the downstream project owns the code and automation must not replace it

Mixing them would make updates riskier and the system harder to reason about.

## Can I keep using FTP or SFTP deployment?

Yes.

GitHub automation and deployment are separate concerns. You can still deploy by FTP, SFTP, rsync, or manual upload.

## Can I use this with Docker or immutable images?

Yes.

That is one of the strongest fits for the framework. Use `stage-runtime` and build from the staged payload instead of the raw working tree.

## What happens if multiple plugins have updates at the same time?

The framework opens one PR per dependency.

That keeps review focused and avoids unrelated dependency changes getting bundled together.

## What happens if a plugin PR stays open and a newer version is released?

If the new version is a patch release on the same release line, the framework updates the existing PR.

If the new version is a newer minor or major release, the framework opens a separate PR and can block it behind the older one.

## What information is included in update PRs?

For dependency PRs, the framework can include:

- release scope
- release timestamp
- release notes or changelog context
- support topics opened after release for WordPress.org plugins
- labels that classify patch/minor/major and bugfix/feature signals

Framework update PRs also include:

- current and target `wp-core-base` version
- bundled WordPress baseline before and after the framework update
- parsed framework release-note sections
- any scaffolded workflow files that were skipped because they were locally customized

## How are framework updates different from plugin or core updates?

Framework updates operate on the vendored `wp-core-base` snapshot and `.wp-core-base/framework.php`.

They do not rewrite `.wp-core-base/manifest.php`, and they do not directly change runtime dependency ownership. They refresh the framework tooling layer that downstream automation runs through.

## What if my repo already ignores `/vendor/`?

Keep the ignore narrow.

If `wp-core-base` is installed at `vendor/wp-core-base`, prefer:

```gitignore
/vendor/*
!/vendor/wp-core-base
!/vendor/wp-core-base/**
```

That keeps framework self-update PRs reviewable and commit-safe without accidentally making the rest of `vendor/` repo-owned.

## Do managed dependencies have to arrive perfectly runtime-clean?

Ideally, yes.

Practically, the framework now supports managed sanitation for predictable non-runtime files in accepted release archives.

That is a normalization layer, not a substitute for proper release packaging discipline.

## Can I keep tests, docs, and build files in my custom local plugin or theme source tree?

Yes, if your runtime contract uses `staged-clean` validation and strip-on-stage rules for `local` code.

## Does the framework support every plugin source type?

No.

Check [support-matrix.md](/Users/matthias/DEV/wp-core-base/docs/support-matrix.md) for the exact supported and unsupported cases.

## Are premium plugins supported in workflow updates?

Selected premium plugin sources are supported.

Today that includes:

- ACF PRO through `acf-pro`
- User Role Editor Pro through `role-editor-pro`
- Freemius-backed premium plugins through `freemius-premium`

Those sources use one fixed local or GitHub Actions secret:

- `WP_CORE_BASE_PREMIUM_CREDENTIALS_JSON`

WooCommerce.com extensions are still outside the native workflow-managed source contract in this phase.

## Why does wp-admin say a plugin is managed by workflows?

Because scaffolded downstreams now include a framework-managed governance MU plugin.

For workflow-managed plugins, the framework suppresses the normal WordPress update affordances that would suggest “update this plugin in the dashboard” even though the intended contract is “update this plugin through Git-reviewed automation PRs”.

That keeps the admin UI aligned with the real ownership model instead of showing conflicting update paths.

## Can I point managed dependencies at raw Git working trees, symlinks, or submodules?

No. That is not the intended managed-dependency model.

Managed dependencies should come from release-backed archives, not live Git working trees.

The same principle applies to automated framework self-updates. The supported install/update path is a vendored release snapshot, not a live submodule update flow.

## Is `relaxed` manifest mode the recommended steady state?

No.

`relaxed` is a migration aid. `strict` is the intended long-term mode once the runtime ownership model is explicit.

## What if my project has runtime assets outside plugins, themes, and MU plugins?

Use:

- `runtime-file`
- `runtime-directory`
- extra `runtime.ownership_roots`

That lets the framework model content-root runtime assets explicitly.

## What if I want an AI agent to evaluate adoption for my repo?

Start the agent with:

- [AGENTS.md](/Users/matthias/DEV/wp-core-base/AGENTS.md)
- [concepts.md](/Users/matthias/DEV/wp-core-base/docs/concepts.md)
- [evaluation-guide.md](/Users/matthias/DEV/wp-core-base/docs/evaluation-guide.md)
- [support-matrix.md](/Users/matthias/DEV/wp-core-base/docs/support-matrix.md)

That gives it the framework vocabulary, support boundaries, and evaluation order.
