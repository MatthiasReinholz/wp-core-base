# Ongoing Operations

This document is for downstream users working with `wp-core-base` on an ongoing basis.

If you are evaluating whether the framework fits your repo, start with [evaluation-guide.md](evaluation-guide.md). If you are new to the terminology, read [concepts.md](concepts.md).

## Core Commands

```bash
php tools/wporg-updater/bin/wporg-updater.php help
php tools/wporg-updater/bin/wporg-updater.php doctor
php tools/wporg-updater/bin/wporg-updater.php doctor --automation
php tools/wporg-updater/bin/wporg-updater.php stage-runtime --output=.wp-core-base/build/runtime
php tools/wporg-updater/bin/wporg-updater.php framework-sync --check-only
```

If `wp-core-base` is vendored, run these commands from the vendored path and pass `--repo-root=.`

## Canonical Command Contract

Use one command surface consistently.

If `wp-core-base` is the repo root:

```bash
bin/wp-core-base add-dependency --source=local --kind=plugin --path=wp-content/plugins/project-plugin
bin/wp-core-base adopt-dependency --kind=plugin --slug=example-plugin --source=wordpress.org --preserve-version
php tools/wporg-updater/bin/wporg-updater.php doctor --automation --json
php tools/wporg-updater/bin/wporg-updater.php stage-runtime --output=.wp-core-base/build/runtime --json
php tools/wporg-updater/bin/wporg-updater.php framework-sync --check-only --json
```

If `wp-core-base` is vendored:

```bash
vendor/wp-core-base/bin/wp-core-base add-dependency --repo-root=. --source=local --kind=plugin --path=cms/plugins/project-plugin
vendor/wp-core-base/bin/wp-core-base adopt-dependency --repo-root=. --kind=plugin --slug=example-plugin --source=wordpress.org --preserve-version
php vendor/wp-core-base/tools/wporg-updater/bin/wporg-updater.php doctor --repo-root=. --automation --json
php vendor/wp-core-base/tools/wporg-updater/bin/wporg-updater.php stage-runtime --repo-root=. --output=.wp-core-base/build/runtime --json
php vendor/wp-core-base/tools/wporg-updater/bin/wporg-updater.php framework-sync --repo-root=. --check-only --json
```

Use `refresh-admin-governance` after direct manifest edits that change dependency ownership or visibility:

```bash
php vendor/wp-core-base/tools/wporg-updater/bin/wporg-updater.php refresh-admin-governance --repo-root=.
```

## Recommended Operating Routine

1. keep `.wp-core-base/manifest.php` accurate
2. run `doctor` after manifest changes
3. run `stage-runtime` before changing your build or deployment contract
4. review update PRs like normal code changes
5. merge approved PRs only after required runtime validation checks pass
6. deploy through your existing process

## Commands, Locks, and Staging Publication

Every repository-dependent CLI command takes the same lock before reading mutable configuration, including `doctor`, `stage-runtime`, and `framework-sync --check-only`. Help does not acquire it. The lock lives at `.wp-core-base/build/locks/mutation.lock`; different command names do not create independent locks. It coordinates framework commands in the same canonical checkout, not editors, separate clones, or deployment readers.

The default wait limit is 300 seconds. Set `WP_CORE_BASE_LOCK_TIMEOUT_SECONDS` to a positive integer when a job needs a different bound. A timeout reports the recorded holder PID, acquisition time, and operation. Check whether that process is active and resolve its failure before retrying. Never delete or replace the lock file to bypass contention: processes already holding the old file could continue while a second writer acquires a new one. An exited process releases its OS lock even though the diagnostic file remains.

Staging accepts a canonical relative output such as `.wp-core-base/build/runtime`. Repository metadata, the lock directory and its ancestors, live core/content paths, framework tooling/distribution paths, traversal aliases, and symlink ancestors are rejected. Assembly and validation occur in a private sibling directory; a validation failure leaves the previous complete stage unchanged. Publication uses directory renames and rollback, so an unsynchronized reader can briefly observe no directory between renames. Build or deploy only after `stage-runtime` succeeds. A failed rollback preserves its backup and reports the recovery location.

## Private Workspaces and Recovery

Scratch operations use an owner-private namespace below the platform temporary directory: `wp-core-base-private-<owner>/repo-<canonical-path-hash>/`. Each operation has a marker, an active-operation lock, and a `payload/` directory. Stale cleanup requires matching ownership/repository metadata, an inactive lock, and sufficient age. Its default age is one hour; `WP_CORE_BASE_TEMP_DIR_MAX_AGE_SECONDS` accepts a positive override. Existing legacy prefix-named directories have no ownership marker and are intentionally left untouched.

