# DUP

DUP is a web-based duplicate-file scanner and manager for **Windows, Linux, and Synology DSM 7**. It uses PHP, a database and a background job worker. Synology packaging is optional; the core application is platform-independent.

## Features

- Create scan profiles with source and move folders, file-type filters, minimum file sizes, per-profile ignored folders and automatic scan schedules.
- Find duplicates using file size, CRC32 (three-part or full), or SHA-256. Optionally verify candidate duplicate groups using SHA-256 without rescanning unrelated files.
- Browse current and historical scans, search indexed files, and preview/download supported file types. **Sort and filter** large, paginated data tables across all matching records (duplicates, search, scan history, jobs, file actions and rename previews). Smaller configuration tables also support sortable columns and quick row filtering.
- Rename (with cleanup rules and Find/Replace), move, delete and link duplicate files, individually or in bulk.
- Set **File Exists** separately for each profile: Fail, Append Number or Overwrite.
- **Scan profiles:** Compact, sortable/filterable scan profile management combines scan configuration, completed scans, scheduling, duplicate results, status and a horizontal action toolbar. A job with a heartbeat overdue by ten minutes is clearly labelled unresponsive and can be diagnosed or explicitly resolved from the profile row without deleting historical scans. The administrator-only Worker diagnostics page shows diagnostic storage permissions and worker file-log availability.
- **Reliable job completion:** File actions retain approved totals, distinguish processed items from succeeded/failed results and refuse to silently skip missing records. Rebuild results are labelled as duplicate groups, not deleted files. An uncaught worker exception finalizes the job and flags unfinished filesystem operations for review; a failed finalization stops that worker from taking another job.
- **MySQL heartbeat reliability:** Job lease validation uses the actual running state and worker token, not the affected-row count of a same-second timestamp update. This prevents a false LostLease at the start of SHA-256 verification; both scan and SHA-256 progress accept only *verified* no-op updates while rejecting genuinely changed leases or unsaved progress. The independent debug log identifies same-second heartbeat skips and unchanged verification progress. Real lease errors use distinct, searchable codes.
- **Distinct, traceable error codes:** Every migrated failure type is identified by a stable component-specific code (e.g. `DUP-HASH-105` for a changed file size versus `DUP-VERIFY-11001` for a candidate database-query error). Worker crash/timeout/cancellation failures are separately coded for all 15 job types. The failed job stores its actual root code, exception, source file/line and an occurrence-specific trace ID; it no longer replaces the cause with `WORKER_PROCESS_EXITED`. The Job Monitor links codes to **Error Code Reference**. Older and not-yet-migrated subsystems can still have legacy codes.
- **Responsive SHA-256 cancellation (DSM):** Cancelling a running verification signals only its owning DSM worker through a private supervisor control file. The supervisor attempts TERM and, after eight seconds without exit, KILL, then records the job's terminal state and restarts the worker. If a job was already stuck cancelling, the Job Monitor and detail page expose a force-stop action, alongside manual recovery for an already-dead worker. Generic hosting continues to use cooperative cancellation.
- **Verified debug logging:** Worker startup checks that its independent log is writable *before claiming any job* and reports failures to stderr/DSM service output instead of silently losing diagnostics. In **Job Monitor → Worker diagnostics**, **Test debug logging now** writes a test record from Web Station PHP and confirms it can be read back, displaying any failure. The interface lists the most recent per-job logs directly; CLI worker.boot is a separate check confirming the background process can write. The two accounts may have different permissions.
- **Detailed debug logs:** SHA-256 verification and worker lifecycle steps are recorded to private rotating JSONL files without database writes. Logs include SQL before/after timings, candidate queries, file checks, per-file hashing and byte updates, exception stack traces, memory and PID. Open **Job Monitor → Debug log** for a particular job or **Worker diagnostics** to view and download logs. DSM additionally mirrors CLI stderr/PHP fatals and supervisor restarts into separate admin-readable log files. The debug viewer does not query the database and remains available when an SQL operation is blocked.
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

1. Extract the generic **DUP-Web-0.7.20.zip** package into a private directory.
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
2. Open **DSM Package Center → Manual Install** and select **DUP-DSM7-0.7.20.spk**.
3. Open DUP at **/dup/** over HTTPS. Complete the setup wizard for a new installation, or sign in after an existing DUP v2 upgrade.
4. Configure scan profiles, file-access permissions and schedules. The DSM package manages its background worker.

On DSM, the web process uses the http group. The background worker must also have the necessary folder permissions. Synology MariaDB 10 commonly uses TCP port 3307, or its local socket if enabled.

**Upgrades:** Back up the DUP database and persistent package data before upgrading. Existing **DUP v2** installations receive pending database migrations and retain their data. The original **pre-v2 DUP database is not automatically converted**; use a separate, new database when replacing that version. Confirm filesystem permissions and test destructive actions on noncritical files before using them on important data.

## Building from source

The release packages are built using:

- Python: packaging/build_release.py (generic ZIP)
- Python: packaging/build_dsm_release.py --version 0.7.20 (Synology SPK)

The published ZIP and SPK contain the application and required dependencies, but do not include the repository test suite, runtime data or developer utilities.

## License

DUP is licensed under the **MIT License**. Bundled third-party components retain their respective licenses.
