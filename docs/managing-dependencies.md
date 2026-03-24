# Managing Dependencies

Use `.wp-core-base/manifest.php` as the source of truth, but prefer the CLI for routine authoring.

Normal workflow:

- use `add-dependency` to create new manifest entries
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

## Add A Private GitHub Release Plugin

```bash
vendor/wp-core-base/bin/wp-core-base add-dependency \
  --repo-root=. \
  --source=github-release \
  --kind=plugin \
  --slug=private-plugin \
  --github-repository=owner/private-plugin \
  --private
```

If you do not pass `--github-token-env`, the CLI generates a default env var name such as:

- `WP_CORE_BASE_GITHUB_TOKEN_PRIVATE_PLUGIN`

You can override that with:

```bash
--github-token-env=PRIVATE_PLUGIN_GITHUB_TOKEN
```

The manifest stores only the env var name, never the token value.

Local shell example:

```bash
export WP_CORE_BASE_GITHUB_TOKEN_PRIVATE_PLUGIN=...
```

GitHub Actions setup:

- create a repository secret with the same name as the configured env var

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

## Remove An Entry

Manifest-only removal:

```bash
vendor/wp-core-base/bin/wp-core-base remove-dependency \
  --repo-root=. \
  --slug=project-plugin \
  --kind=plugin
```

Remove the manifest entry and delete the runtime path:

```bash
vendor/wp-core-base/bin/wp-core-base remove-dependency \
  --repo-root=. \
  --slug=project-plugin \
  --kind=plugin \
  --delete-path
```

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
