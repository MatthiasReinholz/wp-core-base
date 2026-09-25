# Security Policy

## Reporting

If you believe you have found a security issue in `wp-core-base`, do not open a public issue first.

Report it privately to the maintainer with:

- a clear description of the issue
- affected versions or workflows
- reproduction steps if available
- impact and likely trust boundary affected

If the report involves release provenance, credential handling, artifact trust, or managed-source verification, include that explicitly.

## Scope

Security-sensitive areas include:

- managed dependency download and verification
- framework release signing and verification
- runtime staging and hygiene enforcement
- credential handling for GitHub, GitLab, and premium providers
- transactional filesystem changes, cleanup, and Git recovery

For the framework trust model, see [docs/security-model.md](docs/security-model.md).

## Response and ownership

The repository maintainer owns framework vulnerability triage and release coordination. Each downstream team owns deployed-site exposure, rollout, and validation of its selected dependencies. Report privately before publishing a reproducer that could put deployed sites at risk; use the repository's private reporting feature when available or an established private maintainer contact.

Operational targets are to triage critical upstream advisories within one business day, assign an owner, and record a patch or mitigation decision immediately. These are response targets, not a guarantee that every compatibility-sensitive upgrade can ship within that period. Prefer a verified upstream security backport when a larger upgrade needs more validation; do not maintain an unreviewed local security patch to bundled WordPress or plugin code.

The normal automation targets are seven days for review of an unqueued update, 24 hours for required checks to complete, and less than 48 hours since the latest scheduled updater/reconciliation run. Approval-required or failed checks need attention immediately. A successful scheduled updater alone does not establish that its pull requests can merge. See [ongoing operations](docs/operations.md#automation-health-and-response-targets).

Use maintained PHP 8.4 or 8.5 for framework operations. PHP 8.1 syntax compatibility is tested for migration, but its end-of-life status makes it unsuitable as a recommended operational runtime. Deployment security also depends on the support status of WordPress, the selected plugins, the database, and the hosting platform.
