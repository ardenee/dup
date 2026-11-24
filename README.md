# DUP

DUP is a web-based duplicate-file scanner and manager for Windows, Linux and Synology DSM. The same PHP application, scan engine, database and job worker run on all three platforms. DSM is a packaging/integration option, not a requirement of the application.

**Current development version: 0.7.6.** This is a major rebuild of DUP and a development preview, not a production-ready upgrade. The Synology package retains the original package ID `dup` and the `/dup/` URL. The new database schema does not migrate older profile data automatically. An upgrade must be tested using disposable data before replacing an existing installation.

**Licence:** MIT for DUP source code. Bundled dependencies retain their respective licences.

## What DUP does

- Creates and edits scan profiles for source folders, move destinations, file types and matching settings; supports enabling/disabling profiles while retaining scan history
- Browses filesystem folders available to the server's PHP account, without a separate allowlist configured during installation
- Runs persistent background scan jobs with progress, heartbeat, cancellation, and error reporting
- Supports four matching modes: file size, original CRC32 3 Part (150 KiB at start/middle/end of large files), full CRC32, and streamed SHA-256; size and CRC32 groups are explicitly marked as candidates
- Retains indexed files from ignored folders so ignore rules can be changed without a full rescan
- Indexes ordinary, directory and dangling symbolic-link metadata (including the raw target) without following links or counting them as potential duplicate savings; link records can be searched and exported
- Preserves prior successful scan snapshots if a later scan fails
- Provides general/duplicate/moved/linked/quarantined-file index searches and paginated cross-scan history of recorded file actions (including restores), duplicate-group browsing with collapsed inline file lists, independent file-list and group pagination, reports, historic runs and CSV exports. Profiles remain for scan configuration and jobs; browse matching files in Duplicates.
- Filters and pages complete file-action batch history by profile, operation and status, with recorded per-file rename results and errors. History remains available across later scans.
- Lets administrators remove old scans from Reports > Scan run after explicit confirmation, transactionally cleaning indexed file, directory and group records without touching filesystem files. Clearing the current completed scan archives older completed runs so they cannot silently become current results; the next scan repopulates them. Jobs, events and audit remain, while scans referenced by file-action/recovery records cannot be removed.
- Shows worker/queue diagnostics and authenticated system status
- Supports explicitly confirmed, reversible quarantine, folder-preserving same-device file moves, and symbolic-link replacement of fully SHA-256-verified duplicates. The CLI worker verifies file evidence, checks destinations, keeps recovery copies where needed, and records audited actions and restores.
- Provides filename cleanup previews, selected-file batch renaming directly from duplicate selections (with confirmation), and manual single-file renaming through the audited background-job worker, without overwriting existing paths. Rename previews can optionally assign available numbered suffixes (like `file (1).ext`) for destination collisions; the exact previewed names are approved and rechecked before use.
- Includes indexed file viewing, read-only image/PDF/text previews and streamed downloads, rejecting stale scan records and symbolic links
- Allows administrators to add, edit or remove unused file-type presets without resetting scan history
- Restores account management for administrators and read-only users: add, rename, change access level, reset passwords, disable and re-enable accounts while preserving audit history. Read-only users can search, browse duplicates, view reports and safely view/download indexed files but cannot mutate data or settings.
- Restores configurable file viewer preferences: enable/disable previews, approved image extensions, HTML-escaped text extensions and PDF inline viewing, without changing downloads or scan data.

Permanent deletion is deliberately unavailable pending further safety and recovery testing, including atomic no-overwrite race handling, restore failure injection, and DSM filesystem validation. Account removal disables login instead of physically deleting records referenced by audit history. Reversible moves are limited to 50 selected files per batch on the same filesystem, preserve the original hierarchy, and never overwrite. Symbolic-link replacement is available only for fully SHA-256-verified duplicate groups; the original is retained privately for restoration. File-action history covers records captured by DUP v2 (not unimported legacy database records). Rename supports selected batches of up to 50 files; this is not yet parity with every legacy rename option.

## Requirements

PHP 8.2 or later, with PDO, mbstring, intl, openssl, xml, ctype, iconv and json. Enable pdo_mysql for MariaDB/MySQL (recommended), or pdo_sqlite for a small local setup. MySQL 8 and MariaDB 10 are supported targets.

The PHP web account must be able to read the folders it browses. The background worker needs filesystem access to the scan sources; move and quarantine operations additionally require write permission. Grant access only to directories the application is intended to manage. Filesystem permissions, not the setup wizard, govern access. No scanner folder selection is required at installation.

## Install on a Windows or Linux web server

