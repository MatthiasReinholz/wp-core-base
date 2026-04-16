# Managing Dependencies

Use `.wp-core-base/manifest.php` as the source of truth, but prefer the CLI for routine authoring.

Normal workflow:

- use `add-dependency` to create new manifest entries
- use `adopt-dependency` to convert an existing `local` entry into a managed entry safely
- use `remove-dependency` to remove entries
- use `list-dependencies` to inspect current state
- edit the manifest manually only for advanced policy or path changes

If PHP is not installed locally, see [local-prerequisites.md](local-prerequisites.md).

## Recommended Entry Points

If `wp-core-base` is the current repository:

```bash
bin/wp-core-base add-dependency --source=local --kind=plugin --path=wp-content/plugins/project-plugin
```

If `wp-core-base` is vendored into a downstream repository:

```bash
vendor/wp-core-base/bin/wp-core-base add-dependency --repo-root=. --source=local --kind=plugin --path=cms/plugins/project-plugin
```

The PHP CLI remains fully supported:

```bash
php vendor/wp-core-base/tools/wporg-updater/bin/wporg-updater.php add-dependency --repo-root=. --source=local --kind=plugin --path=cms/plugins/project-plugin
```

For mode-specific help, prefer:

```bash
vendor/wp-core-base/bin/wp-core-base help add-dependency
vendor/wp-core-base/bin/wp-core-base help adopt-dependency
vendor/wp-core-base/bin/wp-core-base help remove-dependency
```

## Add A WordPress.org Plugin

```bash
vendor/wp-core-base/bin/wp-core-base add-dependency \
  --repo-root=. \
  --source=wordpress.org \
  --kind=plugin \
  --slug=woocommerce
```

This will:

- fetch WordPress.org metadata
- install the selected runtime snapshot into the configured plugins root
- infer `name` and `main_file`
- sanitize and checksum the installed tree
- write the manifest entry

Preview the resolved version, archive path, destination path, and sanitation rules without mutating the repo:

```bash
vendor/wp-core-base/bin/wp-core-base add-dependency \
  --repo-root=. \
  --source=wordpress.org \
  --kind=plugin \
  --slug=woocommerce \
  --plan
```

Pin to the currently installed version during adoption or migration by passing `--version=...`.

## Add A GitHub Release Plugin

```bash
vendor/wp-core-base/bin/wp-core-base add-dependency \
  --repo-root=. \
  --source=github-release \
  --kind=plugin \
  --slug=example-plugin \
  --github-repository=owner/example-plugin
```

Optional flags:

- `--version=1.2.3`
- `--archive-subdir=plugin`
- `--github-release-asset-pattern=*.zip`
- `--private`
- `--plan`, `--preview`, or `--dry-run`

Use `--archive-subdir` only when the extracted payload is not resolved correctly by default. Standard WordPress.org plugin ZIPs should not need it.

The authoring locator first checks the archive root and then each direct child directory. For directory payloads it prefers a candidate whose basename matches the slug and whose resolved main file is shallower; for file payloads it prefers a basename match. Use `--archive-subdir` when the upstream archive wraps the real payload in one stable subdirectory and the default locator would otherwise pick the wrong layer.

If the upstream project also publishes a checksum sidecar for the ZIP, add the matching manifest fields after creation:

- `source_config.checksum_asset_pattern`
- `source_config.verification_mode`
- optionally `source_config.min_release_age_hours`

Recommended hardened setup:

- `github_release_asset_pattern`: the real ZIP asset
- `checksum_asset_pattern`: the matching `.sha256` asset
- `verification_mode: checksum-sidecar-required`

That makes `sync` verify the downloaded archive before extraction. If you prefer a repo-wide default, set `security.github_release_verification` and `security.managed_release_min_age_hours` in the manifest instead.

### Agent-ready GitHub release hardening workflow

If an AI coding agent is upgrading a downstream repo to use GitHub release trust checks, it should follow this order exactly:

1. inspect the real upstream GitHub Release assets
2. confirm the ZIP asset name or stable glob
3. confirm whether a matching checksum sidecar asset exists
4. add the dependency normally if it does not exist yet
5. edit `.wp-core-base/manifest.php` only after the asset names are confirmed
6. set:
   - `source_config.github_release_asset_pattern`
   - `source_config.checksum_asset_pattern`
   - `source_config.verification_mode`
   - optionally `source_config.min_release_age_hours`
7. run `doctor --repo-root=.`
8. run `sync`
9. only keep `checksum-sidecar-required` if the upstream checksum file really binds the digest to the ZIP filename

Recommended per-dependency manifest shape:

```php
'source_config' => [
    'github_repository' => 'owner/example-plugin',
    'github_release_asset_pattern' => 'example-plugin-*.zip',
    'github_token_env' => null,
    'min_release_age_hours' => 24,
    'verification_mode' => 'checksum-sidecar-required',
    'checksum_asset_pattern' => 'example-plugin-*.zip.sha256',
    'credential_key' => null,
    'provider' => null,
    'provider_product_id' => null,
],
```

If the checksum sidecar does not exist upstream, do not guess. Leave verification at `none` or a repo-level optional mode instead.

