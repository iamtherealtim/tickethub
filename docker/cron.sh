#!/bin/sh
# Scheduler loop for the `cron` service in docker-compose.yml.
#
# Every 5 minutes:  tickets:cron        (SLA warnings, automations, mailbox poll)
#                   tickethub:webhooks  (outbound webhook delivery)
# Once a day:       tickethub:retention (data retention / purge)
#                   tickethub:digest    (daily email digests)
#
# Commands that are not present in this build are reported once and skipped, so
# the loop keeps working while optional modules are missing.
set -u

cd /var/www/html

INTERVAL="${CRON_INTERVAL_SECONDS:-300}"
DAILY_AT="${CRON_DAILY_AT:-03}"          # hour (UTC, 00-23) for the daily jobs
LOG=writable/logs/cron.log

log() { printf '%s [cron] %s\n' "$(date -u +'%Y-%m-%d %H:%M:%S')" "$*" | tee -a "$LOG"; }

# Cache the list of registered spark commands (refreshed hourly in case the
# image is updated underneath a long-lived container).
COMMANDS=""
COMMANDS_AT=0
have_command() {
    now=$(date +%s)
    if [ -z "$COMMANDS" ] || [ $((now - COMMANDS_AT)) -gt 3600 ]; then
        COMMANDS="$(php spark list --no-header 2>/dev/null || php spark list 2>/dev/null || true)"
        COMMANDS_AT=$now
    fi
    printf '%s' "$COMMANDS" | grep -q "^[[:space:]]*$1\([[:space:]]\|$\)"
}

run_job() {
    if ! have_command "$1"; then
        log "skip $1 (command not registered in this build)"
        return 0
    fi
    log "run $1"
    if ! php spark "$1" >>"$LOG" 2>&1; then
        log "warn $1 exited non-zero (see $LOG)"
    fi
}

log "scheduler started; interval=${INTERVAL}s, daily jobs at ${DAILY_AT}:00 UTC"
last_daily=""
while :; do
    run_job tickets:cron
    run_job tickethub:webhooks

    today=$(date -u +%Y-%m-%d)
    hour=$(date -u +%H)
    if [ "$hour" = "$DAILY_AT" ] && [ "$last_daily" != "$today" ]; then
        run_job tickethub:retention
        run_job tickethub:digest
        last_daily="$today"
    fi

    sleep "$INTERVAL"
done
