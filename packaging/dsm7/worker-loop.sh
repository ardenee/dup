#!/bin/sh
set -eu
APP="${1:?private app directory required}"
VAR="${2:?persistent package var directory required}"
umask 077

find_php() {
    for php in \
        "${DUP_PHP_BIN:-}" \
        /var/packages/PHP8.4/target/usr/local/bin/php84 \
        /var/packages/PHP8.2/target/usr/local/bin/php82 \
        /usr/local/bin/php84 \
        /usr/local/bin/php82 \
        /usr/bin/php
    do
        [ -n "$php" ] && [ -x "$php" ] || continue
        if "$php" -r 'exit(PHP_VERSION_ID >= 80200 ? 0 : 1);' >/dev/null 2>&1; then
            printf '%s\n' "$php"
            return 0
        fi
    done
    return 1
}

trap 'exit 0' TERM INT
cd "$APP"
while :; do
    # Installation does not create credentials. The setup wizard must supply
    # private APP_SECRET/DATABASE_URL before a worker may run.
    if [ -r "$VAR/.env.local" ]; then
        if PHP=$(find_php); then
            APP_ENV=prod "$PHP" "$APP/bin/console" dup:jobs:work --max-jobs=1 --no-ansi ||
                echo "DUP v2: worker invocation failed"
        else
            echo "DUP v2: no usable PHP 8.2+ CLI binary found"
            sleep 60
        fi
    fi
    sleep 5
done