## Add A Premium Plugin

Premium workflow updates use a downstream-registered provider adapter.

No premium vendor is built into `wp-core-base`.

First scaffold or register a provider in `.wp-core-base/premium-providers.php`.

The workflow credential contract is a single JSON env var or GitHub Actions secret:

- `WP_CORE_BASE_PREMIUM_CREDENTIALS_JSON`

The manifest stores only the dependency source and optional lookup keys. It never stores premium license keys.

### Scaffold a premium provider

```bash
vendor/wp-core-base/bin/wp-core-base scaffold-premium-provider \
  --repo-root=. \
  --provider=example-vendor
```

That creates:

- `.wp-core-base/premium-providers.php`
- `.wp-core-base/premium-providers/example-vendor.php`

The generated class extends `AbstractPremiumManagedSource`. Implement the provider-specific HTTP contract there.

### Agent-ready workflow for a custom premium source

If a downstream coding agent needs to make a premium plugin work, it should use this exact order:

1. inspect `.wp-core-base/premium-providers.php`
2. reuse an existing provider if it already matches the upstream contract
3. scaffold a provider only if one does not exist yet
4. implement the generated provider class
5. set `WP_CORE_BASE_PREMIUM_CREDENTIALS_JSON`
6. run `doctor --repo-root=. --github`
7. if the plugin already exists as a local dependency, use `adopt-dependency`; otherwise use `add-dependency`
8. run `stage-runtime`

That keeps provider setup, credential setup, manifest authoring, and runtime validation clearly separated.

For agents, prefer explicit non-interactive commands instead of `--interactive`.

### Add a premium plugin through a registered provider

```bash
vendor/wp-core-base/bin/wp-core-base add-dependency \
  --repo-root=. \
  --source=premium \
  --provider=example-vendor \
  --kind=plugin \
  --slug=premium-plugin
```

Credentials JSON entry:

```json
{
  "plugin:premium:example-vendor:premium-plugin": {
    "license_key": "provider-specific-secret"
  }
}
```

By default, the JSON object key is the dependency component key, for example `plugin:premium:example-vendor:premium-plugin`.
If the manifest uses `source_config.credential_key`, that override becomes the lookup key instead.

Example shared-license pattern:

```bash
vendor/wp-core-base/bin/wp-core-base add-dependency \
  --repo-root=. \
  --source=premium \
  --provider=example-vendor \
  --kind=plugin \
  --slug=premium-plugin \
  --credential-key=example-vendor:team-license \
  --provider-product-id=42
```

```json
{
  "example-vendor:team-license": {
    "license_key": "provider-specific-secret",
    "site_url": "https://example.com"
  }
}
```

If your provider class needs a stable product identifier, include `--provider-product-id=...` when you add or adopt the dependency and read it from `source_config.provider_product_id`.

The provider class contract is documented in [adding-premium-provider.md](/Users/matthias/DEV/wp-core-base/docs/adding-premium-provider.md), including:

- the required return shape of `fetchCatalog()`
- the required return shape of `releaseDataForVersion()`
- the expected behavior of `downloadReleaseToFile()`
- minimal credential validation patterns

### Local and GitHub setup for premium credentials

Local shell example:

```bash
export WP_CORE_BASE_PREMIUM_CREDENTIALS_JSON='{"plugin:premium:example-vendor:premium-plugin":{"license_key":"provider-specific-secret"}}'
```

GitHub Actions setup:

- create one repository secret named `WP_CORE_BASE_PREMIUM_CREDENTIALS_JSON`
- store the same JSON object there

Premium source failures remain per-dependency warnings during `sync`. A broken premium source does not stop healthy managed dependency updates from continuing.

If a premium source cannot be expressed through a deterministic HTTP contract that can resolve version metadata and download a ZIP archive in CI, keep it `local` instead of forcing it into managed premium automation.

## Add A Private GitHub Release Plugin

```bash
vendor/wp-core-base/bin/wp-core-base add-dependency \
  --repo-root=. \
  --source=github-release \
  --kind=plugin \
  --slug=private-plugin \
  --github-repository=owner/private-plugin
```

If the repository needs authentication and you do not pass `--github-token-env`, the CLI falls back to a generated default env var name such as:

- `WP_CORE_BASE_GITHUB_TOKEN_PRIVATE_PLUGIN`

You can override that with:

```bash
--github-token-env=PRIVATE_PLUGIN_GITHUB_TOKEN
```

The manifest stores only the env var name, never the token value.

You can still pass `--private` explicitly if you want to make that intent obvious up front, but it is no longer required for the default token-env naming flow.

Local shell example:

```bash
export WP_CORE_BASE_GITHUB_TOKEN_PRIVATE_PLUGIN=...
```

GitHub Actions setup:

- create a repository secret with the same name as the configured env var

Preview private GitHub adoption without mutating the repo:

```bash
vendor/wp-core-base/bin/wp-core-base add-dependency \
  --repo-root=. \
  --source=github-release \
  --kind=plugin \
  --slug=private-plugin \
  --github-repository=owner/private-plugin \
  --plan
```