Workspaces marked as preserved are excluded from automatic cleanup regardless of age. A failed dependency restore reports its payload path; `recovery.json` contains the intended runtime path and a `config_files` map of prior file existence and base64-encoded contents. An existing runtime backup is stored alongside it under `runtime/`. Framework installation and staging failures report their own retained backup paths.

If recovery is incomplete:

1. stop automated writes to that checkout and retain the original error, paths, and Git revisions
2. inspect the backup and recovery metadata as data; confirm that the recorded paths belong to this checkout and do not pass through symlinks
3. compare the backup, current tree, manifest, governance data, and framework lock; restore the intended consistent state in a reviewed repair rather than blindly copying paths from a record
4. run `doctor` and `stage-runtime`, inspect the Git diff, and resume automation only after the runtime contract passes
5. remove the preserved backup manually only after the repair is verified and no operation still uses it

Recovery records and backups may contain project code or private configuration. Keep their private permissions and avoid attaching their contents to public issues.

## Concurrent Git Changes and Uncertain Pushes

Update pushes and remote rollback use an exact expected revision. If another actor advances the branch, the lease fails and that actor's commit is preserved. A branch merely observed at the beginning of a run is not authority to restore or delete it later. Local rollback likewise uses recorded ownership and conditional ref updates; unrelated untracked files are retained.

If a push fails after a local commit is created, the server may already have accepted it. The tool retains that recovery commit and reports the uncertainty. Inspect the local commit, the current remote ref, and any existing PR before retrying or repairing it. Do not resolve this situation with an unconditional force push. A remote rollback failure keeps the local recovery ref for inspection. Unowned residue from a partial application may require a reviewed manual repair before the checkout is clean enough for another sync.

## Automation Health and Response Targets

A green scheduled updater does not establish that its generated PRs can pass required checks. In the framework source repository, the read-only GitHub health script inspects dependency/core and framework update PRs, effective required checks, workflow approval requirements, and the scheduled updater/reconciliation runs:

```bash
php scripts/ci/check_update_health.php --repo=owner/repository --json --fail-on-actionable
```

Authenticate the GitHub CLI with read access to the repository's PRs, Actions, and rules. This is a maintainer utility in the source repository, not a bundled downstream CLI command or a GitLab monitor. The upstream `wporg-update-health.yml` workflow uses the same read-only report. The script refuses to report health when required input cannot be established, including absent effective required checks or missing scheduled-run history.

Default signals are:

- approval-required or failed required checks: immediate attention
- a PR at least 24 hours old with missing or unfinished required checks: attention
- an unqueued update open for at least seven days: review overdue
- latest scheduled updater/reconciliation run at least 48 hours old, or a failed completed run: attention

`--max-age-hours` and `--check-grace-hours` configure the PR thresholds. Intentional queueing suppresses the ordinary review-age warning; it does not hide broken or approval-required checks. `--fail-on-actionable` returns a nonzero status when the completed report finds actionable items. Data-read failures also fail the command rather than producing a false healthy result.

The repository maintainer owns framework automation triage. Each downstream team should assign an update owner and an escalation channel. Triage critical upstream advisories within one business day and record a patch/mitigation decision; the seven-day routine review target is not a security-update delay. Review workflow changes and approval requirements, rerun legitimate checks through the normal authorization path, and retain required checks. This monitor does not approve workflows, bypass branch protection, merge PRs, or decide whether an advisory affects a deployed site.

## Reviewing Update PRs

For dependency update PRs, pay attention to:

- release scope
- release notes
- support topics listed after the release timestamp for WordPress.org plugins
- whether the dependency is actually safe to overwrite

For WordPress core PRs, pay attention to:

- release notes
- patch versus minor versus major scope
- any site-specific compatibility concerns

For framework update PRs, pay attention to:

- current and target `wp-core-base` version
- the upstream baseline metadata before and after the update; this is informational for framework updates, not a downstream core/plugin installation
- release-note sections from `wp-core-base` itself
- any scaffolded workflow files that were intentionally skipped because they were locally customized

Framework update PRs are separate from dependency and core PRs. They update the vendored framework snapshot and `.wp-core-base/framework.php`, not your runtime dependency manifest.

## Framework Upgrade Preflight

Before merging or auto-merging a framework update:

1. run `framework-sync --check-only --json`
2. inspect `refreshed_files`, `removed_files`, and `skipped_files`
3. if customized framework-managed files must block rollout, run `framework-sync --check-only --fail-on-skipped-managed-files --json`
4. reconcile any skipped managed files manually before allowing the real framework update PR to land

The strict flag is for GitOps-style downstreams that treat framework-managed workflow drift as a release gate instead of a review note.

