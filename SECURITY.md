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
- credential handling for GitHub and premium providers

For the framework trust model, see [docs/security-model.md](/Users/matthias/DEV/wp-core-base/docs/security-model.md).
