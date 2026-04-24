# Architecture

This document is for maintainers and evaluators of `wp-core-base`.

## System Shape

`wp-core-base` is organized around four compatibility-sensitive contracts:

1. `.wp-core-base/manifest.php`
2. `.wp-core-base/framework.php`
3. `tools/wporg-updater/bin/wporg-updater.php`
4. scaffolded downstream workflows and runtime files

Everything else exists to load, validate, project, or automate those contracts.

## Main Subsystems

### Config and contract loading

- `Config` loads and normalizes the downstream manifest
- `RuntimeHygieneDefaults` is the canonical source for default forbidden and managed-sanitation runtime hygiene lists
- `ManifestWriter` and `PhpArrayFileWriter` serialize normalized manifests deterministically
- `FrameworkConfig` loads and normalizes framework metadata
- these classes are the canonical schema boundaries for the framework

### Runtime hygiene and staging

- `RuntimeInspector` is the low-level runtime policy engine
- `RuntimeOwnershipInspector` discovers undeclared runtime paths
- `RuntimeStager` assembles a clean deployment payload

Core invariant:
- `managed`, `local`, and `ignored` are different contracts and must not be blurred

### Dependency authoring and ingestion

- `DependencyAuthoringService` owns add/adopt/remove workflows
- `DependencyAuthoringSupport` isolates shared option and classification helpers
- `DependencyScanner` and `DependencyMetadataResolver` infer local runtime metadata
- managed-source adapters resolve WordPress.org, GitHub release, and premium-provider inputs

Core invariant:
- managed dependencies must resolve to a deterministic sanitized runtime tree with a stable checksum

### Automation and PR lifecycle

- `Updater` orchestrates managed dependency update PRs, including release resolution, installation, branch updates, and PR lifecycle checks
- `CoreUpdater` handles WordPress core PRs and archive application
- `FrameworkSyncer` handles vendored framework self-update PRs
- `PullRequestBlocker` enforces blocked-by queueing rules

Core invariant:
- one dependency/version pair should map to one live automation PR

### Release engineering and provenance

- `FrameworkReleasePreparer` updates framework metadata and release notes
- `FrameworkReleaseArtifactBuilder` builds the vendored snapshot artifact
- `FrameworkReleaseVerifier` validates metadata, public contract coherence, artifact checksum, detached signature, and downstream installability

Core invariant:
- the published release artifact must match the committed framework metadata and the public documentation about the current baseline

## Repository Topology

This repository includes a full WordPress baseline, but external reviewers should separate:

- framework-owned code: `tools/wporg-updater`, docs, templates, workflows, metadata
- bundled baseline payload: committed WordPress core and selected plugins/themes used as the upstream baseline state

The framework owns how that baseline is described, validated, staged, and released. It does not claim authorship of upstream WordPress or third-party plugin internals.

## What Must Stay Coherent

At all times, these must agree:

- README current baseline facts
- `.wp-core-base/framework.php`
- `.wp-core-base/manifest.php` for managed dependency versions
- `docs/releases/<current-version>.md`
- scaffolded workflow and template expectations

If they drift, public trust drops immediately.
