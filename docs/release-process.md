# Release Process

This document is for maintainers of `wp-core-base`.

## Goal

Each release should represent a stable upstream state that downstream users can adopt deliberately.

## Checklist

1. update the baseline intentionally
2. run:

```bash
php tools/wporg-updater/tests/run.php
php tools/wporg-updater/bin/wporg-updater.php doctor
php tools/wporg-updater/bin/wporg-updater.php stage-runtime --output=.wp-core-base/build/runtime
```

3. update `README.md` if the baseline changed
4. update docs if user-facing behavior changed
5. cut a tag that matches the release policy
6. publish release notes that explain downstream impact

## Tag Format

Recommended tag shape:

- `v6.9.4.0`
- `v6.9.4.1`
- `v6.9.5.0`

The first three segments track the bundled WordPress core version. The last segment tracks framework revisions on top of that baseline.
