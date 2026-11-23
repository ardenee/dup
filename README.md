# DUP v2

A modular PHP duplicate-file manager for Synology DSM and generic PHP hosts.

**Development preview — not production-ready.** The Symfony application, fresh database,
profiles, a database-backed CLI scan worker, SHA-256 duplicate detection, ignored-folder
policies, job monitoring, and read-only duplicate results are implemented and tested on
disposable DEV folders. The authenticated NAS folder picker and background ignore-rule
recalculation are implemented. A generic ZIP and isolated DSM SPK preview builder exist,
but **DSM installation and the worker service have not been tested on a NAS**. A browser-based
first-run installer, file actions, reporting and end-to-end DSM tests remain to be built.
Do not replace DUP v1 yet.

## Design

- Symfony 7.4 LTS, Twig, Doctrine DBAL/Migrations, Symfony Security, and Monolog.
- Independent modules for Accounts, Profiles, Scans, Jobs, Duplicates, Dashboard and System.
- **One** durable, DB-backed job queue, processed by a PHP CLI worker; no Redis,
  external executable or secondary Messenger queue.
- MariaDB 10 / MySQL 8 is the intended production database. SQLite powers isolated
  development tests. InnoDB tables are used on MariaDB.
- Every scan creates its own immutable run: a failed/cancelled scan does not overwrite
  the previous completed results.
- All extension-eligible files are indexed, including files under `@eaDir` and
  `#recycle`, but ignored folders are excluded from duplicate matches.
- Streamed SHA-256 verifies potential duplicates of the same size; a size-only
  mode produces **unverified candidate groups**, never presented as verified content.
- Large-group members and duplicate-group lists use database cursor pagination.
- All web requests must use **public/** as document root. The project root,
  source, private configuration, vendor, job logs and databases must not be public.

## DEV setup

Install PHP 8.2+ and Composer on the development machine, then:

```bash
composer install
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console dup:admin:create admin
php -S 127.0.0.1:8088 -t public public/index.php
```

Create a new ignored `.env.local` or supply private environment values for
`APP_SECRET`, `DATABASE_URL`, `APP_ENV` and `DUP_ALLOWED_SCAN_ROOTS`.
For example: `DUP_ALLOWED_SCAN_ROOTS=/volume1/Music;/volume2/Archive`.
Only these directories and their subfolders are accessible to the picker or scan workers;
there are no default filesystem roots. Do not commit real credentials.

Start at `/profiles/new` to configure an allowed file type, source folder,
move folder and hash mode. Scans do not start from HTTP requests; the web app
only queues a job. Run this CLI command (or schedule it with cron):

```bash
php bin/console dup:jobs:work --max-jobs=1
```

Useful operational commands:

```bash
php bin/console dup:scan:queue 1
php bin/console dup:jobs:cancel 1
php bin/console dup:jobs:recover --after=120
php bin/console dup:settings:ignore --names='@eaDir,#recycle'
```

The `/settings/ignored-folders` page lets an administrator change exact folder-name
exclusions. The system queues a database-only regeneration of duplicate groups, using
existing stored hashes and filenames; a filesystem rescan is not required. Historical
completed scans are retained.

To keep progress and cancel requests responsive, workers update their heartbeat
at checkpoints. A terminated worker becomes failed after its heartbeat expires;
it never silently overwrites a previous successful result.

## Routes

- `/health` — public status with private detail redacted
- `/login` — login; remaining application routes require an administrator
- `/` — dashboard (last successful snapshot per profile)
- `/profiles`, `/profiles/new` — profile configuration and scan submission
- `/api/folders` — authenticated, root-restricted NAS directory listing
- `/settings/ignored-folders` — update ignore rules and queue duplicate recalculations
- `/jobs`, `/api/jobs`, `/api/jobs/{id}/events` — job status and event history
- `/duplicates`, `/duplicates/{id}` — read-only duplicate groups and file members
- `/system` — authenticated PHP, schema and worker diagnostics

## Testing and packaging

On DEV, scan and recovery regression tests are kept offline (outside the public
repository). A preview generic ZIP containing vendored PHP dependencies can be
built with:

```bash
python packaging/build_preview.py
python packaging/build_dsm_preview.py --version 0.1.0 --php-backend 11
```

The DSM preview uses package ID `dupv2` and a separate `/dup-v2/` portal. It places
Symfony source/vendor/config in the **private package target**, copying only the web
front controller and static assets into `web_packages`. A package-managed, PHP-only
worker loop can process queued jobs after private configuration is established.
The PHP backend ID is configurable at build time; the default 11 is known to work
with PHP 8.2 on the test DSM, but other DSM installations need verification.

**Neither artifact is a production installer yet.** The package generator checks
file parity, excludes secrets and runtime databases, and leaves the old DUP portal
untouched. Do not install the preview SPK until the first-run configuration,
DSM service lifecycle, permissions and web routing have been tested. No Composer
or Node.js should be required on a deployed web host.

DUP v1 remains on GitHub `main` and the existing DSM installation at
`Y:\dup`. DUP v2 is developed independently in branch `v2/rebuild`.
No data migration is planned. A fresh DSM installation and explicit acceptance
tests are required before any cutover.
