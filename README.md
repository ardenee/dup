# DUP

DUP is a web-based duplicate-file manager for Synology NAS systems.

It scans configured folders, records file metadata in MariaDB or SQLite, identifies duplicate groups, and provides browser-based tools to inspect, search, rename, move, delete, link, export/import, and download files.

## Current target platform

- Synology DSM 7
- Synology Web Station
- PHP 8.2 or newer
- MariaDB 10, or SQLite fallback mode
- Modern desktop browser

## Requirements

### Synology packages

Install these first:

- Web Station
- PHP 8.2 or newer
- MariaDB 10
- phpMyAdmin, optional but useful for troubleshooting

### Required PHP extensions

The Web Station PHP profile used by DUP should have:

- PDO
- pdo_mysql
- mysqli, recommended
- curl, recommended for update checks
- json
- session
- ctype
- hash
- mbstring

Do not enable obsolete extensions such as:

- old `mysql`
- `mcrypt`

### Web Station / PHP settings

DUP needs filesystem access to the NAS folders it scans.

Recommended `open_basedir` during setup/testing:

```text
none
```

A stricter production value can be used later, for example:

```text
/var/services/web_packages/dup:/volume1:/volume2:/volume3:/volume4:/tmp:/var/services/tmp
```

The PHP profile must allow DUP to access the scan folders, log folder, backup folder, and database/socket paths needed by MariaDB or SQLite.

### Permissions

The web package user must be able to read scanned folders.

DUP also needs write access to:

```text
logs/
backup/
db.php during setup
```

Typical DSM test permissions:

```sh
sudo chown -R dup:http /volume1/web_packages/dup/logs
sudo chmod -R 775 /volume1/web_packages/dup/logs

sudo chown -R dup:http /volume1/web_packages/dup/backup
sudo chmod -R 775 /volume1/web_packages/dup/backup
```

## Database modes

DUP supports:

- MariaDB 10 through PDO/pdo_mysql
- SQLite fallback mode for systems where MariaDB access is unavailable

MariaDB is preferred for larger scans.

## Duplicate detection modes

Profiles can use one of three duplicate-detection modes:

- **File Size**: fastest mode. Groups files by identical size only.
- **Fast CRC32 (start/middle/end)**: reads three parts of larger files: the first block, a middle block, and the final block. It CRCs those parts and combines the three CRCs into a final CRC32 value. If the file is smaller than the combined sample size, DUP falls back to a full-file CRC32.
- **Full CRC32**: reads and hashes the full file. Slower, but more reliable than sampled hashing.

The fast CRC32 mode is intended as a speed/uniqueness compromise for large media libraries. Future hash modes may include MD5 or SHA-based options.

## Scanner behaviour

Scans run as a background PHP CLI worker. The web UI remains responsive and polls status updates from the database.

The scanner:

1. Finds files under the profile path.
2. Filters by file extension and minimum size.
3. Stores path, size, modified time, profile id, and symlink target.
4. Calculates duplicate groups by size or CRC32 depending on profile settings.
5. Updates the profile status back to Idle when complete.

## File actions

DUP supports queued background file actions:

- Rename
- Move
- Delete
- Link / symlink

Actions are queued from the web interface and processed by a worker thread so larger batches can run without blocking the browser.

## Safety notes

DUP can delete, rename, and move real files on the NAS. Test with a disposable folder before scanning large libraries.

Keep the repository private until generated credentials are separated from source-controlled files.

## Planned improvements

- Split generated database credentials out of `db.php` into an ignored local config file.
- Add file preview for images and safe text formats.
- Improve search result highlighting.
- Add more hash modes, such as MD5.
- Add database indexes and migration helpers for larger libraries.
