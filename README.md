# DUP v2

DUP is a PHP duplicate-file scanner and manager for **Windows web servers, Linux web servers, and Synology DSM**. The PHP application, database schema, web interface and job worker are shared. Synology is an optional deployment adapter—not the application's operating system.

**Development preview, not a production release.** DUP v1 stays on GitHub `main` and the live DSM installation; v2 is developed on `v2/rebuild`.

## Features implemented

- Modular Symfony 7.4 LTS and Twig interface; Doctrine DBAL/Migrations, Symfony Security and Monolog
- Fresh database, administrator, profile types, allowed directory roots, directory chooser, exact folder-name ignore rules
- Persistent file scans and background jobs with progress, heartbeats, cancellation and stalled-job recovery
- Streamed SHA-256 duplicate verification; size-only matching explicitly marked *unverified*
- Indexed files from ignored directories retained; changing ignore rules regenerates duplicate groups without rescanning
- Snapshot preservation: a failed scan or recalculation never destroys the last completed results
- Read-only duplicate browser with indexed keyset pagination, job history, events and system diagnostics
- Private, one-time pairing-code setup with PHP extension, storage, database and admin checks

**Not implemented:** file actions, a complete report/export workspace, automatic upgrades and validated installation on an actual Linux/DSM host. Do not replace a production DUP installation yet.

## Deployment — all platforms

The **generic web ZIP is the primary application**. It contains all PHP dependencies. Composer, Node.js, Docker, systemd, Task Scheduler and Synology packages are **not required by the application itself**.

Requirements: PHP **8.2+** with `pdo`, `mbstring`, `intl`, `openssl`, `xml`, `ctype`, `iconv` and `json`. Enable `pdo_mysql` for MariaDB/MySQL (recommended) or `pdo_sqlite` for a small local/test database. MySQL 8/MariaDB 10 are supported targets; production database integration testing is still pending.

1. Unpack the generic ZIP to a **private application directory** on the web server. Create a dedicated, initially empty database and database user with schema creation permissions. The application will not overwrite populated databases.
2. Point the web server's **document root to the application's `public/` directory**. Never expose `vendor/`, `src/`, `migrations/`, `config/`, `var/` or `.env.local` over HTTP.
3. Open **`https://your-site/setup.php`** (or `http://127.0.0.1:port/setup.php` for localhost development). Obtain the one-time pairing code from the private `var/setup.token` file using your server's file manager or the CLI command below.
4. Fill out the single setup form: empty database, administrator and allowed scan directories. It creates the schema, private `.env.local`, admin hash and `var/setup.complete`, then invalidates the pairing code.
5. Sign in, create a profile and configure a job worker using the **same PHP command on every platform**.

```bash
php bin/setup-token.php
```

Only a fresh installation accepts setup. Setup requires HTTPS for non-local requests. The installer will not overwrite an existing `.env.local`, any populated database, or installed administrator.

`DUP_ALLOWED_SCAN_ROOTS` is a semicolon-separated list of directories that the PHP process can read. The setup wizard validates and saves them using the host's **actual filesystem paths**. A Windows machine may use `D:\\Music`, Linux `/srv/media`, DSM `/volume1/Music`. A Windows-mapped `Y:\\` drive on the development PC is *not* a path on a Synology PHP worker. The folder picker and job queue enforce these roots. No roots means no browsing/scanning.

### Windows web servers

Use PHP 8.2+ with IIS/FastCGI and URL Rewrite, or Apache. Set the site document root to `C:\\Sites\\dup-v2\\public` (or your chosen private path). The ZIP includes `public/web.config` for IIS and `public/.htaccess` for Apache. Configure the web server to serve PHP and give the application identity write permission to `var/`.

### Linux web servers

Use Apache/mod_rewrite or Nginx/PHP-FPM and a private install such as `/srv/dup-v2`. Set document root to `/srv/dup-v2/public`. A sample Nginx configuration is at `packaging/platform/linux-nginx.conf.example`. Give the PHP worker read access only to selected scan roots and writable access to the private runtime directory.

### Synology DSM 7 (optional)

The separate **preview SPK** uses package ID `dupv2` and a `/dup-v2/` Web Station portal. The existing DUP v1 package and `Y:\\dup` are unaffected. Its PHP application stays under the private package target; only `index.php`, `setup.php` and static assets are copied into Web Station. Its lifecycle scripts run the **same PHP job worker** as Windows/Linux, and store credentials/runtime state under the persistent package `var` directory.

DSM Web Station, PHP 8.2+ and MariaDB 10 are required by the current SPK metadata. PHP profile backend IDs may differ across NAS models. **The SPK is not ready for installation:** Web Station portal resolution, PHP extension selection, permissions, setup, service start/stop, migration and upgrade behaviour still require live DSM testing.

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
python packaging/build_dsm_preview.py --version 0.1.0 --php-backend 11
```

Both builders include vendored dependencies, verify the package contents, and exclude local databases and secrets. These are **preview artifacts only**, not releases. All regression fixtures and temporary outputs remain offline.

A fresh target database is used for DUP v2. There is no migration of v1 profiles or scan data.
