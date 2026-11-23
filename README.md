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

- Multiple scan profiles with folder and extension filters and ignored-folder settings.
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

The DSM SPK is a **development candidate**, not a tested upgrade for an existing package. Back up the database and private configuration before installing or replacing anything on DSM. New installation refuses to overwrite a database containing existing tables and requires a newly chosen administrator password. Existing installations require versioned migrations.

Regenerate DSM icons, if required, with `python packaging/make_icons.py` (Pillow required).

## Security and development status

**Keep DUP on a trusted network. Do not expose it to the public internet yet.** Session authentication, legacy-hash upgrade-on-login, login throttling, CSRF and administrator checks, path restrictions for file actions, and CLI-only worker entry points have been implemented in DEV and passed synthetic PHP/HTTP tests.

**Deployment status:** These authentication changes are staged on the security development branch, **not yet installed on DSM**. The existing DSM database's password column is `VARCHAR(50)`; it must first be backed up and migrated to `VARCHAR(255)`. Until that migration and live login verification complete, DSM still uses the old cookie login. Do not copy this branch to a live server or install its ZIP/SPK over an existing installation.

Completed baseline: reconciled DEV/GitHub/DSM files, restored the missing action dispatcher, moved DSM private runtime data outside the HTTP root, and introduced a verified shared generic-web/DSM package builder. The hardening branch also removes per-request schema changes and late log-attribution updates.

Remaining work: approve and verify the live account-column migration, test browser login and admin account changes on DSM, finish auth/CSRF coverage, review file-action edge cases, build a non-destructive upgrade migration system, profile scans and queries, improve log efficiency, and finish modular architecture/UI improvements.

Source: https://github.com/ardenee/dup
