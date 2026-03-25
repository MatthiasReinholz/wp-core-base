<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

final class CommandHelp
{
    public static function render(?string $topic, string $commandPrefix, string $phpCommandPrefix): string
    {
        return match ($topic) {
            null, '', 'general' => self::general($commandPrefix, $phpCommandPrefix),
            'add-dependency' => self::addDependency($commandPrefix),
            'adopt-dependency' => self::adoptDependency($commandPrefix),
            'remove-dependency' => self::removeDependency($commandPrefix),
            'sync' => self::sync($phpCommandPrefix),
            default => self::general($commandPrefix, $phpCommandPrefix),
        };
    }

    private static function general(string $commandPrefix, string $phpCommandPrefix): string
    {
        return <<<TEXT
Usage:
  {$commandPrefix} add-dependency --source=local --kind=plugin --path=wp-content/plugins/project-plugin
  {$commandPrefix} adopt-dependency --kind=plugin --slug=woocommerce --source=wordpress.org --preserve-version
  {$phpCommandPrefix} sync
  {$phpCommandPrefix} doctor [--repo-root=/path] [--github]
  {$phpCommandPrefix} stage-runtime [--repo-root=/path] [--output=.wp-core-base/build/runtime]
  {$phpCommandPrefix} refresh-admin-governance [--repo-root=/path]
  {$phpCommandPrefix} scaffold-downstream [--repo-root=/path] [--tool-path=vendor/wp-core-base] [--profile=content-only-default] [--content-root=cms] [--force]
  {$phpCommandPrefix} framework-sync [--repo-root=/path] [--check-only]
  {$phpCommandPrefix} prepare-framework-release [--repo-root=/path] --release-type=patch|minor|major|custom [--version=v1.0.1]
  {$phpCommandPrefix} release-verify [--repo-root=/path] [--tag=v1.0.0]
  {$phpCommandPrefix} suggest-manifest [--repo-root=/path]
  {$phpCommandPrefix} format-manifest [--repo-root=/path]
  {$phpCommandPrefix} add-dependency [--repo-root=/path] --source=... --kind=... [--slug=...] [--path=...]
  {$phpCommandPrefix} adopt-dependency [--repo-root=/path] --source=wordpress.org|github-release|acf-pro|role-editor-pro|freemius-premium --kind=... --slug=... [--preserve-version]
  {$phpCommandPrefix} remove-dependency [--repo-root=/path] [--component-key=...] [--slug=...] [--kind=...] [--source=...] [--delete-path]
  {$phpCommandPrefix} list-dependencies [--repo-root=/path]
  {$phpCommandPrefix} pr-blocker

Use:
  {$commandPrefix} help add-dependency
  {$commandPrefix} help adopt-dependency
  {$commandPrefix} help remove-dependency
  {$commandPrefix} help sync

TEXT;
    }

    private static function addDependency(string $commandPrefix): string
    {
        return <<<TEXT
add-dependency

Purpose:
  Add a managed, local, or ignored dependency entry to .wp-core-base/manifest.php.

Common flags:
  --repo-root=PATH
  --source=wordpress.org|github-release|acf-pro|role-editor-pro|freemius-premium|local
  --kind=plugin|theme|mu-plugin-package|mu-plugin-file|runtime-file|runtime-directory
  --slug=SLUG
  --path=PATH
  --main-file=FILE
  --version=VERSION
  --archive-subdir=PATH
  --github-repository=OWNER/REPO
  --github-release-asset-pattern=PATTERN
  --github-token-env=ENV_NAME
  --credential-key=LOOKUP_KEY
  --provider-product-id=ID
  --private
  --replace
  --force
  --interactive
  --plan
  --dry-run

Notes:
  - --replace is required when a managed add should overwrite an existing runtime path.
  - --archive-subdir is only needed when the archive payload is not selected correctly by default.
  - --version pins adoption to a specific upstream release instead of latest.
  - premium sources use the fixed JSON secret/env contract: `WP_CORE_BASE_PREMIUM_CREDENTIALS_JSON`.
  - --plan and --dry-run are preview aliases; they do not mutate the repo.

Examples:
  {$commandPrefix} add-dependency --repo-root=. --source=wordpress.org --kind=plugin --slug=woocommerce
  {$commandPrefix} add-dependency --repo-root=. --source=wordpress.org --kind=plugin --slug=woocommerce --version=10.6.1 --replace
  {$commandPrefix} add-dependency --repo-root=. --source=github-release --kind=plugin --slug=private-plugin --github-repository=owner/private-plugin
  {$commandPrefix} add-dependency --repo-root=. --source=github-release --kind=plugin --slug=private-plugin --github-repository=owner/private-plugin --private
  {$commandPrefix} add-dependency --repo-root=. --source=github-release --kind=plugin --slug=private-plugin --github-repository=owner/private-plugin --archive-subdir=private-plugin
  {$commandPrefix} add-dependency --repo-root=. --source=acf-pro --kind=plugin --slug=advanced-custom-fields-pro
  {$commandPrefix} add-dependency --repo-root=. --source=role-editor-pro --kind=plugin --slug=user-role-editor-pro
  {$commandPrefix} add-dependency --repo-root=. --source=freemius-premium --kind=plugin --slug=blocksy-companion-pro --provider-product-id=5115
  {$commandPrefix} add-dependency --repo-root=. --source=local --kind=plugin --path=cms/plugins/project-plugin
  {$commandPrefix} add-dependency --repo-root=. --source=local --kind=mu-plugin-file --path=cms/mu-plugins/bootstrap.php
  {$commandPrefix} add-dependency --repo-root=. --source=wordpress.org --kind=plugin --slug=blocksy-companion --plan

TEXT;
    }

