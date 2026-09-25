# Contributing To wp-core-base

This guide is for contributors, maintainers, and the repository author.

If you are a downstream user, return to [../README.md](../README.md).

## Project Responsibility

`wp-core-base` is a reusable upstream, not a site-specific application.

Contributors should preserve:

- a clean upstream/downstream boundary
- explicit runtime contracts
- explicit dependency ownership
- clear user documentation separated from maintainer detail

## Documentation Boundaries

- `README.md` is for downstream users
- `docs/getting-started.md` is for downstream onboarding
- `docs/contributing.md` is for maintainers and contributors
- `docs/architecture.md` is the maintainer map of subsystems and invariants
- `docs/security-model.md` is the maintainer trust-boundary and release-trust reference
- `docs/automation-overview.md` is for technical internals

Do not move contributor-only detail back into `README.md` unless downstream users truly need it to adopt the framework.

## Verification

Before shipping changes, run:

```bash
php tools/wporg-updater/tests/run.php
php tools/wporg-updater/bin/wporg-updater.php doctor --repo-root=.
php tools/wporg-updater/bin/wporg-updater.php stage-runtime --repo-root=. --output=.wp-core-base/build/runtime
php tools/wporg-updater/bin/wporg-updater.php release-verify --repo-root=.
php scripts/ci/check_workflow_examples_and_permissions.php
```

Also syntax-check touched PHP files with `php -l`.

The CI workflow also runs PHPStan and workflow linting. Treat those as release-blocking signals, not optional cleanup work.

## Baseline Changes

When changing the bundled baseline:

1. keep `.wp-core-base/manifest.php` aligned
2. update managed dependency checksums if managed trees changed
3. keep the `Current Baseline` section in `README.md` accurate
4. make sure the repository still passes `doctor` and `stage-runtime`

Core or plugin baseline migrations must also pass the database-backed upgrade fixture for both profiles. See [baseline upgrades and rollback](baseline-upgrades.md) for the pinned starting point, test commands, evidence boundaries and downstream rehearsal procedure.

## Scaffolding Changes

If you change downstream scaffolding, keep these aligned:

- `tools/wporg-updater/templates/`
- `docs/examples/`
- `docs/getting-started.md`
- `docs/manifest-reference.md`

## Release Discipline

Treat tags as the contract with downstream users.

The framework release version is pinned in `.wp-core-base/framework.php`, not in the runtime manifest.

Downstream framework self-update depends on that version metadata and on the published vendorable release asset, so release hygiene matters directly for downstream automation.

Use [release-process.md](release-process.md) for the maintainer checklist.

## Support Expectations

- treat the CLI, manifest shape, framework metadata shape, and scaffolded workflow filenames as compatibility-sensitive
- prefer upstream fixes in `wp-core-base` over downstream workarounds when the framework contract is wrong or ambiguous
- if a change affects trust boundaries, release verification, or agent behavior, update the maintainer docs in the same change

## Test inventory and quality tools

`tests/run.php` checks an explicit inventory of every integration suite before allocating fixtures, then requires each registered suite to execute once. Output includes each suite's assertions, duration, failures, and the final registered/executed counts. The historical fixture order is preserved. Adding a suite file without registering and invoking it fails the run.

Set `WP_CORE_BASE_TEST_REPORT=/tmp/wp-core-base-tests.json` for a structured report. With PCOV or Xdebug coverage mode enabled, set `WP_CORE_BASE_COVERAGE_FILE=/tmp/wp-core-base-coverage.json` for actual observed framework lines. This report excludes subprocess execution and has no arbitrary pass-percentage threshold.

Static analysis runs at level 5 for the framework and CI scripts, with a separate level 8 gate for the typed source records, update planning, ownership, HTTP policy, and framework payload identity boundaries. Run the same pinned PHPStan tool with `--configuration=phpstan-boundaries.neon.dist --memory-limit=1G` to check those stricter contracts locally.

Install the pinned quality toolchain with `bash scripts/ci/install_quality_tools.sh`. Versions and verified archive digests have one source in `scripts/ci/quality-tools.json`. Run documentation contracts with `php scripts/ci/check_documentation.php`; it checks current links and CLI flags and executes safe onboarding/verification examples. Historical release notes are excluded from current command-contract checks.


## WordPress Runtime Smoke Test

Filesystem validation alone does not prove that WordPress and its plugins can bootstrap together. The source repository includes a real WordPress smoke fixture for both staging profiles:

```bash
php scripts/ci/verify_wordpress_runtime.php --profile=full-core
php scripts/ci/verify_wordpress_runtime.php --profile=content-only
```

Before running it, provision a disposable local MySQL-compatible database and set:

- `WP_CORE_BASE_SMOKE_DB_HOST`: `127.0.0.1` or `localhost`, optionally with a port
- `WP_CORE_BASE_SMOKE_DB_NAME`: exactly `wp_core_base_smoke`
- `WP_CORE_BASE_SMOKE_DB_USER`: the disposable database user
- `WP_CORE_BASE_SMOKE_DB_PASSWORD`: that user's password

The fixture requires `mysqli`, does not load a site's `wp-config.php`, uses isolated table prefixes, blocks external WordPress HTTP calls and automatic updates, and exercises installation, the manifest's plugins, MU governance, and REST bootstrap. For `content-only`, it verifies staging excludes core and supplies the separately maintained core layer for the test. Each subprocess must write a unique completion receipt after its assertions; an early `exit(0)` cannot make the smoke pass. Cleanup removes only the fixture's run-specific database objects and stage. This is not a browser, payment, production-data migration, or exhaustive plugin integration test.

Retain the profile, PHP/database versions, source revision, and output as release evidence. Re-run after core, plugin, staging, or governance changes. Use [package benchmarks](evaluating-alternatives.md#measuring-package-cost) for distribution cost and [update health](operations.md#automation-health-and-response-targets) for operational progress; these measure different contracts.
