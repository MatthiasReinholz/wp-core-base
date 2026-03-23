# Evaluation Guide

This guide is for people or AI agents assessing whether `wp-core-base` fits an existing WordPress project.

## Goal

Produce a grounded assessment of:

- fit
- migration effort
- risks
- recommended profile and adoption path
- recommended framework consumption and update path

## Evaluation Order

### 1. Determine repository shape

Answer:

- does the repo contain WordPress core?
- or does it contain only a content tree?

Recommend:

- `full-core` when core is in Git
- `content-only` when core is external

### 2. Determine deployment model

Classify the current deployment style:

- server-side Git checkout
- FTP or SFTP deployment
- CI-built artifact
- Docker/image-first
- platform-managed WordPress core with content-only repo

This affects whether `stage-runtime` should become part of the deployment contract.

### 3. Inventory runtime roots

Identify all runtime-bearing paths, including:

- plugins
- themes
- MU plugins
- runtime files
- extra runtime directories under the content root

### 4. Classify ownership

For each runtime path, decide whether it should be:

- `managed`
- `local`
- `ignored`

Use these rules:

- third-party archive-backed dependencies are strong candidates for `managed`
- project-owned code is usually `local`
- intentionally out-of-scope paths may be `ignored`

### 5. Inventory dependency sources

For each path that might be `managed`, determine the source:

- WordPress.org
- GitHub Release
- private GitHub Release
- unsupported source type

Then compare against [support-matrix.md](/Users/matthias/DEV/wp-core-base/docs/support-matrix.md).

### 6. Check GitHub availability

Decide whether the project has:

- GitHub source control and Actions available
- GitHub source control only
- no GitHub yet

Remember:

- GitHub is required for automated PR workflows
- GitHub is not required to use the framework’s codebase structure or runtime model

### 6a. Check framework pinning strategy

If the downstream wants ongoing upstream framework maintenance, confirm that it can vendor `wp-core-base` as a release snapshot and keep `.wp-core-base/framework.php` under version control.

That is the supported automated framework-update model.

### 7. Determine validation and staging needs

Ask:

- should the repo tree itself already be deployable?
- or should deployment use a staged runtime payload?

Recommend:

- `source-clean` if repo paths must already be runtime-clean
- `staged-clean` if local source trees may keep allowed extras

### 8. Determine migration strictness

Ask:

- is the runtime already fully understood and explicitly classifiable?
- or are there many mixed, undeclared, or legacy paths?

Recommend:

- `strict` when the project is ready for explicit ownership now
- `relaxed` temporarily when migration needs discovery first

## Recommended Output Format

A good evaluation should include:

1. Profile recommendation
2. Deployment model summary
3. Dependency source inventory
4. Ownership classification summary
5. Supported areas
6. Unsupported or manual areas
7. Migration risks
8. Recommended next steps
9. Framework version/update recommendation

## Good Adoption Recommendations

### Strong fit

The project is a strong fit when:

- it wants Git-reviewed updates
- it can separate managed and local ownership clearly
- it benefits from deterministic runtime staging
- its third-party dependencies are mostly from supported source types

### Moderate fit

The project is a moderate fit when:

- it wants the runtime model and docs
- but some dependency sources are still unsupported
- or GitHub automation is not yet available

### Weak fit

The project is a weak fit when:

- it expects every dependency source to be updater-managed today
- it relies heavily on raw Git working trees or symlinked runtime inputs
- it cannot accept explicit managed versus local ownership

## Recommended Adoption Paths

### Greenfield full-core

Use `full-core`.

### Greenfield content-only or image-first

Use `content-only`, often with `content-only-image-first`.

### Existing mixed repo

Start with:

- `content-only` or `full-core` as appropriate
- `relaxed` if discovery is still needed
- `suggest-manifest`
- `stage-runtime`

Then converge toward `strict`.

## Questions An Evaluator Should Answer Explicitly

- Can this project keep custom code as `local`?
- Which third-party dependencies are safe to overwrite?
- Which dependency sources are unsupported today?
- Does the team want GitHub-based PR automation?
- Should runtime builds come from `stage-runtime`?
- Is the migration mostly structural, operational, or both?

## Common Mistakes In Evaluations

- assuming all plugin folders are safely `managed`
- ignoring themes, MU plugins, and runtime files
- assuming GitHub is required even when only the base/repo model is being adopted
- recommending `relaxed` as a permanent state
- ignoring unsupported dependency source types

## Related Docs

- [concepts.md](/Users/matthias/DEV/wp-core-base/docs/concepts.md)
- [support-matrix.md](/Users/matthias/DEV/wp-core-base/docs/support-matrix.md)
- [faq.md](/Users/matthias/DEV/wp-core-base/docs/faq.md)
- [getting-started.md](/Users/matthias/DEV/wp-core-base/docs/getting-started.md)
- [migration-guide.md](/Users/matthias/DEV/wp-core-base/docs/migration-guide.md)
