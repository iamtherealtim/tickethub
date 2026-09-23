#!/bin/sh
# TicketHub container entrypoint.
#
# 1. Waits for the database to accept connections.
# 2. Writes/updates writable/.env from the Docker .env on every start (a mounted
#    .env always wins and is never touched). See docker/env-sync.php.
# 3. Runs pending migrations (php spark migrate --all).
# 4. Hands over to the CMD (apache2-foreground, or the cron loop).
#
# Environment variables (see docker/.env.example):
#   CI_ENVIRONMENT   production | development            (default: production)
#   TICKETHUB_DOMAIN public host name; the address becomes https://<domain>/ (default: http://localhost/)
#   APP_BASE_URL     explicit address, overrides the one derived from TICKETHUB_DOMAIN
#   APP_FORCE_HTTPS  true|false, override the HTTPS redirect (default: on for an https:// address in production)
#   APP_PROXY_IPS    proxies trusted for X-Forwarded-* (default: the private ranges — only Caddy reaches the app)
#   DB_HOST DB_PORT DB_NAME DB_USER DB_PASSWORD
#   ENCRYPTION_KEY   hex2bin:... ; generated on first boot when empty
#   SKIP_MIGRATIONS  set to 1 to skip "php spark migrate --all" (e.g. the cron container)
set -eu

cd /var/www/html

CI_ENVIRONMENT="${CI_ENVIRONMENT:-production}"
DB_HOST="${DB_HOST:-db}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME="${DB_NAME:-tickethub}"
DB_USER="${DB_USER:-tickethub}"
DB_PASSWORD="${DB_PASSWORD:-}"

log() { printf '[entrypoint] %s\n' "$*"; }

# ---------------------------------------------------------------- wait for DB
wait_for_db() {
    tries=0
    max="${DB_WAIT_SECONDS:-90}"
    log "waiting for database ${DB_HOST}:${DB_PORT} (up to ${max}s)"
    until php -r '
        mysqli_report(MYSQLI_REPORT_OFF);
        $m = mysqli_init();
        $m->options(MYSQLI_OPT_CONNECT_TIMEOUT, 2);
        $ok = @$m->real_connect(getenv("DB_HOST"), getenv("DB_USER"), getenv("DB_PASSWORD"), getenv("DB_NAME"), (int) getenv("DB_PORT"));
        exit($ok ? 0 : 1);
    ' >/dev/null 2>&1; do
        tries=$((tries + 1))
        if [ "$tries" -ge "$max" ]; then
            log "database not reachable after ${max}s — giving up"
            exit 1
        fi
        sleep 1
    done
    log "database is up"
}

# ------------------------------------------------------------------ write .env
write_env() {
    # A real .env mounted by the operator always wins.
    if [ -f .env ] && [ ! -L .env ]; then
        log ".env already present (mounted) — leaving it alone; TICKETHUB_DOMAIN etc. are not applied to it"
        return
    fi

    # The generated file lives on the shared app_writable volume, so it survives
    # container re-creation and the app and cron containers read the SAME file
    # (one encryption.key for both). Its managed keys — environment, address,
    # proxies, database — are re-applied from the Docker .env on every start, so
    # editing the Docker .env and running `docker compose up -d` takes effect.
    SHARED_ENV=writable/.env
    umask 077
    php docker/env-sync.php "$SHARED_ENV"
    # Readable by PHP, writable only by root.
    chown root:www-data "$SHARED_ENV"
    chmod 640 "$SHARED_ENV"
    ln -sf "$SHARED_ENV" .env
}

# ------------------------------------------------------------------ migrations
migrate() {
    if [ "${SKIP_MIGRATIONS:-0}" = "1" ]; then
        log "SKIP_MIGRATIONS=1 — not running migrations"
        return
    fi
    log "running migrations"
    php spark migrate --all
}

wait_for_db
write_env
migrate

# writable/ may be a fresh named volume: make sure the runtime dirs exist.
mkdir -p writable/cache writable/logs writable/session writable/uploads writable/debugbar
chown -R www-data:www-data writable
# ...except the shared config, which PHP may read but never rewrite.
if [ -f writable/.env ]; then
    chown root:www-data writable/.env
    chmod 640 writable/.env
fi

log "starting: $*"
exec "$@"