1. Extract the generic web ZIP into a private application directory.
2. Configure the web server to serve the application's `public/` directory with PHP enabled. Never expose `src/`, `vendor/`, `config/`, `var/` or `.env.local` over HTTP.
3. Provide a MariaDB/MySQL account with permission to create and manage the selected database. You do not need to create the database manually: the installer creates it when missing.
4. Open `https://your-host/setup.php`. Enter the desired database name (for example `dup2`), database credentials, and initial DUP administrator account. A missing database is created automatically; an existing populated database is never overwritten. No filesystem paths are required during installation.
5. Sign in, create a scan profile, browse for a source and destination folder, then start a scan.

The installer connects to MariaDB without selecting a schema if the requested database does not yet exist, then issues `CREATE DATABASE` with a validated, quoted database name. The supplied MariaDB user must have `CREATE` privilege to create a new database and the necessary schema permissions to initialize it. Prefer a dedicated database account with narrowly scoped privileges rather than permanently saving the MariaDB root account. If creation is denied, the wizard reports the privilege error instead of requiring a manual phpMyAdmin step.

The installer writes private configuration, creates the schema and administrator, and closes itself after successful setup. It refuses to overwrite existing private configuration or a database containing tables or views. Since the first administrator is created through the setup page, complete setup on a trusted server/network and do not leave an unconfigured installation publicly accessible.

Non-local HTTP page requests redirect to HTTPS; insecure form submissions are rejected. Localhost HTTP is supported for development. Reverse proxies must pass the original HTTPS scheme to PHP or explicitly configure trusted proxy IPs using `DUP_TRUSTED_HTTPS_PROXIES`. The optional `DUP_HTTPS_PORT` variable sets a non-standard HTTPS port.

For Windows, configure PHP on Apache or IIS/FastCGI and allow the relevant service account to access media drives and writable runtime storage. For Linux, use Apache or Nginx with PHP-FPM; a sample configuration is in `packaging/platform/`.

## Synology DSM 7

Install Web Station, PHP 8.2+ and MariaDB 10 first, then use Package Center's **Manual Install** with the DUP SPK. The package provides the Web Station portal `/dup/` and opens it over HTTPS. The PHP profile must enable pdo_mysql, mbstring, intl, iconv, openssl, fileinfo and xml.

Complete the same web setup wizard. DUP automatically creates a missing MariaDB database if the supplied account has CREATE privilege. This is suitable for a new database name such as `dup2`; a populated legacy `dup` database is refused rather than overwritten. DUP identifies Synology DSM in the readiness panel and defaults its MariaDB 10 port to **3307** rather than 3306. If TCP is unavailable, the installer can try the local MariaDB 10 socket at `/run/mysqld/mysqld10.sock` when accessible to the PHP profile. The readiness panel reports socket visibility, and connection failures show a safe driver error code instead of a generic message. A phpMyAdmin login alone does not verify that the PHP profile can connect to the same database and host.

Scan folders are selected when creating profiles. The Web Station PHP process runs as `dup:http`, while DSM may initially launch the package background worker as `dup:dup`. The worker must have access through the same `http` group permissions as the web process. The DSM `postinst` script grants supplementary `http` membership to `dup` when it runs with root privileges; existing installations can be repaired with `sudo /usr/syno/sbin/synogroup --memberadd http dup` followed by a worker restart. No per-user shared-folder ACL changes are required. Scan folders must use actual DSM paths such as `/volume1/...`, not Windows mapped-drive paths.

**Upgrade caveat:** The 0.7.6 package uses the same Synology package identity as previous DUP releases, but the redesigned application requires a fresh database. It does not automatically migrate older database schemas or profiles. Back up any installation you want to preserve and do not apply this preview over production data.

## Background processing

Run one cycle through cron, Task Scheduler or a service manager:

```bash
php bin/console dup:jobs:work --max-jobs=1 --env=prod
```

For a persistent worker:

```bash
php bin/console dup:jobs:work --loop --idle-sleep=5 --max-jobs=1 --env=prod
```

The Synology package includes its own service adapter using the same job-worker command.

## Development and preview packages

Development source requires Composer dependencies and a configured local database. Generic ZIP and DSM SPK builders package vendored dependencies; end users do not need Composer.

```bash
python packaging/build_preview.py
python packaging/build_dsm_preview.py --version 0.7.6 --php-backend 11
php tests/setup-database.php
```

The DSM build produces `DUP-DSM7-0.7.6-compat.spk`. Both outputs are development previews and must pass a real installation test before release. DSM Web Station PHP backend IDs vary between machines and must be verified for the target.
