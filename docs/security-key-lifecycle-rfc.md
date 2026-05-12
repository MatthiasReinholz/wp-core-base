# Security Key Lifecycle RFC

This document defines a future design for framework release signing key lifecycle controls beyond the current multi-key verification model.

## Status

- State: partially implemented
- Priority: active hardening
- Scope: release signing and verification controls

## Current Baseline

Current behavior already supports:

- detached signatures for release checksum sidecars
- key-ID binding during verification
- multiple trusted public keys (default key, rotated glob, and env-provided paths)

This RFC does not replace the current mechanism. It records the policy controls now present in the verifier and the remaining migration work.

## Goals

1. make key retirement and compromise response deterministic
2. preserve offline verification for historical releases where possible
3. avoid hidden trust changes between framework versions

## Proposed Enhancements

### 1. Public Key Metadata

Machine-readable metadata is stored in `tools/wporg-updater/keys/framework-release-public-keys.json` for each trusted public key:

- `key_id`
- `created_at`
- `not_after` (expiry timestamp)
- `status` (`active`, `retired`, `revoked`)
- optional `reason` for retirement or revocation

### 2. Committed Revocation List

The committed revocation file is `tools/wporg-updater/keys/framework-release-revocations.json`.

Verification behavior:

- reject signatures from revoked key IDs unconditionally
- report revocation source in CLI error output

### 3. Expiry Enforcement Modes

Introduce explicit verification policy modes:

- `warn-on-expired-key`
- `fail-on-expired-key`

Current behavior is compatibility-safe: retired and expired keys warn, while revoked keys fail.

## Remaining Migration Work

1. add an explicit CLI policy flag for fail-on-expired-key
2. enable fail-on-expired-key for framework release publish paths after maintainers have rotated active keys
3. document release-specific expectations when a historical release verifies with a retired key warning

## Non-Goals

- online key lookup services
- external KMS dependency for verification
- retroactive rewriting of historical release signatures
