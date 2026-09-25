# Decision 002: one repository mutation lock and conditional Git writes

Status: accepted for 1.5.0.

Every repository-dependent CLI command acquires the same checkout lock before loading mutable configuration, including staging, doctor, and check-only operations. Help is exempt. Command-specific locks cannot protect a shared manifest or checkout. The lock is reentrant within one process; a fork must contend independently.

Remote pushes/deletions require the exact revision observed before the operation, and local rollback uses compare-and-swap against the operation-owned revision. Recovery records completed checkout and push receipts instead of treating a later HEAD as proof of ownership. A moved branch is preserved. An ambiguous push retains the local recovery commit because the remote may have accepted it.

Only recorded mutation paths are restored; unrelated untracked files are not globally cleaned. Custom Git runners may keep the basic interface, but automatic conditional recovery requires the optional guarded interface. Unsupported recovery fails with evidence rather than silently using an unconditional force push.

The lock coordinates framework commands using the same canonical checkout. It is not a distributed lock across separate clones and does not lock editors or arbitrary Git commands. Exact remote leases protect against other clones; unexpected local commits or unowned files are preserved for recovery.
