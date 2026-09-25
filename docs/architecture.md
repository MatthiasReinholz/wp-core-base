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
- `Cli\CommandOptions` supplies both command option validation and help; values and boolean flags have distinct schemas
- namespaced `extensions` retain downstream metadata while security and source sections reject unknown policy keys
- these classes are the canonical schema boundaries for the framework

### Runtime hygiene and staging

- `RuntimeInspector` is the low-level runtime policy engine
- `RuntimeOwnershipInspector` discovers undeclared runtime paths
- `RuntimeStager` assembles and validates a private deployment payload before publishing it
- canonical path validation protects runtime inputs, repository metadata, tooling, lock paths, and configured distribution paths

Core invariant:
- `managed`, `local`, and `ignored` are different contracts and must not be blurred

### Dependency authoring and ingestion

- `DependencyAuthoringService` owns add/adopt/remove workflows
- `DependencyAuthoringSupport` isolates shared option and classification helpers
- `DependencyMutationTransaction` records runtime and configuration rollback data for authoring; failed restoration preserves a recovery manifest and backups
- `ExtractedPayloadLocator` validates archive subdirectories and expected runtime entry points
- `DependencyScanner` and `DependencyMetadataResolver` infer local runtime metadata
- managed-source adapters resolve WordPress.org, GitHub/GitLab releases, generic JSON metadata, and premium-provider inputs
- `SourceCatalog` and `SourceRelease` validate version/timestamp records without discarding provider fields; `HistoricalVersionSource` is an optional capability for latest-only adapters

Core invariant:
- managed dependencies must resolve to a deterministic sanitized runtime tree with a stable checksum

### Automation and PR lifecycle

- `Updater` orchestrates managed dependency update PRs, including release resolution, installation, branch updates, and PR lifecycle checks
- `CoreUpdater` handles WordPress core PRs and archive application
- `FrameworkSourceBaselineSynchronizer` keeps upstream-only baseline metadata and public baseline facts aligned with generated dependency and core PRs
- `FrameworkSyncer` handles vendored framework self-update PRs
- `PullRequestBlocker` enforces blocked-by queueing rules

Core invariant:
- one dependency/version pair should map to one live automation PR

### Shared mutation and transport boundaries

- `MutationLock` and `MutationLease` serialize repository-dependent CLI commands before mutable configuration is loaded
- `BranchRollbackGuard` records confirmed Git mutations and restores only owned paths and references; `GuardedGitRunnerInterface` exposes optional exact-revision compare-and-swap capabilities
- `TempWorkspace` owns private marked scratch directories; `TempDirectoryJanitor` removes only eligible inactive workspaces for the same repository
- `HttpRequestPolicy` validates HTTPS origins and redirect destinations and strips credentials after origin changes; `HttpClient` uses the same policy for direct, retried, and streamed requests

Core invariant:
- an observation is not ownership; unexpected concurrent state or an uncertain write must retain evidence instead of authorizing broader cleanup

The [filesystem](decisions/001-filesystem-recovery.md), [Git mutation](decisions/002-mutations-and-git.md), [distribution](decisions/003-release-artifacts.md), and [network trust](decisions/004-network-trust.md) decisions document the limits and compatibility choices.

### Release engineering and provenance

- `FrameworkReleasePreparer` updates framework metadata and release notes
- `FrameworkReleaseArtifactBuilder` builds the reproducible tooling-only vendored artifact
- `FrameworkReleasePayload` defines the allowlist and validates the extracted file inventory independently
- `FrameworkPayloadIdentity` binds verified payload metadata to the requested release version, configured source, asset name, and non-downgrade policy
- `FrameworkReleaseVerifier` validates metadata, public contract coherence, artifact checksum, detached signature, and downstream installability

Core invariant:
- the published release artifact must match the committed framework metadata and the public documentation about the current baseline

## Repository Topology

This repository includes a full WordPress baseline, but external reviewers should separate:

- framework-owned code: `tools/wporg-updater`, docs, templates, workflows, metadata
- bundled baseline payload: committed WordPress core and selected plugins/themes used as the upstream baseline state

The framework owns how that baseline is described, validated, and staged. The official framework ZIP excludes that runtime baseline; a tagged source checkout or source archive can serve as an optional full-core starter. Framework sync changes vendored tooling and eligible framework-managed files, while downstream core and dependency updates use their own manifest-driven flows. The framework does not claim authorship of upstream WordPress or third-party plugin internals.

## What Must Stay Coherent

At all times, these must agree:

- README current baseline facts
- `.wp-core-base/framework.php`
- `.wp-core-base/manifest.php` for managed dependency versions
- `docs/releases/<current-version>.md`
- scaffolded workflow and template expectations

If they drift, public trust drops immediately.
