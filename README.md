# DUP v2

A modular, PHP-based duplicate-file manager for Synology DSM and generic web hosts.

**Development preview — not yet a replacement for DUP v1.** The framework, database schema, authentication infrastructure, profile configuration, health checks, dashboard, and job-monitor views are present. Scanning, duplicate verification, file actions, the NAS folder picker, interactive first-run setup, and DSM SPK installation remain under development. Do not deploy to a production NAS yet.

## Architecture

- Symfony 7.4 LTS, Twig, Doctrine DBAL/Migrations, Symfony Security, Messenger, and Monolog
- Modules: Accounts, Profiles, Dashboard, Jobs, and System; each module owns its controllers/services and registers navigation through a shared interface
- MariaDB 10 intended for production; SQLite used for isolated DEV testing
- New scan-run snapshots and indexed file/directory tables, with durable job/event and file-action tables
- Symfony web root is **public/**. Never expose the project root, vendor, config, source, migrations, or private environment files through HTTP
- Existing DUP v1 database and profiles will **not** be migrated

## Development (Windows)

Install PHP 8.2+ and Composer on the development machine, then:

```bash
composer install
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console dup:admin:create admin
php -S 127.0.0.1:8088 -t public public/index.php
```

Provide a fresh `.env.local` with a random `APP_SECRET` and a `DATABASE_URL`; it is ignored by Git. The CLI admin command asks for the password without echoing it (or accepts `--password-stdin` for trusted automated setup). Login at `/login`.

The application skeleton is restricted to PHP 8.2-compatible dependencies through Composer's platform constraint. PHP 8.4 is the preferred DSM runtime when available.

## Status

- Health: `GET /health` (redacted, no filesystem paths)
- Private diagnostics: `GET /system`
- Profiles: `GET /profiles` and `/profiles/new`
- Jobs: `GET /jobs` and `/api/jobs` (authenticated; no worker connected yet)

## Packaging and safety

The generic ZIP and DSM SPK must be generated from the same tested application sources, including vendored PHP dependencies, so Composer/Node are not required on deployment hosts. DSM-specific packaging and installation/upgrade validation are not yet complete.

v1 remains on GitHub `main` and the existing DSM deployment. This v2 worktree uses branch `v2/rebuild`. Cutover is allowed only after installation, runtime, scanning and file-action acceptance tests pass.
