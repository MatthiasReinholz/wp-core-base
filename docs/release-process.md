# Release Process

This document is for maintainers of `wp-core-base`.

It describes how to cut a clean release that downstream repositories can trust and consume.

## Release Goal

Each tag should represent a stable, intentional upstream state.

Downstream users should be able to answer these questions from the release alone:

- which WordPress core version is bundled
- whether curated plugin baselines changed
- whether the updater or documentation changed in a way they should care about

## Maintainer Checklist

1. Update the base intentionally.
2. Run local verification:
   - `make doctor`
   - `make verify`
   - targeted `php -l` checks for touched PHP files
3. Review the user-facing docs if behavior changed.
4. Review the contributor docs if workflow changed.
5. Confirm the bundled baseline is reflected correctly in `README.md`.
6. Commit the release-ready state.
7. Tag the release using the repository versioning convention.
8. Publish a GitHub Release with a concise downstream-oriented summary.

## Tag Format

The intended format is:

- `v6.9.4.0`
- `v6.9.4.1`
- `v6.9.5.0`

The first three segments track the bundled WordPress core version.

The final segment tracks `wp-core-base` revisions on top of that core baseline.

## Release Notes Guidance

Release notes should focus on downstream impact.

They should summarize:

- bundled WordPress core version
- bundled curated plugin changes
- automation changes
- documentation or adoption changes that matter to users

Do not make downstream users infer impact from raw commit history.