    private static function adoptDependency(string $commandPrefix): string
    {
        return <<<TEXT
adopt-dependency

Purpose:
  Convert an existing local-owned dependency into a managed dependency in one atomic workflow.

Current scope:
  - local -> wordpress.org
  - local -> github-release
  - local -> acf-pro
  - local -> role-editor-pro
  - local -> freemius-premium

Common flags:
  --repo-root=PATH
  --component-key=KIND:local:SLUG
  --slug=SLUG
  --kind=KIND
  --from-source=local
  --source=wordpress.org|github-release|acf-pro|role-editor-pro|freemius-premium
  --version=VERSION
  --preserve-version
  --github-repository=OWNER/REPO
  --github-release-asset-pattern=PATTERN
  --github-token-env=ENV_NAME
  --credential-key=LOOKUP_KEY
  --provider-product-id=ID
  --private
  --archive-subdir=PATH
  --plan
  --dry-run

Notes:
  - adopt-dependency keeps the existing runtime path.
  - --preserve-version keeps the currently installed local version instead of jumping to latest upstream.
  - the operation is atomic for a single dependency: if adoption fails, the existing runtime tree is restored.
  - multi-command migration batches are still not atomic across separate invocations.

Examples:
  {$commandPrefix} adopt-dependency --repo-root=. --kind=plugin --slug=woocommerce --source=wordpress.org --preserve-version
  {$commandPrefix} adopt-dependency --repo-root=. --component-key=plugin:local:woocommerce --source=wordpress.org --version=10.6.1
  {$commandPrefix} adopt-dependency --repo-root=. --kind=plugin --slug=private-plugin --source=github-release --github-repository=owner/private-plugin --preserve-version
  {$commandPrefix} adopt-dependency --repo-root=. --kind=plugin --slug=advanced-custom-fields-pro --source=acf-pro
  {$commandPrefix} adopt-dependency --repo-root=. --kind=plugin --slug=user-role-editor-pro --source=role-editor-pro
  {$commandPrefix} adopt-dependency --repo-root=. --kind=plugin --slug=blocksy-companion-pro --source=freemius-premium --provider-product-id=5115
  {$commandPrefix} adopt-dependency --repo-root=. --kind=plugin --slug=blocksy-companion --source=wordpress.org --preserve-version --archive-subdir=blocksy-companion
  {$commandPrefix} adopt-dependency --repo-root=. --kind=plugin --slug=woocommerce --source=wordpress.org --plan --preserve-version

TEXT;
    }

    private static function removeDependency(string $commandPrefix): string
    {
        return <<<TEXT
remove-dependency

Purpose:
  Remove a dependency entry from the manifest, optionally deleting its runtime path.

Common flags:
  --repo-root=PATH
  --component-key=KIND:SOURCE:SLUG
  --slug=SLUG
  --kind=KIND
  --source=SOURCE
  --delete-path

Examples:
  {$commandPrefix} remove-dependency --repo-root=. --slug=project-plugin --kind=plugin --source=local
  {$commandPrefix} remove-dependency --repo-root=. --component-key=plugin:local:project-plugin --delete-path

TEXT;
    }

    private static function sync(string $phpCommandPrefix): string
    {
        return <<<TEXT
sync

Purpose:
  Reconcile WordPress core and managed dependencies against the configured upstream sources.

Common flags:
  --repo-root=PATH
  WPORG_REPO_ROOT=PATH
  WPORG_UPDATE_DRY_RUN=1

Notes:
  - sync acts only on manifest-declared managed dependencies.
  - local dependencies are never overwritten by sync.
  - dependency-source failures are reported per dependency and do not stop healthy updates from proceeding.

Examples:
  {$phpCommandPrefix} sync
  WPORG_REPO_ROOT=. {$phpCommandPrefix} sync

TEXT;
    }
}
