# Caddy for TicketHub: terminates HTTPS in front of the app container.
#
# The official image plus three DNS-provider modules, so TICKETHUB_TLS=dns can
# get a publicly trusted certificate for a site that is not reachable from the
# internet. Versions are pinned; bump them together and let CI's compose smoke
# test validate every mode.
#
# Go cross-compiles, so the builder runs natively and targets the requested
# architecture (amd64/arm64) — no slow emulated compile.
FROM --platform=$BUILDPLATFORM caddy:2.11.4-builder AS build
ARG TARGETOS
ARG TARGETARCH
RUN GOOS=$TARGETOS GOARCH=$TARGETARCH xcaddy build v2.11.4 \
    --with github.com/caddy-dns/cloudflare@v0.2.4 \
    --with github.com/caddy-dns/azure@v0.6.0 \
    --with github.com/caddy-dns/route53@v1.6.2

FROM caddy:2.11.4
COPY --from=build /usr/bin/caddy /usr/bin/caddy
COPY start.sh /usr/local/bin/tickethub-caddy
RUN sed -i 's/\r$//' /usr/local/bin/tickethub-caddy && chmod 755 /usr/local/bin/tickethub-caddy

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD wget -q --spider http://127.0.0.1:2019/config/ || exit 1

CMD ["tickethub-caddy"]
