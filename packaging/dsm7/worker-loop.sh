#!/bin/sh
set -eu
APP="${1:?private application directory required}"
VAR="${2:?persistent runtime directory required}"
umask 077
DIAG="$VAR/runtime/log/dup-jobs"
OUTPUT="$DIAG/worker-output.log"
CONTROL="$VAR/runtime/worker-control"
mkdir -p "$CONTROL"
chmod 0700 "$CONTROL"
# Expose only diagnostics to the Web Station 'http' group. Private config
# files remain owner-only; parent folders provide traversal, not listing.
mkdir -p "$DIAG"
if id -nG | tr ' ' '\n' | grep -qx 'http'; then
    chgrp http "$VAR/runtime" "$VAR/runtime/log" "$DIAG" 2>/dev/null || :
    chmod 0710 "$VAR/runtime" "$VAR/runtime/log" 2>/dev/null || :
    chmod 0750 "$DIAG" 2>/dev/null || :
fi

# The package service runs THIS shell as its stable PID. It supervises the
# separate PHP worker, reaps it, records unexpected exits and restarts it.
# A PHP fatal error must not silently strand all future jobs.
child=""
stopping=0
log() {
    line=$(printf '%s DUP worker: %s' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" "$*")
    printf '%s\n' "$line"
    # Mirror supervisor events into the admin-readable private log directory.
    # Keep at most 2 MiB + one rotation, independent of worker PHP/database.
    file="$DIAG/supervisor.log"
    if [ -d "$DIAG" ]; then
        if [ -f "$file" ]; then
            count=$(wc -c < "$file" 2>/dev/null || printf '0')
            if [ "$count" -ge 2097152 ]; then
                mv -f "$file" "$file.1" 2>/dev/null || :
            fi
        fi
        printf '%s\n' "$line" >> "$file" 2>/dev/null || :
        chmod 0640 "$file" 2>/dev/null || :
        chgrp http "$file" 2>/dev/null || :
    fi
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
    if [ -f "$OUTPUT" ]; then
        count=$(wc -c < "$OUTPUT" 2>/dev/null || printf '0')
        if [ "$count" -ge 2097152 ]; then
            mv -f "$OUTPUT" "$OUTPUT.1" 2>/dev/null || :
        fi
    fi
    "$PHP" "$APP/bin/console" dup:jobs:work --loop --idle-sleep=5 --max-jobs=1 --env=prod --no-ansi >> "$OUTPUT" 2>&1 &
    child="$!"
    chmod 0640 "$OUTPUT" 2>/dev/null || :
    chgrp http "$OUTPUT" 2>/dev/null || :
    pid="$child"
    request="$CONTROL/verify-$pid.request"
    # Never act on a request from a previous incarnation of the same PID.
    rm -f "$request"
    requested_job=""
    requested_at=0
    status=0
    # Poll the private control file while this specific child is alive.
    # The web application never signals a PID directly. Only the parent
    # supervisor can signal the worker it launched itself.
    while kill -0 "$pid" 2>/dev/null; do
        if [ "$stopping" -ne 0 ]; then
            kill -TERM "$pid" 2>/dev/null || :
            break
        fi
        if [ -z "$requested_job" ] && [ -f "$request" ]; then
            job_id=""
            IFS= read -r job_id < "$request" || :
            rm -f "$request" || :
            case "$job_id" in
                ''|*[!0-9]*)
                    log "WARNING: ignored malformed private cancellation request for worker PID $pid"
                    ;;
                *)
                    requested_job="$job_id"
                    requested_at=$(date +%s)
                    log "SHA-256 job #$requested_job: sending TERM to owned worker PID $pid"
                    kill -TERM "$pid" 2>/dev/null || :
                    ;;
            esac
        fi
        if [ -n "$requested_job" ]; then
            now=$(date +%s)
            if [ $((now-requested_at)) -ge 8 ]; then
                log "SHA-256 job #$requested_job: worker PID $pid failed to exit after TERM; sending KILL"
                kill -KILL "$pid" 2>/dev/null || :
                break
            fi
        fi
        sleep 1 &
        wait "$!" || :
    done
    wait "$pid" || status="$?"
    rm -f "$request" || :
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
