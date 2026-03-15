# Downstream Usage

`wp-core-base` can support downstream WordPress projects in two different ways:

- as a versioned code dependency
- as a shared automation dependency

Those are related, but they should not be treated as the same thing.

## Recommended Model

For most teams, the cleanest approach is:

1. Keep `wp-core-base` as the canonical upstream repository.
2. Tag and publish releases whenever the base changes in a way downstream projects should consume.
3. Consume the repository in downstream projects as either:
   - a Git subtree, or
   - a Git submodule pinned to a release tag.
4. Keep project-specific code outside the upstream-owned core paths.

That gives downstream projects:

- explicit upgrade points
- reproducible WordPress core baselines
- a clean diff between upstream base changes and local customization

## Code Dependency Options

### Git Subtree

Use a subtree when:

- downstream teams want the base committed directly in their own repository
- local customization is common
- developers prefer to avoid nested repositories

Tradeoff:

- updates are explicit pull operations
- merge discipline matters

### Git Submodule

Use a submodule when:

- teams want a hard version pin to upstream tags
- clear upstream/downstream separation is more important than convenience
- the organization is comfortable with submodule workflows

Tradeoff:

- operational overhead is higher
- contributor UX is worse if the team is unfamiliar with submodules

### Template Repository

Use a template only for one-time bootstrapping.

It is useful when:

- you need a fresh starting point
- you do not need ongoing upstream synchronization

It is not a real dependency model.

## Automation Dependency

The updater can also be used against an external checked-out repository by setting `WPORG_REPO_ROOT`.

That means a downstream repository can:

- check out its own code
- check out `wp-core-base` as a submodule or subtree, or under a tools path
- run `tools/wporg-updater/bin/wporg-updater.php` from the upstream base
- point it at the downstream checkout with `WPORG_REPO_ROOT`

This keeps the automation logic centralized while allowing downstream repos to own their own actual content and PR history.

## Example Downstream Layout

One practical structure is:

```text
downstream-project/
  .github/
  app/
  vendor/
    wp-core-base/   <- subtree or submodule
```

Then in CI:

```bash
WPORG_REPO_ROOT="$GITHUB_WORKSPACE" php vendor/wp-core-base/tools/wporg-updater/bin/wporg-updater.php sync
```

## Versioning Strategy

Recommended release tags for `wp-core-base`:

- `v6.9.4.0`
- `v6.9.4.1`
- `v6.9.5.0`

Interpretation:

- first three segments track the bundled WordPress core version
- final segment tracks base-repository revisions on top of that core version

This makes downstream upgrade intent easy to understand.

## Release Contents

Each GitHub Release should state:

- bundled WordPress core version
- bundled wordpress.org plugin versions, if any are intentionally part of the base
- whether the release is primarily security, bugfix, or feature oriented
- any downstream migration notes

## Recommendation

If the goal is a long-lived upstream dependency, prefer:

- Git subtree for most teams
- Git submodule for teams that want the strongest pinning discipline

Use template repositories only for initial scaffolding, not for ongoing dependency management.
