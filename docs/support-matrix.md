# Support Matrix

This document lists what `wp-core-base` supports today, what it supports with constraints, and what remains outside the current automation scope.

## Repository Profiles

| Area | Status | Notes |
| --- | --- | --- |
| `full-core` downstreams | Supported | WordPress core is committed in the downstream repo. |
| `content-only` downstreams | Supported | WordPress core is external to the downstream repo. |
| image-first downstreams | Supported | `stage-runtime` is the intended build contract. |

## Deployment Models

| Area | Status | Notes |
| --- | --- | --- |
| GitHub + CI/CD | Supported | Best fit for full automation. |
| GitHub + FTP/SFTP deployment | Supported | GitHub handles PR automation; FTP handles delivery. |
| GitHub + manual deployment | Supported | Review/update flow still works. |
| no GitHub, manual usage | Supported with constraints | Base/repo model works; automated PRs do not. |
| vendored `wp-core-base` self-update PRs | Supported | Uses `.wp-core-base/framework.php` plus GitHub Releases. |

## Runtime Ownership

| Area | Status | Notes |
| --- | --- | --- |
| `managed` dependencies | Supported | Updater may overwrite them. |
| `local` project-owned runtime code | Supported | First-class long-term model. |
| `ignored` paths | Supported | Explicitly out of staging and update scope. |
| strict manifest ownership | Supported | Recommended steady state. |
| relaxed manifest ownership | Supported with constraints | Migration aid, not ideal end state. |

## Runtime Kinds

| Area | Status | Notes |
| --- | --- | --- |
| `plugin` | Supported | May be managed or local. |
| `theme` | Supported | May be managed or local. |
| `mu-plugin-package` | Supported | Managed or local depending on source/support. |
| `mu-plugin-file` | Supported | Usually local. |
| `runtime-file` | Supported | Usually local. |
| `runtime-directory` | Supported with constraints | Local or ignored today; not updater-managed. |

## Managed Dependency Sources

| Source | Status | Notes |
| --- | --- | --- |
| WordPress.org plugins | Supported | First-class automated source. |
| WordPress.org themes | Supported | First-class automated source. |
| public GitHub Releases | Supported | Requires stable Releases. |
| private GitHub Releases | Supported with constraints | Requires token env and release-backed artifacts. |
| downstream-registered premium providers | Supported with constraints | Uses `source: premium`, a provider registered in `.wp-core-base/premium-providers.php`, and `WP_CORE_BASE_PREMIUM_CREDENTIALS_JSON`. |
| raw GitHub tags without Releases | Not supported | GitHub Releases are the source of truth. |
| WooCommerce.com | Not supported | No native automation path today. |
| Composer as managed source | Not supported | Can coexist downstream, but not as native updater source. |
| manual vendoring / copy-paste as managed source | Not supported | Manual code can exist, but not as automated managed source. |
| symlinked runtime dependencies | Not supported | Runtime hygiene rejects symlinks. |
| raw Git working trees as managed source | Not supported | Managed dependencies are archive-based. |

## Update PR Behavior

| Behavior | Status | Notes |
| --- | --- | --- |
| one PR per dependency | Supported | Current operating model. |
| patch release refreshes existing PR | Supported | Same release line. |
| later minor/major opens separate blocked PR | Supported | Requires blocker workflow. |
| support-topic refresh for WordPress.org plugins | Supported | PR body can refresh with newer signals. |
| framework self-update PRs | Supported | One PR per framework release line, using vendored snapshots. |
| combined multi-plugin PRs | Not supported | Intentionally one dependency per PR. |

## Runtime Cleanliness

| Area | Status | Notes |
| --- | --- | --- |
| source-clean validation | Supported | Repo paths must already be deployable. |
| staged-clean validation for local code | Supported | Strip-on-stage allowed for `local`. |
| managed sanitation during sync | Supported | Normalizes accepted upstream archives before commit/checksum. |
| strip-on-stage for managed code as the main model | Not recommended | Managed artifacts should ideally be runtime-ready. |
| admin governance MU plugin for workflow-managed plugins | Supported | Scaffolding writes a framework-managed loader plus generated governance data. |

## What To Tell Evaluators

If an evaluator asks whether the framework can be adopted, the main questions are:

1. Is the repo best modeled as `full-core` or `content-only`?
2. Are the important managed dependency sources supported today?
3. Can the project accept explicit `managed` versus `local` ownership?
4. Does the team want Git-reviewed update PRs?
5. Should deployment come from a staged runtime payload?

If the answer to most of those is yes, the framework is usually a strong fit.
