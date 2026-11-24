# DUP

DUP is a web-based duplicate-file scanner and manager for **Windows, Linux, and Synology DSM 7**. It uses PHP, a database and a background job worker. Synology packaging is optional; the core application is platform-independent.

## Features

- Create scan profiles with source and move folders, file-type filters, minimum file sizes, per-profile ignored folders and automatic scan schedules.
- Find duplicates using file size, CRC32 (three-part or full), or SHA-256. Optionally verify candidate duplicate groups using SHA-256 without rescanning unrelated files.
- Browse current and historical scans, search indexed files, and preview/download supported file types. **Sort and filter** large, paginated data tables across all matching records (duplicates, search, scan history, jobs, file actions and rename previews). Smaller configuration tables also support sortable columns and quick row filtering.
- Rename (with cleanup rules and Find/Replace), move, delete and link duplicate files, individually or in bulk.
- Set **File Exists** separately for each profile: Fail, Append Number or Overwrite.
- **Synology worker supervision:** Restart CLI workers after unexpected PHP exits, record exit code and unfinished job failure details by worker PID, and show a bounded, admin-only process log under Job Monitor → Worker diagnostics. The supervisor checks the CLI PHP version and pdo_mysql/mbstring extensions before starting. SHA-256 candidate verification reads candidate paths in pages of 64 and logs each access/check step.
- Manage background jobs directly: cancel queued or running scans, resolve an overdue cancellation whose worker no longer reports, retry failures, or delete finished scan/verification job logs with confirmation. Stale resolutions are audited, never terminate OS processes and do not delete scan results.
- Monitor live job progress and independent worker/job heartbeats with automatic/manual refresh. SHA-256 verification shows the current candidate file, completed-file and byte counts, per-file timing, and failure details. Heartbeat freshness is not proof that hashing is advancing; stale-job recovery is an explicit administrator action after inspection. Retry failed scans and review reports, scan history and detailed action logs; clean up old scan results, finished jobs and resolved action records.
- Manage administrator and read-only accounts, and view system diagnostics including required PHP extensions and database schema status.

**Important:** Delete permanently removes the selected file. Overwrite permanently replaces an existing destination after the user approves it. These actions cannot be undone. A moved source can be returned to its original path, but restoring a move does not recover a destination that was overwritten. Maintain backups and review affected files before confirming destructive actions.

## Requirements

- PHP **8.2 or later**, with PDO, mbstring, intl, iconv, openssl, XML/DOM, ctype and hash. Enable pdo_mysql for MariaDB/MySQL.
- MariaDB 10 or MySQL 8 is recommended; pdo_sqlite is supported for small local installations.
- PHP-enabled web server and a PHP CLI worker with access to the scan folders. File actions need appropriate write/delete permissions.
- **Synology DSM 7:** Install **Web Station**, **PHP 8.2+** and **MariaDB 10** first. Enable pdo_mysql in DUP's Web Station PHP profile.

## Windows or Linux installation

1. Extract the generic **DUP-Web-0.7.14.zip** package into a private directory.
2. Point Apache, IIS, or Nginx at its **dup/public/** directory. Never publicly expose src, vendor, config, var, or private environment files.
3. Open **https://your-host/setup.php**. Provide the database connection and create the first administrator. A missing database can be created if the supplied database account has the required privileges.
4. Sign in, create a scan profile, select accessible folders, and start a scan.
5. Configure the background worker with a service manager, Task Scheduler, or cron. From the private application directory, a persistent worker runs with:

        php bin/console dup:jobs:work --loop --idle-sleep=5 --max-jobs=1 --env=prod

   For scheduled, single-run processing instead use:

        php bin/console dup:jobs:work --max-jobs=1 --env=prod

Use HTTPS for remote access. The web and worker accounts must have the appropriate source and move-folder permissions.

## Synology DSM 7 installation

1. Install Web Station, PHP 8.2 or later and MariaDB 10, and enable the necessary PHP extensions.
2. Open **DSM Package Center → Manual Install** and select **DUP-DSM7-0.7.14.spk**.
3. Open DUP at **/dup/** over HTTPS. Complete the setup wizard for a new installation, or sign in after an existing DUP v2 upgrade.
4. Configure scan profiles, file-access permissions and schedules. The DSM package manages its background worker.

On DSM, the web process uses the http group. The background worker must also have the necessary folder permissions. Synology MariaDB 10 commonly uses TCP port 3307, or its local socket if enabled.

**Upgrades:** Back up the DUP database and persistent package data before upgrading. Existing **DUP v2** installations receive pending database migrations and retain their data. The original **pre-v2 DUP database is not automatically converted**; use a separate, new database when replacing that version. Confirm filesystem permissions and test destructive actions on noncritical files before using them on important data.

## Building from source

The release packages are built using:

- Python: packaging/build_release.py (generic ZIP)
- Python: packaging/build_dsm_release.py --version 0.7.14 (Synology SPK)

The published ZIP and SPK contain the application and required dependencies, but do not include the repository test suite, runtime data or developer utilities.

## License

DUP is licensed under the **MIT License**. Bundled third-party components retain their respective licenses.