## Add Local Project-Owned Code

Local entries are first-class. They are staged and validated, but never overwritten by `sync`.

### Local plugin

```bash
vendor/wp-core-base/bin/wp-core-base add-dependency \
  --repo-root=. \
  --source=local \
  --kind=plugin \
  --path=cms/plugins/project-plugin
```

### Local theme

```bash
vendor/wp-core-base/bin/wp-core-base add-dependency \
  --repo-root=. \
  --source=local \
  --kind=theme \
  --path=cms/themes/project-theme
```

### MU plugin package

```bash
vendor/wp-core-base/bin/wp-core-base add-dependency \
  --repo-root=. \
  --source=local \
  --kind=mu-plugin-package \
  --path=cms/mu-plugins/project-bootstrap
```

### MU plugin file

```bash
vendor/wp-core-base/bin/wp-core-base add-dependency \
  --repo-root=. \
  --source=local \
  --kind=mu-plugin-file \
  --path=cms/mu-plugins/bootstrap.php
```

### Runtime file

```bash
vendor/wp-core-base/bin/wp-core-base add-dependency \
  --repo-root=. \
  --source=local \
  --kind=runtime-file \
  --path=cms/object-cache.php
```

### Runtime directory

```bash
vendor/wp-core-base/bin/wp-core-base add-dependency \
  --repo-root=. \
  --source=local \
  --kind=runtime-directory \
  --path=cms/languages
```

## Adopt Local Code Into Managed Ownership

Use `adopt-dependency` when you already have a `local` plugin or theme in the repo and want to convert it into a managed upstream dependency.

This is the safe migration path because a single adoption is atomic:

- the existing runtime path is preserved
- the managed snapshot is prepared and sanitized first
- the manifest is only rewritten after the runtime tree has been replaced successfully
- if the adoption fails, the original local runtime tree is restored

### Adopt a local plugin into WordPress.org management

```bash
vendor/wp-core-base/bin/wp-core-base adopt-dependency \
  --repo-root=. \
  --kind=plugin \
  --slug=woocommerce \
  --source=wordpress.org \
  --preserve-version
```

### Adopt a local plugin into GitHub Release management

```bash
vendor/wp-core-base/bin/wp-core-base adopt-dependency \
  --repo-root=. \
  --kind=plugin \
  --slug=private-plugin \
  --source=github-release \
  --github-repository=owner/private-plugin \
  --preserve-version
```

### Adopt a local premium plugin into managed premium ownership

```bash
vendor/wp-core-base/bin/wp-core-base scaffold-premium-provider \
  --repo-root=. \
  --provider=example-vendor

vendor/wp-core-base/bin/wp-core-base adopt-dependency \
  --repo-root=. \
  --kind=plugin \
  --slug=premium-plugin \
  --source=premium \
  --provider=example-vendor \
  --preserve-version
```

### Preview an adoption before it changes anything

```bash
vendor/wp-core-base/bin/wp-core-base adopt-dependency \
  --repo-root=. \
  --kind=plugin \
  --slug=woocommerce \
  --source=wordpress.org \
  --preserve-version \
  --plan
```

Recommended migration pattern:

- use `--preserve-version` to keep the currently installed version instead of jumping to latest upstream
- use `--version=...` when you want an explicit upstream version
- use `--archive-subdir=...` only when an upstream archive layout requires it
- review the resulting manifest diff and staged runtime after each adoption

Important scope note:

- a single `adopt-dependency` run is atomic
- a batch of several separate commands is not transactional across invocations
- if you are migrating many entries, do them one by one and review each result

## Unsupported Premium Path In This Phase

WooCommerce.com extensions are still outside the native workflow-update contract in this phase.

Use them as:

- `local` dependencies
- or another manual/project-specific process

Do not model them as native managed sources yet.

## Remove An Entry

Manifest-only removal:

```bash
vendor/wp-core-base/bin/wp-core-base remove-dependency \
  --repo-root=. \
  --slug=project-plugin \
  --kind=plugin \
  --source=local
```

Remove the manifest entry and delete the runtime path:

```bash
vendor/wp-core-base/bin/wp-core-base remove-dependency \
  --repo-root=. \
  --slug=project-plugin \
  --kind=plugin \
  --source=local \
  --delete-path
```

If the same slug exists from multiple sources, prefer `--component-key` or add `--source` explicitly so removal stays unambiguous.

## List Current Entries

```bash
vendor/wp-core-base/bin/wp-core-base list-dependencies --repo-root=.
```

This prints configured dependencies grouped by:

- `managed`
- `local`
- `ignored`

## Interactive Mode

For humans working in a terminal, you can ask the CLI to prompt for missing inputs:

```bash
vendor/wp-core-base/bin/wp-core-base add-dependency --repo-root=. --interactive
```

Interactive mode is optional convenience. The non-interactive flags remain the canonical interface for scripts and AI agents.

## When To Edit The Manifest Manually

Use manual editing for things like:

- profile changes
- path-root changes
- strip or sanitation policy
- ownership roots
- unusual allowlists or advanced policy overrides

Use the CLI for routine entry creation and removal.
