# Decision 003: immutable tooling artifacts and receipt-based publication

Status: accepted for 1.5.0.

The vendored artifact keeps the existing ZIP name and `wp-core-base/` root for old-installer compatibility. Its contents now follow an explicit tooling allowlist. The optional WordPress/plugin starter baseline remains in the upstream repository and is not duplicated into every consumer's vendor directory.

Official builds read blobs from an exact Git commit. A sorted file inventory records the source revision, hashes, sizes, and modes. ZIP entries have fixed timestamps, modes, order, and stored compression. This makes identical source builds byte-identical and removes workstation files and compression-version variation from release identity. Development fixtures are explicitly marked and never an automatic official-build fallback.

Publication proceeds through draft creation, asset upload, remote byte verification, and publication. Positive receipts identify the exact newly created tag object and numeric release ID. Pre-existing resources are never overwritten or deleted. Failed publication preserves every remote release and tag. Release deletion offers no conditional state check, so even a positively identified draft can become published between observation and deletion. Receipts and uncertainty flags are recovery evidence for an operator, not automatic cleanup authority. Published bytes remain immutable even when that requires a new corrective release.
