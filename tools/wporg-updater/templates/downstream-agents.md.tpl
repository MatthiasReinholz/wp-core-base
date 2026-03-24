# AGENTS.md

This repository uses `wp-core-base` as vendored tooling for dependency management, runtime validation, and framework self-updates.

## Start Here

Read these local files first:

1. `.wp-core-base/USAGE.md`
2. `.wp-core-base/manifest.php`
3. `.wp-core-base/framework.php`

If you need deeper framework rules, then read:

1. `__WPORG_TOOL_ROOT__/AGENTS.md`
2. `__WPORG_TOOL_ROOT__/docs/managing-dependencies.md`
3. `__WPORG_TOOL_ROOT__/docs/downstream-usage.md`

## Dependency Changes

For routine plugin, theme, MU plugin, runtime-file, or runtime-directory changes:

- prefer `__WPORG_WRAPPER_PATH__ add-dependency ...`
- prefer `__WPORG_WRAPPER_PATH__ remove-dependency ...`
- prefer `__WPORG_WRAPPER_PATH__ list-dependencies --repo-root=.`

Do not start by hand-editing `.wp-core-base/manifest.php` unless the change is unusual or clearly advanced.

## Source Of Truth

- `.wp-core-base/manifest.php` is the downstream runtime/dependency source of truth
- `.wp-core-base/framework.php` is the installed framework lock and managed-file metadata

## Ownership Model

Every runtime path should be treated as one of:

- `managed`: the framework may overwrite it from a trusted upstream archive
- `local`: the project owns it directly; the framework must not overwrite it
- `ignored`: intentionally outside staging/update scope

`local` is normal and first-class. It is not a temporary migration state.

## Validation Commands

Preferred checks:

```bash
__WPORG_PHP_PATH__ doctor --repo-root=. --github
__WPORG_PHP_PATH__ stage-runtime --repo-root=. --output=.wp-core-base/build/runtime
```

## Automation Surface

This repo may include scaffolded workflows such as:

- scheduled/manual updates
- merged-PR reconciliation
- blocker evaluation
- runtime validation
- framework self-update

Those workflows consume the manifest. They are not the primary authoring interface for dependency changes.
