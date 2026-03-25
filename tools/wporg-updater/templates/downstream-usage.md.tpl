# Downstream wp-core-base Usage

This repository uses `wp-core-base` as vendored tooling.

## Source Of Truth

- runtime and dependency ownership lives in `.wp-core-base/manifest.php`
- installed framework version metadata lives in `.wp-core-base/framework.php`
- routine dependency changes should use the CLI instead of hand-editing manifest arrays

## Preferred CLI

If `wp-core-base` is vendored in this repo, use:

```bash
__WPORG_WRAPPER_PATH__ list-dependencies --repo-root=.
```

If the shell wrapper is not executable in your environment, the PHP entrypoint is:

```bash
__WPORG_PHP_PATH__ list-dependencies --repo-root=.
```

## Common Tasks

List declared dependencies:

```bash
__WPORG_WRAPPER_PATH__ list-dependencies --repo-root=.
```

Add a WordPress.org plugin:

```bash
__WPORG_WRAPPER_PATH__ add-dependency --repo-root=. --source=wordpress.org --kind=plugin --slug=woocommerce
```

Add a local custom plugin:

```bash
__WPORG_WRAPPER_PATH__ add-dependency --repo-root=. --source=local --kind=plugin --path=__CONTENT_ROOT__/plugins/project-plugin
```

Add a local MU plugin file:

```bash
__WPORG_WRAPPER_PATH__ add-dependency --repo-root=. --source=local --kind=mu-plugin-file --path=__CONTENT_ROOT__/mu-plugins/bootstrap.php
```

Add a runtime directory:

```bash
__WPORG_WRAPPER_PATH__ add-dependency --repo-root=. --source=local --kind=runtime-directory --path=__CONTENT_ROOT__/languages
```

Add a GitHub Release plugin:

```bash
__WPORG_WRAPPER_PATH__ add-dependency --repo-root=. --source=github-release --kind=plugin --slug=private-plugin --github-repository=owner/private-plugin
```

Scaffold a custom premium provider:

```bash
__WPORG_WRAPPER_PATH__ scaffold-premium-provider --repo-root=. --provider=example-vendor
```

Add a premium plugin through a registered provider:

```bash
__WPORG_WRAPPER_PATH__ add-dependency --repo-root=. --source=premium --provider=example-vendor --kind=plugin --slug=premium-plugin
```

Remove a dependency:

```bash
__WPORG_WRAPPER_PATH__ remove-dependency --repo-root=. --kind=plugin --source=wordpress.org --slug=woocommerce
```

Interactive mode:

```bash
__WPORG_WRAPPER_PATH__ add-dependency --repo-root=. --interactive
```

## Custom Premium Provider Workflow

`wp-core-base` does not ship built-in premium vendor adapters.

If this repo needs premium workflow updates, use this exact order:

1. inspect `.wp-core-base/premium-providers.php`
2. if a matching provider already exists, reuse it
3. if the provider is missing, scaffold one:

```bash
__WPORG_WRAPPER_PATH__ scaffold-premium-provider --repo-root=. --provider=example-vendor
```

4. implement the provider class in `.wp-core-base/premium-providers/example-vendor.php`
5. set `WP_CORE_BASE_PREMIUM_CREDENTIALS_JSON` locally or as a GitHub repository secret
6. verify the provider and credentials:

```bash
__WPORG_PHP_PATH__ doctor --repo-root=. --github
```

7. if the plugin already exists as a local dependency, adopt it; otherwise add it through that provider:

```bash
__WPORG_WRAPPER_PATH__ add-dependency --repo-root=. --source=premium --provider=example-vendor --kind=plugin --slug=premium-plugin
```

```bash
__WPORG_WRAPPER_PATH__ adopt-dependency --repo-root=. --source=premium --provider=example-vendor --kind=plugin --slug=premium-plugin --preserve-version
```

8. validate the staged runtime:

```bash
__WPORG_PHP_PATH__ stage-runtime --repo-root=. --output=.wp-core-base/build/runtime
```

For agents, prefer explicit non-interactive commands. Use interactive mode only when a human explicitly wants prompts.

The provider class must implement these contracts:

- `fetchCatalog()` returns at least `latest_version` and `latest_release_at`
- `releaseDataForVersion()` returns at least `version` and `release_at`
- `downloadReleaseToFile()` writes the ZIP archive to the destination path

The normal downstream pattern is to keep the provider class file in `.wp-core-base/premium-providers/` and register it with a `path` entry. It does not need Composer autoloading in that case.

Store secrets only in `WP_CORE_BASE_PREMIUM_CREDENTIALS_JSON`.
Do not put license keys, bearer tokens, or signed URLs into `.wp-core-base/manifest.php`.

Credential lookup rules:

- by default, the JSON object key is the dependency component key, for example `plugin:premium:premium-plugin`
- if the manifest sets `source_config.credential_key`, that override becomes the JSON lookup key

Minimal credentials JSON example:

```json
{
  "plugin:premium:premium-plugin": {
    "license_key": "provider-specific-secret"
  }
}
```

Shared-license example with an explicit credential key:

```bash
__WPORG_WRAPPER_PATH__ add-dependency --repo-root=. --source=premium --provider=example-vendor --kind=plugin --slug=premium-plugin --credential-key=example-vendor:team-license --provider-product-id=42
```

```json
{
  "example-vendor:team-license": {
    "license_key": "provider-specific-secret",
    "site_url": "https://example.com"
  }
}
```

If a premium source does not expose a deterministic HTTP contract that can fetch version metadata and a ZIP archive in CI, do not model it as `managed premium`. Keep it `local` or otherwise outside automated premium updates.

## Validation

Check the repo configuration:

```bash
__WPORG_PHP_PATH__ doctor --repo-root=. --github
```

Build the staged runtime payload:

```bash
__WPORG_PHP_PATH__ stage-runtime --repo-root=. --output=.wp-core-base/build/runtime
```

## GitHub Release Dependencies

Private GitHub dependencies store only the token environment variable name in the manifest.

If the CLI needs auth, it can generate a default token env name and tell you what to set:

- locally: export the token in your shell
- in GitHub Actions: add the same name as a repository secret

## More Detail

- framework usage and architecture: `__WPORG_TOOL_ROOT__/docs/downstream-usage.md`
- dependency authoring details: `__WPORG_TOOL_ROOT__/docs/managing-dependencies.md`
- custom premium provider setup: `__WPORG_TOOL_ROOT__/docs/adding-premium-provider.md`
- AI/agent guidance: `__WPORG_TOOL_ROOT__/AGENTS.md`
