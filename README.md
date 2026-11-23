# DUP v2

DUP is a PHP duplicate-file scanner and manager for **Windows web servers, Linux web servers, and Synology DSM**. The PHP application, database schema, web interface and job worker are shared. Synology is an optional deployment adapter—not the application's operating system.

**Licence:** MIT for the DUP project code (see [LICENSE](LICENSE)). Bundled third-party packages retain their own licences, supplied with each dependency.

**Development preview, not a production release.** DUP v1 remains in GitHub `main`; v2 is developed on `v2/rebuild`. Installing v2 is a separate package action and does not migrate v1 data.

## Features implemented

- Modular Symfony 7.4 LTS and Twig interface; Doctrine DBAL/Migrations, Symfony Security and Monolog
- Fresh database, administrator, profile types, allowed directory roots, directory chooser, exact folder-name ignore rules
- Persistent file scans and background jobs with progress, heartbeats, cancellation and stalled-job recovery
- Streamed SHA-256 duplicate verification; size-only matching explicitly marked *unverified*
- Indexed files from ignored directories retained; changing ignore rules regenerates duplicate groups without rescanning
- Snapshot preservation: a failed scan or recalculation never destroys the last completed results
- Duplicate browser with indexed keyset pagination, job history, events and system diagnostics
- Reports workspace: per-profile latest successful snapshots, historical run navigation, stored file states, scan warning/error events and potential duplicate savings
- Streamed UTF-8 CSV exports (all indexed files, duplicate memberships, file states and job events) with spreadsheet formula-injection protection
- Authenticated /api/system/status diagnostics and 15-second live-refreshing System page for worker heartbeats, backlogs, failures and manual-recovery flags
- Explicitly confirmed **reversible quarantine and restore** via the same CLI job queue, with SHA-256 re-verification of both the selected file and another surviving copy, before any move
- Same-volume rename only (no copy/delete fallback), unique private quarantine directories, collision protection and audit history
- Automatic duplicate-group regeneration after quarantine or restoration, without rescanning source files
- Private, one-time pairing-code setup with PHP extension, storage, database and admin checks

**Not implemented:** permanent deletion, scheduled report delivery, automatic upgrades and validated production deployment under Linux Apache/Nginx or Synology DSM. Permanent deletion is intentionally disabled until further safety and recovery testing. Do not replace a production DUP installation yet.

## Deployment — all platforms

The **generic web ZIP is the primary application**. It contains all PHP dependencies. The core reads configuration from the web server or private `.env.local`; database URLs preserve encoded credentials on all platforms. Composer, Node.js, Docker, systemd, Task Scheduler and Synology packages are **not required by the application itself**.

Requirements: PHP **8.2+** with `pdo`, `mbstring`, `intl`, `openssl`, `xml`, `ctype`, `iconv` and `json`. Enable `pdo_mysql` for MariaDB/MySQL (recommended) or `pdo_sqlite` for a small local/test database. MySQL 8/MariaDB 10 are supported targets. Disposable database and browser integration tests have passed with MariaDB 10.11 on Windows PHP 8.5 and Ubuntu PHP 8.3. Production server integration remains to be verified.

1. Unpack the generic ZIP to a **private application directory** on the web server. Create a dedicated, initially empty database and database user with schema creation permissions. The application will not overwrite populated databases.
2. Point the web server's **document root to the application's `public/` directory**. Never expose `vendor/`, `src/`, `migrations/`, `config/`, `var/` or `.env.local` over HTTP.
3. Open **`https://your-site/setup.php`** (or `http://127.0.0.1:port/setup.php` for localhost development). Obtain the one-time pairing code from the private `var/setup.token` file using your server's file manager or the CLI command below.
4. Fill out the single setup form: empty database, administrator and allowed scan directories. It creates the schema, private `.env.local`, admin hash and `var/setup.complete`, then invalidates the pairing code.
5. Sign in, create a profile and configure a job worker using the **same PHP command on every platform**.

```bash
php bin/setup-token.php
```

Only a fresh installation accepts setup. DUP redirects non-local HTTP GET/HEAD requests to HTTPS and rejects insecure form submissions; localhost development HTTP is allowed. On HTTPS reverse proxies, configure the PHP gateway to report HTTPS to PHP, or set `DUP_TRUSTED_HTTPS_PROXIES` to the comma-separated IP addresses of the trusted TLS proxies (in the server/PHP environment) to honour their `X-Forwarded-Proto` header. To use a nonstandard HTTPS port on a generic server, set `DUP_HTTPS_PORT` in the PHP process environment (default: 443). The installer will not overwrite an existing `.env.local`, any populated database, or installed administrator.

`DUP_ALLOWED_SCAN_ROOTS` is a semicolon-separated list of directories that the PHP process can read. The setup wizard validates and saves them using the host's **actual filesystem paths**. A Windows machine may use `D:\\Music`, Linux `/srv/media`, DSM `/volume1/Music`. A Windows-mapped `Y:\\` drive on the development PC is *not* a path on a Synology PHP worker. The folder picker and job queue enforce these roots. No roots means no browsing/scanning.

### Windows web servers

