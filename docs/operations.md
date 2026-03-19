# Ongoing Operations

This guide is for people who will work with `wp-core-base` on an ongoing basis in a downstream project.

It is about day-to-day use after the initial adoption.

## First Commands To Know

If `wp-core-base` is available inside your project, the most useful commands are:

```bash
php tools/wporg-updater/bin/wporg-updater.php help
php tools/wporg-updater/bin/wporg-updater.php doctor
php tools/wporg-updater/bin/wporg-updater.php doctor --github
php tools/wporg-updater/tests/run.php
```

If you are working in this repository directly, you can also use:

```bash
make doctor
make verify
make sync-dry-run
```

## Recommended Ongoing Workflow

For a downstream team using GitHub:

1. run `doctor` when setting up locally or after changing config
2. use dry-run mode for cautious rollouts
3. review update pull requests like any other code change
4. test locally before merge when the update affects your project materially
5. merge approved changes
6. deploy with your normal deployment method

If you are still wiring up the downstream repository, run `doctor --github` before enabling the scheduled workflow. It now checks for the GitHub environment and for the presence of both the sync workflow and the blocker workflow.

## Updating Managed Plugins

Managed plugins are explicit allowlist entries in `.github/wporg-updates.php`.

When you add or remove a managed plugin:

1. update the config file
2. run `doctor`
3. run a dry-run sync if you want to preview behavior
4. commit the config change

Do not assume the updater will auto-discover the right plugins to manage.

## Using Dry-Run Safely

Dry-run mode is the safest first step when:

- enabling automation for the first time
- changing plugin config
- testing a new downstream workflow
- validating a new vendoring path for `wp-core-base`

Enable it with:

```bash
WPORG_UPDATE_DRY_RUN=1 php tools/wporg-updater/bin/wporg-updater.php sync
```

In GitHub Actions, add `WPORG_UPDATE_DRY_RUN: 1` temporarily to the workflow environment.

## Reviewing Update Pull Requests

The automation is designed to make PRs self-describing, but the team still needs a review habit.

For plugin PRs, pay special attention to:

- changelog entries or GitHub release notes
- support topics opened after release for wordpress.org plugins
- whether the release is patch, minor, or major
- whether your project keeps any local plugin patches

For core PRs, pay special attention to:

- whether the release is patch, minor, or major
- release-note content
- any project-specific compatibility concerns

## Understanding Blocked PRs

Later PRs for the same component may stay blocked while an older PR is still open.

That is intentional.

The intended behavior is:

- patch releases on the same line update the existing PR
- later minor or major releases open as separate blocked PRs
- the blocker is removed after older predecessor PRs are resolved

If you see a blocked PR, it usually means the sequence is working as designed.

The blocker behavior depends on a workflow that runs `pr-blocker`. If you do not have that workflow in the downstream repository, later PRs will still open, but they will not queue in the intended way.

## Common Failure Modes

## `doctor` reports GitHub env missing

That is expected outside GitHub unless you explicitly want to test GitHub-dependent modes locally.

## Support forum scan limit exceeded

Increase `support_max_pages` for that plugin if the forum volume justifies it and you still want complete results.

## Managed plugin path or main file not found

Check:

- the plugin directory path
- the configured `main_file`
- whether the plugin actually exists in the downstream repository

## Local patches inside managed plugin directories

That is a structural problem, not a temporary one.

Managed plugin updates replace the upstream plugin directory. Move local changes out of the managed plugin folder if you want safe ongoing use.

## Contributor Notes

If you are changing `wp-core-base` itself rather than using it downstream, switch to [contributing.md](contributing.md).
