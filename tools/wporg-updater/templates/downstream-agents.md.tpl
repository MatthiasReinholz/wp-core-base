# AGENTS.md

This repository uses `wp-core-base` as vendored tooling for dependency management, runtime validation, and framework self-updates.

## Start Here

Read these local files first:

1. `.wp-core-base/USAGE.md`
2. `.wp-core-base/manifest.php`
3. `.wp-core-base/framework.php`
4. `.wp-core-base/premium-providers.php`

If you need deeper framework rules, then read:

1. `__WPORG_TOOL_ROOT__/AGENTS.md`
2. `__WPORG_TOOL_ROOT__/docs/managing-dependencies.md`
3. `__WPORG_TOOL_ROOT__/docs/downstream-usage.md`
4. `__WPORG_TOOL_ROOT__/docs/adding-premium-provider.md`

## Dependency Changes

For routine plugin, theme, MU plugin, runtime-file, or runtime-directory changes:

- prefer `__WPORG_WRAPPER_PATH__ add-dependency ...`
- prefer `__WPORG_WRAPPER_PATH__ remove-dependency ...`
- prefer `__WPORG_WRAPPER_PATH__ list-dependencies --repo-root=.`

Do not start by hand-editing `.wp-core-base/manifest.php` unless the change is unusual or clearly advanced.

## Hosted Release Trust Checks

If a task involves a managed `github-release` or `gitlab-release` dependency and you want stronger download-time trust checks:

1. inspect the real upstream hosted Release assets first
2. confirm the ZIP asset name or stable glob
3. confirm whether a matching checksum sidecar asset exists
4. only then edit `.wp-core-base/manifest.php` to set:
   - `source_config.github_release_asset_pattern` or `source_config.gitlab_release_asset_pattern`
   - `source_config.checksum_asset_pattern`
   - `source_config.verification_mode`
   - optionally `source_config.min_release_age_hours`
5. if several hosted release dependencies should share the same default posture, prefer repo-level:
   - `security.github_release_verification`
   - `security.managed_release_min_age_hours`
6. run `__WPORG_PHP_PATH__ doctor --repo-root=.`
7. run `__WPORG_PHP_PATH__ sync`

Use `checksum-sidecar-required` only when the checksum asset really exists and binds the digest to the ZIP filename. Do not guess checksum patterns from tag names alone.

## Premium Plugin Workflow

No premium vendor is built into `wp-core-base`.

If a task involves a premium plugin source:

1. check whether `.wp-core-base/premium-providers.php` already registers a matching provider
2. if a matching provider already exists, reuse it instead of inventing a second provider for the same upstream contract
3. if no matching provider exists, scaffold one with `__WPORG_WRAPPER_PATH__ scaffold-premium-provider --repo-root=. --provider=your-provider`
4. implement the provider class in `.wp-core-base/premium-providers/your-provider.php`
5. configure `WP_CORE_BASE_PREMIUM_CREDENTIALS_JSON` locally or as a CI/CD secret
6. run `__WPORG_PHP_PATH__ doctor --repo-root=. --automation`
7. if the plugin already exists in the repo as a `local` dependency, use `adopt-dependency`; otherwise use `add-dependency`
8. run `__WPORG_PHP_PATH__ stage-runtime --repo-root=. --output=.wp-core-base/build/runtime`

For agents, prefer non-interactive commands with explicit flags. Use interactive mode only when a human explicitly asks for guided prompts.

When implementing a premium provider, do not guess the contract. Read:

1. `.wp-core-base/premium-providers.php`
2. the generated provider class file
3. `__WPORG_TOOL_ROOT__/docs/adding-premium-provider.md`

The provider class must:

- return `latest_version` and `latest_release_at` from `fetchCatalog()`
- return `version` and `release_at` from `releaseDataForVersion()`
- write the ZIP archive to the destination path in `downloadReleaseToFile()`
- keep secrets only in `WP_CORE_BASE_PREMIUM_CREDENTIALS_JSON`, never in the manifest

Credential lookup rules:

- by default, credentials are looked up by the dependency component key, for example `plugin:premium:premium-plugin`
- if the manifest sets `source_config.credential_key`, that override is used instead

Minimal credentials JSON example:

```json
{
  "plugin:premium:premium-plugin": {
    "license_key": "provider-specific-secret"
  }
}
```

If multiple premium dependencies share one account or license, set `--credential-key=...` when authoring the dependency and use that same string as the JSON lookup key.

If a premium source cannot be implemented through a deterministic HTTP contract, do not fake support for it. Keep that plugin `local` or otherwise outside managed premium automation.

## Source Of Truth

- `.wp-core-base/manifest.php` is the downstream runtime/dependency source of truth
- `.wp-core-base/framework.php` is the installed framework lock and managed-file metadata
- `.wp-core-base/premium-providers.php` registers downstream-owned premium source adapters

## Ownership Model

Every runtime path should be treated as one of:

- `managed`: the framework may overwrite it from a trusted upstream archive
- `local`: the project owns it directly; the framework must not overwrite it
- `ignored`: intentionally outside staging/update scope

`local` is normal and first-class. It is not a temporary migration state.

## Validation Commands

Preferred checks:

```bash
__WPORG_PHP_PATH__ doctor --repo-root=. --automation
__WPORG_PHP_PATH__ stage-runtime --repo-root=. --output=.wp-core-base/build/runtime
```

If a workflow or coding agent needs stable machine-readable output, prefer:

```bash
__WPORG_PHP_PATH__ doctor --repo-root=. --json
__WPORG_PHP_PATH__ doctor --repo-root=. --automation --json
__WPORG_PHP_PATH__ stage-runtime --repo-root=. --output=.wp-core-base/build/runtime --json
__WPORG_WRAPPER_PATH__ add-dependency --repo-root=. --source=wordpress.org --kind=plugin --slug=example-plugin --plan --json
__WPORG_PHP_PATH__ release-verify --repo-root=. --json
```

## Automation Surface

This repo may include scaffolded workflows such as:

- scheduled/manual updates
- merged-PR reconciliation
- blocker evaluation
- runtime validation
- framework self-update

Those workflows consume the manifest. They are not the primary authoring interface for dependency changes.
