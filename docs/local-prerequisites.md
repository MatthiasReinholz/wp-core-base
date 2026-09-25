# Local Prerequisites

Most framework commands run through PHP.

Use PHP 8.4 or 8.5 for local and CI operations. PHP 8.1 syntax compatibility remains tested for migration, but PHP 8.1 has reached end of life and is not a recommended operational runtime. CI also tests PHP 8.3.

Required extensions are `curl`, `dom`, `json`, `libxml`, `mbstring`, `openssl`, `simplexml`, and `zip`. Some distributions enable JSON, libxml, and OpenSSL in their core PHP packages. Git must be on `PATH`; `doctor` verifies the actual installed extensions and Git environment. The WordPress smoke fixture additionally needs `mysqli` and a disposable local MariaDB database.

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
- Debian or Ubuntu: `sudo apt install php-cli php-curl php-xml php-mbstring php-zip`
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

## CLI argument format

Values use `--name=value`; boolean flags have no assigned value. For example, use `--repo-root=.` and `--force`, never `--force=false`. Unknown, duplicate, empty, or command-inapplicable options fail before configuration is loaded or repository state is changed. `help <command>` lists accepted options from the same schema used by the parser.
