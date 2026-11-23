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

The DSM SPK is a **development candidate**, not a tested upgrade for an existing package. Back up the database and private configuration before installing or replacing anything on DSM. Do not manually run the existing installer against an active database: setup is restricted to first installation, and destructive recreation is blocked once configuration exists.

Regenerate DSM icons, if required, with `python packaging/make_icons.py` (Pillow required).

## Security and development status

**Keep DUP on a trusted network. Do not expose it to the public internet yet.** The legacy account login uses an MD5-derived cookie credential, and full session/password/CSRF migration is still needed. File-action path validation and permissions also need a security audit.

Completed baseline: identify the primary DEV/GitHub source, restore the missing action dispatcher, move the DSM configuration/logs/backups outside the HTTP root, remove public legacy copies, protect destructive setup, and establish a common generic-web/DSM package builder.

Remaining work: modern sessions and password hashes, CSRF and filesystem authorisation, non-destructive database migrations, profiling/indexes and lower-cost logging, modular PHP services, asynchronous workers, UI consistency and usability, and full end-to-end regression tests.

Source: https://github.com/ardenee/dup