## Blocked PRs

Blocked PRs are intentional.

The framework updates patch releases in place on the same line, but opens separate blocked PRs for later minor or major releases if an older PR is still unresolved.

When one automation PR merges, the reconciliation workflow also refreshes the remaining open dependency PRs onto the new base branch state. That keeps manifest checksums and other baseline metadata current across sibling PRs.

The dependency updater also keeps one live PR per dependency/version pair. If duplicate PRs exist for the same target version, the updater keeps one canonical PR and closes the others as superseded. If a previously open PR is already satisfied on the base branch, it is closed automatically as stale/no-op.

WordPress core and framework self-update PRs follow the same rule: one live PR per target version, with stale/no-op PRs closed during reconciliation instead of left open.

Closing any recognized automation PR also removes its generated same-repository
branch. This applies to merged, manually rejected, stale, duplicate, and
superseded PRs. It does not merge or approve anything; it only performs
housekeeping after the close decision.

Closing an update PR is not a permanent version pin. If the manifest still
declares an older managed version, a later scheduled sync can propose the update
again. Change the dependency policy or version intentionally when an update must
remain deferred.

That queueing behavior depends on the blocker workflow.

## Runtime Validation In CI

The intended CI contract is:

1. run `doctor --automation`
2. run `stage-runtime`
3. build or deploy from the staged runtime payload

Treat `wp-core-base Runtime Validation` (or your equivalent workflow that runs the same contract) as a required merge check for automation PRs. Do not merge updater/reconciliation PRs while this check is failing.

If your project is image-first, treat the staged runtime directory as the build input.

## GitLab Automation Prerequisites

For GitLab-hosted automation, configure:

- a masked CI/CD variable named `GITLAB_TOKEN`
- token permissions that include `api` and `write_repository`

The scaffolded `.gitlab-ci.yml` also relies on the normal GitLab CI project metadata, such as `CI_PROJECT_ID`, `CI_PROJECT_PATH`, and `CI_API_V4_URL`.

Updater-driven GitLab merge-request closures clean their branches through the
same provider-neutral lifecycle service. GitLab installations that want cleanup
after a manual close can invoke `managed-pr-cleanup --pr-number=<iid>` from a
trusted close-event integration; the framework does not install a webhook
service or global orphan crawler.

## Common Failure Modes

### `doctor` reports checksum drift

That means a managed dependency tree does not match the manifest checksum. Fix the tree or regenerate the managed dependency snapshot intentionally.

### Runtime validation fails with `Managed dependency checksum mismatch` after an automation merge

This usually means a PR updated a manifest checksum/version without carrying the matching dependency payload in the same merge.

Recover by:

1. opening a corrective PR that brings the dependency payload and manifest back into the same state
2. rerunning runtime validation until the checksum contract passes
3. ensuring `wp-core-base Runtime Validation` stays required so unsafe merges are blocked before landing

### `doctor` reports forbidden runtime files

That means a staged dependency contains files or directories outside your runtime hygiene policy. Remove or reclassify them intentionally.

### Sync fails for a managed dependency

Check:

- the manifest entry
- the main file path
- the source type
- hosted release configuration if the source is `github-release` or `gitlab-release`
- the metadata URL, timestamp field, and download URL if the source is `generic-json`

If the workflow still processed other dependencies, look at the automation job summary and the managed issue `wp-core-base dependency source failures`. The framework keeps healthy updates moving, but the job should still fail after the run when source warnings were reported.

### Framework self-update skips a workflow file

That means a scaffolded workflow no longer matches the last framework-managed checksum in `.wp-core-base/framework.php`.

The framework leaves that file untouched on purpose. Review the new version in the vendored snapshot and update the downstream workflow manually if you want to pick up the upstream changes.

If that situation should fail preflight in CI instead of surfacing only as a review note, run:

```bash
php vendor/wp-core-base/tools/wporg-updater/bin/wporg-updater.php framework-sync --repo-root=. --check-only --fail-on-skipped-managed-files --json
```

### Automation env missing

That is expected outside GitHub or GitLab unless you are explicitly validating the hosted automation contract locally.

On GitLab, the most common cause is a missing `GITLAB_TOKEN` CI/CD variable or a token that lacks `api` and `write_repository` access.

## Deferred Scalability Work

The following items are intentionally deferred unless scale or incident data justifies extra complexity:

- proactive per-host request pacing for WordPress.org calls
- cross-run support-topic caching for older open PR investigation

Trigger conditions for implementation:

1. routine sync workload exceeds 100 managed dependencies, or
2. repeated 429/timeout incidents persist after existing `Retry-After` handling, or
3. support-topic crawling becomes a material share of end-to-end sync time.
