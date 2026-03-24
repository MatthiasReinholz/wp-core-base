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

Remove a dependency:

```bash
__WPORG_WRAPPER_PATH__ remove-dependency --repo-root=. --kind=plugin --source=wordpress.org --slug=woocommerce
```

Interactive mode:

```bash
__WPORG_WRAPPER_PATH__ add-dependency --repo-root=. --interactive
```

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
- AI/agent guidance: `__WPORG_TOOL_ROOT__/AGENTS.md`
