# DUP

DUP is a browser-based duplicate-file scanner and file manager. Profiles scan folders, index metadata in a database and group potential duplicates by size or CRC32. The web UI provides searches, per-profile review, logs, and queued rename, move, delete and link operations.

## Platforms

- **Generic PHP web server** (primary distribution): PHP 8.2+, PDO and pdo_mysql, MariaDB 10, and a web server with permission to access the directories being scanned.
- **Synology DSM 7** (optional generated package): Web Station, PHP 8.2+ and MariaDB 10 installed first; enable **pdo_mysql** in the Web Station PHP profile. The package uses a Web Station service and runs the same PHP source as the generic ZIP.
- Local DEV lint baseline: PHP 8.5.12. MariaDB is preferred for large libraries. Legacy SQLite support still requires installation/schema and compatibility verification.

A working web PHP profile should provide PDO, pdo_mysql, mbstring and sessions. Enable curl, fileinfo and the necessary SQLite extensions if using those capabilities.

## Features

- Profile-based recursive scans, extension and size filters, and ignored-folder settings
- File-size, sampled CRC32 and full CRC32 duplicate modes (CRC32 is not a cryptographic hash)
- Browsing and searching file records, duplicate groups and moved files
- Queued rename, move, delete and symbolic-link operations
- Per-profile scan state, operational logs and account-based access

**Important:** A match by size or sampled CRC32 is not proof that two files are identical. Confirm content before deletion or replacement.

## Installation

For a generic web server, extract the web ZIP into a site directory and configure the PHP runtime, MariaDB 10 and filesystem permissions. Open `setup.php` for a **new installation only**. Initial setup recreates the `dup` database, so never run it against a database containing data you intend to retain.

DUP stores local DB configuration in `config.local.php` (ignored by Git); do not commit credentials. The web server needs write permission for `logs/` and `backup/`, and only the file access required for chosen scan folders. Restrict web access to a trusted network while the security modernisation is underway.

The current code uses legacy MD5-derived authentication cookies and ageing JavaScript dependencies. **It is not yet suitable for exposure to the public internet.** Database migrations, sessions/CSRF protection, filesystem authorisation and private storage isolation are on the hardening roadmap.

## Building packages

From the repository root, using Python 3.10 or newer:

```sh
python packaging/build.py --version 0.7.0
```

The script builds and verifies:

- `dist/DUP-Web-0.7.0.zip` — generic PHP web application
- `dist/DUP-DSM7-0.7.0.spk` — the same application files plus Synology Web Station metadata and package scripts

Builds exclude `config.local.php`, personal databases, logs, backups, the historical `orignal/` reference and other development-only content. This is a **staging build**; SPK installation and upgrade are not yet certified. Do not install it over an existing DSM instance without preserving its live configuration and database first.

To regenerate DSM icons from `images/dup.png` (Pillow required), run `python packaging/make_icons.py` before building.

## Development status

The active DEV checkout is `dup/` on branch `main`; `dup2/` is an old reference copy, not the release source. Work is focused on securing authentication and file actions, introducing non-destructive migrations, improving database indexes and worker throughput, simplifying the PHP modules, and replacing outdated interface libraries. Existing scan records and ignored-folder semantics must be preserved through upgrades.

Source: https://github.com/ardenee/dup
