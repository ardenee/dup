#!/bin/sh
set -eu
APP="${1:?private application directory required}"
VAR="${2:?persistent runtime directory required}"
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

cd "$APP"
while :; do
    # The system-independent installer creates this marker and the private
    # configuration. The DSM wrapper does not own schema or credentials.
    if [ -r "$VAR/runtime/setup.complete" ] && [ -r "$VAR/.env.local" ]; then
        if PHP=$(find_php); then
            # Replace the shell with the common PHP worker. DSM stop sends
            # SIGTERM to the real worker PID; no orphaned child process.
            exec "$PHP" "$APP/bin/console" dup:jobs:work --loop --idle-sleep=5 --max-jobs=1 --env=prod --no-ansi
        fi
        echo "DUP v2: PHP 8.2+ CLI binary unavailable"
    fi
    sleep 10
done
