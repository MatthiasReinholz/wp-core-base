# Baseline Upgrades and Rollback

This guide is for maintainers changing the optional WordPress starter baseline and downstream teams adopting that runtime change. A framework update through `framework-sync` installs framework tooling; it does not install the framework's recorded WordPress or plugin versions into a downstream site.

## Coordinated Baseline Changes

Treat core and plugin requirements as one deployment decision. The move from WordPress 6.9.9, WooCommerce 11.0.1 and Jetpack 16.1.3 to WordPress 7.1.2, WooCommerce 11.1.2 and Jetpack 16.2 must include a compatible core: both target plugins declare WordPress 7.0 as their minimum. Do not deploy either target plugin into the old core merely because its individual update PR passes framework unit tests.

For `full-core`, review the core and plugin trees together, update the manifest checksums, and run `doctor` and `stage-runtime`. For `content-only`, pin and validate the externally supplied core/image alongside the content artifact. Its core compatibility cannot be established from the content manifest alone.

The baseline's managed checksums, framework baseline metadata, governance projection, README and current release notes must agree. Preserve `local` ownership for project code and do not overwrite local themes or plugins to make the upgrade look uniform. Use the framework's archive ingestion and sanitization contracts for managed dependencies; do not patch vendored upstream code to hide a compatibility problem.

## Repository Upgrade Fixture

The source repository includes a database-backed upgrade fixture, separate from the fresh-install smoke test:

```bash
php scripts/ci/verify_wordpress_upgrade.php --profile=full-core --order-storage=hpos
php scripts/ci/verify_wordpress_upgrade.php --profile=full-core --order-storage=posts
php scripts/ci/verify_wordpress_upgrade.php --profile=content-only --order-storage=hpos
php scripts/ci/verify_wordpress_upgrade.php --profile=content-only --order-storage=posts
```

Run it from a Git checkout containing the immutable v1.5.0 baseline commit `a8f6404fa46befd6d2a8ecd9a3aca940ea05a45c`. Shallow CI checkouts must fetch that exact commit before running the fixture. Use the disposable local database variables described in [contributing.md](contributing.md#wordpress-runtime-smoke-test), including the exact database name `wp_core_base_smoke`; the PHP runtime also needs `mysqli`. This fixture must never be pointed at a production database.

The order-storage modes cover WooCommerce's HPOS tables and legacy posts storage. The fixture starts with the pinned old runtime and synthetic store data, then exercises the candidate runtime against that existing database. It checks:

- exact core/plugin versions, the active plugin inventory, configured theme availability and the governance MU plugin;
- real WordPress upgrades and WooCommerce's queued database migration actions;
- simple and variable products, existing processing/pending orders, customer data, stock, coupon, tax and cart totals;
- working WooCommerce download-permission records and preservation of a table at the database's 64-character identifier limit;
- public WordPress REST access and anonymous/customer/administrator WooCommerce order permissions;
- creation and fresh-process retrieval of a new order from the migrated taxed/discounted cart;
- migration-specific preservation of customized email content and removal of obsolete generated data;
- restoration of the isolated pre-upgrade database together with the previous runtime, verified in a fresh process.

The fixture complements the fresh-install test and the framework's filesystem compatibility checks. Preserve its complete output, source revision, PHP/database versions, tested profile and order-storage mode with release evidence. Run all four combinations after changing the core/plugin baseline or the fixture itself.

Both fresh-install and upgrade validation reject recorded WordPress database errors and PHP fatal/parse errors, even when the process exits successfully and writes its completion receipt. Nonfatal upstream notices, warnings and deprecations remain a separate diagnostic category; a passing fixture does not claim that every log is empty. Backup table aliases are bounded independently of the original table name, with an explicit mapping and inventory check before restoration.

This is a repository baseline regression test. Its isolated table copy is sufficient for the fixture's known schema; it is not a general backup implementation and does not exercise database triggers or foreign-key restore behavior. It does not certify a merchant's production data, custom code, payment provider, browser checkout, multisite configuration or external Jetpack services. Those require the downstream site's own staging environment and integration tests.

## Existing Store Rehearsal

Before adopting a runtime upgrade on an existing store:

1. Inventory the exact core, plugin, theme, PHP and database versions, including local code, paid extensions, active order storage mode and background jobs. Read the upstream release notes for requirements and migrations.
2. Restore a recent database and matching uploads/runtime backup into an isolated staging site. Disable live payments, email delivery, webhooks and external integrations or direct them to their supported test environments. Protect customer data according to the site's policies.
3. Apply the same reviewed runtime artifact and external core image that production will use. Complete WordPress and WooCommerce database updates and wait for their required migration jobs to finish.
4. Compare existing products, variations, customers, orders, refunds, stock, tax settings and scheduled actions before and after. Exercise storefront navigation, cart, checkout, payment/refund tests, administrative editing and each project-specific integration.
5. Rehearse restoration of the pre-upgrade database and matching runtime before scheduling the production change. Record the expected recovery time and acceptable data-loss window.

WooCommerce's [update guidance](https://woocommerce.com/document/how-to-update-woocommerce/) and WordPress's [core update guidance](https://wordpress.org/documentation/article/updating-wordpress/) describe the upstream database and backup requirements. For manifest-managed components, deliver the reviewed Git artifact through the project's deployment process; dashboard updates are governed separately.

## Deployment and Rollback

Use the site's maintenance procedure to stop writes and drain or pause workers before taking the final consistent backup. Keep the previous runtime artifact, its source revision, the database snapshot and any required upload/configuration snapshot together. Preserve secrets outside Git.

Deploy the tested staged payload, run the required database upgrades, clear runtime/object caches as appropriate, and complete the site's acceptance checks before reopening writes. Retain migration and application logs and watch failed scheduled actions and critical store flows after release.

If acceptance checks fail, keep writes paused and restore the pre-upgrade database together with the matching prior runtime and required file snapshot. A Git revert or swapping an old plugin directory is not a database rollback. Do not run old code against an upgraded database and assume it will undo schema or data migrations. Recheck the restored store, invalidate stale caches, and only then resume traffic and workers.

Once new orders or other writes have been accepted, restoring an old database can discard them. Follow the site's incident and reconciliation procedure to preserve that data before a restore, or use a validated forward fix. The framework's filesystem transaction backups protect framework mutations; they are not a production database backup or a store rollback service.
