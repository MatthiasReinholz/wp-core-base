# Security Key Lifecycle RFC

This document defines a future design for framework release signing key lifecycle controls beyond the current multi-key verification model.

## Status

- State: proposed
- Priority: future
- Scope: release signing and verification controls

## Current Baseline

Current behavior already supports:

- detached signatures for release checksum sidecars
- key-ID binding during verification
- multiple trusted public keys (default key, rotated glob, and env-provided paths)

This RFC does not replace the current mechanism. It adds policy controls for expiry and explicit revocation.

## Goals

1. make key retirement and compromise response deterministic
2. preserve offline verification for historical releases where possible
3. avoid hidden trust changes between framework versions

## Proposed Enhancements

### 1. Public Key Metadata

Add machine-readable metadata for each trusted public key:

- `key_id`
- `created_at`
- `not_after` (expiry timestamp)
- `status` (`active`, `retired`, `revoked`)
- optional `reason` for retirement or revocation

### 2. Committed Revocation List

Add a committed revocation file under `tools/wporg-updater/keys/` that lists revoked key IDs and revocation timestamps.

Verification behavior:

- reject signatures from revoked key IDs unconditionally
- report revocation source in CLI error output

### 3. Expiry Enforcement Modes

Introduce explicit verification policy modes:

- `warn-on-expired-key`
- `fail-on-expired-key`

Default should remain compatibility-safe until migration is complete.

## Migration Plan (Future)

1. add metadata file and parser without enforcing expiry
2. add revocation-list parsing and hard failure for revoked keys
3. add expiry warning mode in CI first
4. graduate to fail mode for framework release publish and verification paths

## Non-Goals

- online key lookup services
- external KMS dependency for verification
- retroactive rewriting of historical release signatures
