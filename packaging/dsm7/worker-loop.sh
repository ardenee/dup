#!/bin/sh
set -eu
APP="${1:?private application directory required}"
VAR="${2:?persistent runtime directory required}"
umask 077

# The package service runs THIS shell as its stable PID. It supervises the
# separate PHP worker, reaps it, records unexpected exits and restarts it.
# A PHP fatal error must not silently strand all future jobs.
child=""
stopping=0
log() {
    printf '%s DUP worker: %s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" "$*"
}
stop_child() {
    stopping=1
    if [ -n "$child" ]; then
        log "service stop requested; signalling worker PID $child"
        kill -TERM "$child" 2>/dev/null || :
    fi
}
trap 'stop_child' TERM INT

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
        if "$php" -r 'exit(PHP_VERSION_ID >= 80200 &&
            extension_loaded("pdo_mysql") && extension_loaded("mbstring") ? 0 : 1);' \
            >/dev/null 2>&1; then
            printf '%s\n' "$php"
            return 0
        fi
    done
    return 1
}

cd "$APP"
log "supervisor started (PID $$); PHP 8.2+ with pdo_mysql and mbstring required"
while [ "$stopping" -eq 0 ]; do
    if [ ! -r "$VAR/runtime/setup.complete" ] || [ ! -r "$VAR/.env.local" ]; then
        log "setup incomplete or private database configuration unavailable; retry in 20 seconds"
        sleep 20 &
        child="$!"
        wait "$child" || :
        child=""
        continue
    fi
    if ! PHP=$(find_php); then
        log "no supported PHP CLI with pdo_mysql and mbstring available; retry in 20 seconds"
        sleep 20 &
        child="$!"
        wait "$child" || :
        child=""
        continue
    fi

    log "starting PHP worker: $PHP"
    "$PHP" "$APP/bin/console" dup:jobs:work --loop --idle-sleep=5 --max-jobs=1 --env=prod --no-ansi &
    child="$!"
    pid="$child"
    status=0
    wait "$pid" || status="$?"
    # If TERM interrupted wait, the child may still be exiting. Never start
    # two workers while the old one has not exited.
    while kill -0 "$pid" 2>/dev/null; do
        wait "$pid" || :
        sleep 1
    done
    child=""
    log "PHP worker PID $pid exited (status $status)"

    # This PID has exited. Resolve only jobs last claimed by this PID/host.
    if ! "$PHP" "$APP/bin/console" dup:jobs:worker-exited \
        --pid="$pid" --status="$status" --env=prod --no-ansi; then
        log "WARNING: unable to record exited worker jobs; inspect PHP/database errors"
    fi
    [ "$stopping" -eq 0 ] || break
    log "restarting worker after unexpected exit in 10 seconds"
    sleep 10 &
    child="$!"
    wait "$child" || :
    child=""
done
log "supervisor stopped"
exit 0
