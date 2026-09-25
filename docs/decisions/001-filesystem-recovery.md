# Decision 001: validate paths before mutation and retain recovery evidence

Status: accepted for 1.5.0.

A staging destination must be a canonical relative descendant that does not overlap repository control data, WordPress core, framework distribution, or runtime source. Configuration and CLI overrides use the same rule; symlink ancestors are rechecked before publication. A fresh output is validated before sibling-directory renames publish it. Validation failure leaves the prior output unchanged; failed restoration retains the backup and reports its location. The two-rename publication sequence does not promise uninterrupted visibility to an unsynchronized reader, so deployment must consume a successfully completed stage.

Temporary operations use private, owner-marked workspaces with active locks. Cleanup requires ownership, age, and an inactive lock. Name prefixes are insufficient authority to delete. Recovery workspaces are explicitly preserved and excluded from automated cleanup.

A transaction records prior states before each mutation, attempts every restoration after a failure, and retains backups if restoration is incomplete. Installation commits before cleanup so a cleanup error cannot trigger destructive rollback of a completed install. This costs temporary disk space but makes failures inspectable and recoverable.
