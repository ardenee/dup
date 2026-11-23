# DUP

DUP is a browser-based duplicate-file scanner and file manager. It indexes folders into a database, groups potential duplicates by file size or CRC32, and provides file search, profile review, logs, and queued rename, move, delete and link operations.

## Runtime requirements

- Generic web server: PHP 8.2+, PDO, `pdo_mysql`, sessions, `mbstring`, and MariaDB 10.
- Synology DSM 7: Web Station, PHP 8.2+ and MariaDB 10 installed first. Enable `pdo_mysql` in the package's Web Station PHP profile.
- Permissions to read profile scan folders; write permissions are needed only for actions explicitly requested by the user.
- PHP CLI must be available for background scanning and file-action jobs.

The developer lint baseline is PHP 8.5.12. The current DSM test instance runs PHP 8.2.35. This is a compatibility baseline, not proof that all functions have been fully tested on both versions.

Existing SQLite installations have legacy support in the code, but **new SQLite installation is temporarily disabled** while the schema and runtime paths are modernised. MariaDB is the supported installation choice.

## Private runtime storage

**Do not store database credentials, SQLite data, logs, or backups under an HTTP-accessible directory.**

- On Synology DSM, DUP automatically uses `/var/packages/dup/var` for private data, configuration, logs and backups. The physical path can resolve to `/volume1/@appdata/dup`.
- On a generic web server, set the `DUP_DATA_DIR` environment variable to a persistent directory **outside all web document roots** with read/write permission for the PHP/CLI user.
- If no private directory is configured outside DSM, DUP falls back to a uniquely named directory under the system temporary directory; configure a stable `DUP_DATA_DIR` before using the application permanently.
- The installer writes `config.local.php` inside the private directory, not the web root. A legacy web-root config is still read as a migration fallback until it has been moved.

The generic distribution and SPK intentionally exclude local secrets, SQLite database files, plain SQL downloads, stored logs, old project copies and runtime backups. A PHP-returned SQL schema is used during new MariaDB setup.

## Features

- Multiple scan profiles with extension filters and configurable ignored-folder names. Files in ignored folders stay indexed; matching and duplicate totals use the current ignore settings.
- File-size, sampled CRC32, and full CRC32 duplicate detection.
- Search, moved files, duplicate groups, and per-profile file review.
- Queued file operations, scan status, logging and a built-in interface.

**A matching size or CRC32 is not definitive proof that two files are identical.** Confirm files before deleting or replacing them.

## Building packages

Run from the repository root with Python 3.10+:

```sh
python packaging/build.py --version 0.7.0
```

The builder verifies both outputs against the same application files:

- `dist/DUP-Web-0.7.0.zip` — generic PHP web application.
- `dist/DUP-DSM7-0.7.0.spk` — the same PHP application with Synology Web Station metadata, icons and install scripts.

The DSM SPK is a **development candidate**, not a tested upgrade for an existing package. Back up the database and private configuration before installing or replacing anything on DSM. New installation refuses to overwrite a database containing existing tables and requires a newly chosen administrator password.

For an existing MariaDB installation, run this with a PHP CLI executable after preserving a full database backup, **before deploying the session-authentication update**:

```sh
php bin/migrate.php --check
php bin/migrate.php --apply
php bin/migrate.php --check
```

The CLI migration is additive and repeatable. It saves an original accounts-table backup in MariaDB, verifies that account records and hashes match, widens the password field to 255 characters if needed, and fills in missing logging columns/indexes. It does not alter existing account passwords or erase scan results. There is **no automatic migration during web requests or DSM SPK upgrades**.

Regenerate DSM icons, if required, with `python packaging/make_icons.py` (Pillow required).

## Security and development status

**Keep DUP on a trusted network. Do not expose it to the public internet yet.** Session authentication, legacy-hash upgrade-on-login, login throttling, CSRF and administrator checks, path restrictions for file actions, and CLI-only worker entry points have been implemented in DEV and passed synthetic PHP/HTTP tests.

**Deployment status (23 November 2026):** The authentication and administrator-guard code was deployed directly to DSM after an account migration from `VARCHAR(50)` to `VARCHAR(255)`. The user confirmed successful administrator login. The new duplicate-filter and pagination code was subsequently synchronized to `Y:\dup`; matching and SQL count expressions were verified against DSM MariaDB, while profile rendering and large-page behaviour passed isolated tests. The DSM database currently has no profiles, so real profile scans and file operations are not yet live-tested. Rollback copies are kept offline; the **DSM SPK upgrade mechanism has not been installed or validated**.

Completed baseline: reconciled DEV/GitHub/DSM files, restored the missing action dispatcher, moved DSM private runtime data outside the HTTP root, and introduced a verified shared generic-web/DSM package builder. The hardening branch also removes per-request schema changes and late log-attribution updates.

Remaining work: verify authenticated account management, real profile scans and file-action operations on DSM; support individual duplicate groups exceeding the 990-file page budget, refresh unknown CRC values from old ignored-folder scans where needed, and finish remaining auth/CSRF coverage, logging efficiency and UI fixes.

Source: https://github.com/ardenee/dup
