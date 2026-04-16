# Local Prerequisites

Most framework commands run through PHP.

Requires PHP 8.1 or newer. The framework is tested on PHP 8.1, 8.3, and 8.4.

That includes:

- `doctor`
- `stage-runtime`
- `sync`
- `framework-sync`
- `add-dependency`
- `remove-dependency`
- `list-dependencies`

If you use the shell launcher at `bin/wp-core-base`, it will check for a local PHP CLI first and print install help if PHP is missing.

There is no separate built-in WordPress `wp-cli` wrapper. Use the shipped PHP entrypoints directly or add your own alias if you want a shorter command name.

## Install PHP CLI

Common install examples:

- macOS with Homebrew: `brew install php`
- Debian or Ubuntu: `sudo apt install php-cli`
- other systems: install a recent PHP CLI and rerun the command

Once PHP is available, you can use either entrypoint:

```bash
bin/wp-core-base doctor
php tools/wporg-updater/bin/wporg-updater.php doctor
```

For vendored downstream installs, the same pattern applies:

```bash
vendor/wp-core-base/bin/wp-core-base doctor --repo-root=.
php vendor/wp-core-base/tools/wporg-updater/bin/wporg-updater.php doctor --repo-root=.
```
