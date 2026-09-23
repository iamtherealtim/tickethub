#!/bin/sh
# Writes /etc/caddy/Caddyfile from the Docker .env, then runs Caddy.
#
#   TICKETHUB_DOMAIN        public name, e.g. helpdesk.example.com. Empty = local demo on http://localhost
#   TICKETHUB_TLS           auto | dns | files | internal | upstream        (default: auto)
#   TICKETHUB_ACME_EMAIL    optional contact for Let's Encrypt expiry notices (auto, dns)
#   TICKETHUB_DNS_PROVIDER  cloudflare | azure | route53                    (dns)
#   TICKETHUB_CERT_FILE / TICKETHUB_KEY_FILE   names inside certs/          (files; default tls.crt / tls.key)
#   TICKETHUB_UPSTREAM_PROXIES  who may send X-Forwarded-* to Caddy        (upstream; default private_ranges)
#
# Secrets (API tokens) are referenced as {$VAR} placeholders and resolved by
# Caddy when it loads the config — they are never written into the file.
#
# `tickethub-caddy validate` writes the file and runs `caddy validate` (CI).
set -eu

CADDYFILE=/etc/caddy/Caddyfile
VALIDATE=0
[ "${1:-}" = "validate" ] && VALIDATE=1
log() { printf '[caddy-setup] %s\n' "$*"; }
fail() {
    printf '[caddy-setup] ERROR: %s\n' "$*" >&2
    printf '[caddy-setup] Fix the Docker .env, then: docker compose up -d\n' >&2
    # Stay up long enough for `docker compose logs caddy` to show this instead of a restart blur.
    [ "$VALIDATE" = 1 ] || sleep 30
    exit 1
}
need() {
    for v in "$@"; do
        eval "val=\${$v:-}"
        [ -n "$val" ] || fail "TICKETHUB_TLS=dns with TICKETHUB_DNS_PROVIDER=${PROVIDER} needs $v in the Docker .env."
    done
}

# Accept "https://helpdesk.example.com/" as well as the bare name.
DOMAIN=$(printf '%s' "${TICKETHUB_DOMAIN:-}" | tr 'A-Z' 'a-z' | sed -e 's#^[a-z]*://##' -e 's#[/:].*$##')
MODE=$(printf '%s' "${TICKETHUB_TLS:-auto}" | tr 'A-Z' 'a-z')
PROVIDER=$(printf '%s' "${TICKETHUB_DNS_PROVIDER:-}" | tr 'A-Z' 'a-z')
EMAIL="${TICKETHUB_ACME_EMAIL:-}"

if [ -n "$DOMAIN" ] && ! printf '%s' "$DOMAIN" | grep -Eq '^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$'; then
    fail "TICKETHUB_DOMAIN=\"${TICKETHUB_DOMAIN}\" is not a valid host name (example: helpdesk.example.com)."
fi

GLOBAL=""
SITE_ADDR=""
TLS=""

if [ -z "$DOMAIN" ]; then
    log "no TICKETHUB_DOMAIN: local demo on plain http (port 80)"
    GLOBAL="auto_https off"
    SITE_ADDR=":80"
