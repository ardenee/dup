# DUP — Duplicate File Scanner & Manager

DUP is a web-based duplicate-file scanner and manager for **Windows, Linux, and Synology DSM 7**. It indexes files, identifies potential duplicates, and lets you review and manage them from a browser. Scans and file operations run as background jobs, with progress, history, and diagnostics.

**[Download the latest release](https://github.com/ardenee/dup/releases/latest)** · [Synology DSM 7 SPK](https://github.com/ardenee/dup/releases/download/0.7.22/DUP-DSM7-0.7.22.spk) · [Generic web-server ZIP](https://github.com/ardenee/dup/releases/download/0.7.22/DUP-Web-0.7.22.zip)

## Features

- **Scan profiles:** Set source and move folders, file types, minimum size, ignored folder names, and automatic schedules. Schedules may use presets or a custom repeat interval and UTC start time.
- **Duplicate detection:** Compare file sizes, CRC32 (three-part or full), and SHA-256 hashes. Verify candidate groups with SHA-256 before treating them as confirmed duplicates.
- **Explore and search:** Browse duplicate groups, expand matching files, search the index, inspect file details, and review retained scan results. Large result tables support filtering, sorting, and pagination.
- **File management:** Preview and confirm individual or bulk rename, move, link, quarantine, and permanent-delete operations. Use filename cleanup rules, Find/Replace, and per-profile destination collision policies.
- **History and recovery:** Review file-action history, restore supported reversible actions, and retain scan results independently from later file changes.
- **Job monitoring:** Track scan, verification, and file-action progress; inspect errors, logs, worker health, and recovery options.
- **Administration:** Manage user accounts, read-only access, system settings, and diagnostics.

> **Data safety:** Deleting files is permanent. Overwriting a destination permanently replaces it. A restore can reverse supported moves or quarantine actions but **cannot recover an overwritten file**. Check previews and keep backups before approving destructive changes. File-size and CRC32 matches are candidates, not SHA-256 verification.

## Requirements

- **PHP 8.2+**, including PDO, mbstring, intl, iconv, OpenSSL, XML/DOM, ctype, and hash.
- **MariaDB 10 or MySQL 8** recommended; SQLite is available for small local installations. Enable **pdo_mysql** when using MariaDB/MySQL.
- Apache, Nginx, or IIS with PHP, plus a **PHP CLI background worker**.
- Read access to scanned folders. Move, rename, link, or delete operations require suitable filesystem permissions.

### Windows / Linux web-server installation

1. Download and extract **DUP-Web-0.7.22.zip**.
2. Configure your web server's document root to **`dup/public/`**. Keep the application's `src`, `config`, `vendor`, and `var` directories outside the public document root.
3. Open **`https://your-host/setup.php`** and follow the setup wizard to configure the database and create an administrator.
4. Configure a PHP CLI worker using a service manager, scheduled task, or cron. From the application root:

   ```sh
   php bin/console dup:jobs:work --loop --idle-sleep=5 --max-jobs=1 --env=prod
   ```

   For a single-job invocation from a scheduler:

   ```sh
   php bin/console dup:jobs:work --max-jobs=1 --env=prod
   ```

5. Sign in, create a scan profile, select accessible folders, and start a scan.

Use HTTPS for remote access. Ensure both the web-server account and CLI worker can access the configured scan locations.

### Synology DSM 7 installation

1. Install **Web Station**, **PHP 8.2 or later**, and **MariaDB 10**.
2. Enable the required PHP extensions, particularly **pdo_mysql**, in the Web Station PHP profile used by DUP.
3. In **Package Center → Manual Install**, select **DUP-DSM7-0.7.22.spk**.
4. Open DUP at **`https://your-nas/dup/`**. Complete the first-run setup or sign in after upgrading an existing DUP v2 installation.
5. Configure scan profiles and DSM filesystem permissions. The SPK manages the background worker; ensure the **http** group and worker account have the required access.

**Upgrading:** Back up the database and DUP's persistent data first. Upgrading an existing **DUP v2** installation preserves its data and applies applicable migrations. The original pre-v2 database is **not automatically migrated**; use a separate database for a fresh v2 installation.

## Source and license

This repository contains the generic PHP application and the packaging scripts for web servers and Synology DSM 7. The official ZIP and SPK already include runtime dependencies.

DUP is distributed under the **MIT License**. Third-party libraries retain their respective licenses.