Use PHP 8.2+ with IIS/FastCGI and URL Rewrite, or Apache. Generic ZIP first-run MariaDB installation, scanning and reports have been tested with Windows PHP 8.5; IIS and Apache deployment still need live validation. Set the site document root to `C:\\Sites\\dup-v2\\public` (or your chosen private path). The ZIP includes `public/web.config` for IIS and `public/.htaccess` for Apache. Configure the web server to serve PHP and give the application identity write permission to `var/`.

### Linux web servers

Use Apache/mod_rewrite or Nginx/PHP-FPM and a private install such as `/srv/dup-v2`. The generic ZIP has passed fresh MariaDB installation and application tests on Ubuntu 24.04 with PHP 8.3; Nginx/Apache and a production service manager still need validation. Set document root to `/srv/dup-v2/public`. A sample Nginx configuration is at `packaging/platform/linux-nginx.conf.example`. Give the PHP worker read access only to selected scan roots and writable access to the private runtime directory.

### Synology DSM 7 (optional)

The **0.7.3 compatibility-preview SPK** uses package ID `dupv2` and a `/dup-v2/` Web Station portal. DUP v1 is a separate package; this installer does not modify v1 data. Its PHP application stays under the private package target; only `index.php`, `setup.php` and static assets are copied into Web Station. Its lifecycle scripts run the **same PHP job worker** as Windows/Linux, and store credentials/runtime state under the persistent package `var` directory.

DSM Web Station, PHP 8.2+ and MariaDB 10 are required by the current SPK metadata. The PHP profile must enable **iconv**, **pdo_mysql**, mbstring, intl and XML. Backend IDs may differ across NAS models. The 0.7.3 compatibility-preview retains HTTPS redirects, Package Center's HTTPS Open link, the original DUP icon, corrected setup-page padding, and the MIT licence. Because DSM rejected an earlier build as an invalid file format, the package temporarily omits the DSM Main Menu shortcut registration and reuses the earlier installable SPK's USTAR layout and minimal package metadata. DSM installation acceptance is still unverified; only install this as a test. The rewrite has passed standalone Nginx tests but still needs live Synology Web Station validation. On an existing installation, inspect the **DUP v2 PHP** profile under **Web Station → Script Language Settings → PHP → Extensions** to confirm iconv is enabled before completing setup. Do not assume that updating the package always overrides an existing customised PHP profile.

## Background jobs — all platforms

For shared hosting, cron or Windows Task Scheduler can invoke one worker cycle at a time:

```bash
php bin/console dup:jobs:work --max-jobs=1 --env=prod
```

For a persistent service, use the cross-platform PHP loop:

```bash
php bin/console dup:jobs:work --loop --idle-sleep=5 --max-jobs=1 --env=prod
```

Windows Task Scheduler can launch the PHP CLI executable with that command and the application directory as its **working directory**. Linux may use systemd (sample: `packaging/platform/linux-systemd.service.example`), cron, Supervisor or another process manager. DSM's SPK provides its own service adapter. The application never assumes any one supervisor is installed.

CLI management:

```bash
php bin/console dup:scan:queue 1
php bin/console dup:jobs:cancel 1
php bin/console dup:jobs:recover --after=120
php bin/console dup:settings:ignore --names="@eaDir,#recycle"
```

Open **Duplicates → a verified group → Preview quarantine** to plan a reversible action. Only a separate, explicit confirmation queues it. The CLI worker then verifies both the selected file and another surviving copy before a same-volume rename. Every quarantined file may be restored from **File actions**, unless the original path has become occupied.

Quarantined files live beneath the profile's private `.dup-quarantine` folder, which is always excluded from duplicate matching. Hard delete and cross-filesystem copy-and-delete are intentionally unavailable. The action history and audit records work across Windows, Linux and DSM.

Open **Reports** for latest-successful-scan storage totals, historical scans (25 per page, bidirectional cursor pagination), and per-run diagnostics. Reported potential recoverable bytes are NOT space already freed. SHA-256 verified groups and unverified size-only candidates are reported separately. Per-profile totals can double-count files if profile roots overlap. CSV exports read only stored database metadata; scans are not rerun. Ignored-folder file rows remain exportable for later policy changes. Scan failures can be found in the job events export even when a scan aborted before indexing a file.

The authenticated **System** page refreshes queue and worker counters every 15 seconds and reports stalled jobs and file actions requiring manual review. `/api/system/status` is administrator-only; public `/health` returns a redacted readiness status.

`/system` reports the operating-system family and recent worker heartbeats, without coupling to an OS service API. `/health` exposes only a redacted ready/setup-required status.

## Developer setup

For local source development, install Composer on DEV (not on end-user hosts):

```bash
composer install
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console dup:admin:create admin
php -S 127.0.0.1:8088 -t public public/index.php
```

Development database/administrator setup above is for an existing `.env.local`. For a *new extracted deployment*, use the one-page `public/setup.php` installer instead; its database and configuration must be empty.

## Build previews

```bash
python packaging/build_preview.py
python packaging/build_dsm_preview.py --version 0.7.3 --php-backend 11  # produces DUP-v2-DSM7-0.7.3-compat.spk
```

Both builders include vendored dependencies, verify the package contents, and exclude local databases and secrets. These are **preview artifacts only**, not releases. All regression fixtures and temporary outputs remain offline.

A fresh target database is used for DUP v2. There is no migration of v1 profiles or scan data.