else
    case "$MODE" in
    auto)
        log "HTTPS for ${DOMAIN}: Let's Encrypt (needs ports 80 and 443 reachable from the internet)"
        SITE_ADDR="$DOMAIN"
        ;;
    dns)
        SITE_ADDR="$DOMAIN"
        case "$PROVIDER" in
        cloudflare)
            need CF_API_TOKEN
            TLS="tls {
		dns cloudflare {\$CF_API_TOKEN}
		resolvers 1.1.1.1 8.8.8.8
	}"
            ;;
        azure)
            need AZURE_TENANT_ID AZURE_CLIENT_ID AZURE_CLIENT_SECRET AZURE_SUBSCRIPTION_ID AZURE_RESOURCE_GROUP_NAME
            TLS="tls {
		dns azure {
			tenant_id {\$AZURE_TENANT_ID}
			client_id {\$AZURE_CLIENT_ID}
			client_secret {\$AZURE_CLIENT_SECRET}
			subscription_id {\$AZURE_SUBSCRIPTION_ID}
			resource_group_name {\$AZURE_RESOURCE_GROUP_NAME}
		}
		resolvers 1.1.1.1 8.8.8.8
	}"
            ;;
        route53)
            # Keys are optional: without them the AWS SDK uses the instance role.
            if [ -n "${AWS_ACCESS_KEY_ID:-}" ]; then
                need AWS_SECRET_ACCESS_KEY
                R53="dns route53 {
			access_key_id {\$AWS_ACCESS_KEY_ID}
			secret_access_key {\$AWS_SECRET_ACCESS_KEY}
		}"
            else
                R53="dns route53"
            fi
            TLS="tls {
		${R53}
		resolvers 1.1.1.1 8.8.8.8
	}"
            ;;
        '') fail "TICKETHUB_TLS=dns needs TICKETHUB_DNS_PROVIDER=cloudflare, azure or route53." ;;
        *) fail "TICKETHUB_DNS_PROVIDER=\"${PROVIDER}\" is not supported. Use cloudflare, azure or route53 (or TICKETHUB_TLS=files with your own certificate)." ;;
        esac
        log "HTTPS for ${DOMAIN}: Let's Encrypt via ${PROVIDER} DNS"
        ;;
    files)
        CERT="/certs/${TICKETHUB_CERT_FILE:-tls.crt}"
        KEY="/certs/${TICKETHUB_KEY_FILE:-tls.key}"
        [ -r "$CERT" ] || fail "TICKETHUB_TLS=files but ${CERT#/certs/} is not in the certs/ folder next to compose.yaml. Put the certificate (with any intermediates appended) there as PEM."
        [ -r "$KEY" ] || fail "TICKETHUB_TLS=files but ${KEY#/certs/} is not in the certs/ folder next to compose.yaml. Put the unencrypted PEM private key there."
        grep -q 'BEGIN CERTIFICATE' "$CERT" || fail "${CERT#/certs/} is not a PEM certificate (expected a -----BEGIN CERTIFICATE----- block). Convert a .pfx with: openssl pkcs12 -in site.pfx -clcerts -nokeys -out tls.crt"
        grep -q 'PRIVATE KEY' "$KEY" || fail "${KEY#/certs/} is not a PEM private key. From a .pfx: openssl pkcs12 -in site.pfx -nocerts -nodes -out tls.key"
        grep -q 'ENCRYPTED' "$KEY" && fail "${KEY#/certs/} is password-protected. Remove the passphrase: openssl pkey -in tls.key -out tls.key.plain"
        log "HTTPS for ${DOMAIN}: certificate from certs/${CERT#/certs/}"
        SITE_ADDR="$DOMAIN"
        TLS="tls ${CERT} ${KEY}"
        ;;
    internal)
        log "HTTPS for ${DOMAIN}: TicketHub internal authority (install its root on client PCs — Admin → Address & HTTPS)"
        GLOBAL="skip_install_trust
	pki {
		ca local {
			name \"TicketHub Internal CA\"
			root_cn \"TicketHub Internal Root CA\"
			intermediate_cn \"TicketHub Internal Intermediate CA\"
		}
	}"
        SITE_ADDR="$DOMAIN"
        TLS="tls internal"
        ;;
    upstream)
        UP="${TICKETHUB_UPSTREAM_PROXIES:-private_ranges}"
        log "HTTPS for ${DOMAIN}: terminated upstream; plain http on port 80, trusting X-Forwarded-* from ${UP}"
        GLOBAL="auto_https off
	servers {
		trusted_proxies static ${UP}
	}"
        SITE_ADDR=":80"
        ;;
    *)
        fail "TICKETHUB_TLS=\"${MODE}\" is not one of: auto, dns, files, internal, upstream."
        ;;
    esac
fi

if [ -n "$EMAIL" ] && { [ "$MODE" = "auto" ] || [ "$MODE" = "dns" ]; } && [ -n "$DOMAIN" ]; then
    GLOBAL="email ${EMAIL}
	${GLOBAL}"
fi

# The host port HTTPS is published on ("443", "8443", "192.168.1.51:8443").
# Caddy listens on 443 inside the container, so its automatic http->https
# redirect would point at :443; with any other outside port, redirect explicitly.
EXT_HTTPS=$(printf '%s' "${TICKETHUB_HTTPS_BIND:-443}" | sed 's/^.*://')
REDIRECT=""
if [ -n "$DOMAIN" ] && [ "$SITE_ADDR" = "$DOMAIN" ] && [ -n "$EXT_HTTPS" ] && [ "$EXT_HTTPS" != "443" ]; then
    log "HTTPS is published on port ${EXT_HTTPS}: http:// requests redirect to https://${DOMAIN}:${EXT_HTTPS}"
    REDIRECT="http://${DOMAIN} {
	redir https://${DOMAIN}:${EXT_HTTPS}{uri} 308
}"
fi

{
    echo "# Generated by tickethub-caddy on $(date -u +%Y-%m-%dT%H:%M:%SZ) from the Docker .env — edits are overwritten."
    echo "{"
    [ -n "$GLOBAL" ] && printf '\t%s\n' "$GLOBAL"
    echo "}"
    echo ""
    [ -n "$REDIRECT" ] && printf '%s\n\n' "$REDIRECT"
    echo "${SITE_ADDR} {"
    [ -n "$TLS" ] && printf '\t%s\n' "$TLS"
    printf '\tencode zstd gzip\n'
    printf '\treverse_proxy app:80\n'
    echo "}"
} > "$CADDYFILE"

if [ "$VALIDATE" = 1 ]; then
    cat "$CADDYFILE"
    exec caddy validate --config "$CADDYFILE" --adapter caddyfile
fi

# Internal CA: Caddy keeps its files root-only. Publish a readable copy of the
# root CERTIFICATE (public by nature; the key stays put) for the app's
# "Download root certificate" button, refreshed in case it is ever re-created.
if [ -n "$DOMAIN" ] && [ "$MODE" = "internal" ]; then
    (
        while :; do
            ROOT=/data/caddy/pki/authorities/local/root.crt
            if [ -f "$ROOT" ] && ! cmp -s "$ROOT" /data/tickethub-root-ca.crt 2>/dev/null; then
                cp "$ROOT" /data/tickethub-root-ca.crt.tmp && chmod 644 /data/tickethub-root-ca.crt.tmp \
                    && mv /data/tickethub-root-ca.crt.tmp /data/tickethub-root-ca.crt
            fi
            sleep 30
        done
    ) &
else
    rm -f /data/tickethub-root-ca.crt
fi

exec caddy run --config "$CADDYFILE" --adapter caddyfile
