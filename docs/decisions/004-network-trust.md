# Decision 004: bind credentials to origins and signed payloads to release identity

Status: accepted for 1.5.0.

HTTPS, destination allowlists, response size limits, and a redirect budget apply at every hop. Credential scope is an origin: scheme, host, and port. API credentials are never sent to an arbitrary asset URL returned in API metadata. On any origin change, authorization, cookies, and custom headers are removed permanently for that request chain. Returning to the original origin does not restore them.

Sidecar reads use the same explicit redirect policy as binary assets. Redirected downloads fail closed on unsafe destinations, malformed URLs, loops, oversized responses, or checksum mismatches. Premium providers may explicitly register additional credential origins; untrusted redirects cannot expand that set.

A detached signature authenticates bytes, but installation also verifies the advertised version, authoritative repository and API identity, asset name, and no-downgrade policy. A valid signature over a different release cannot satisfy the intended update. This distinction keeps transport trust, artifact integrity, and update intent separate.
