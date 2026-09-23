# Site address and HTTPS

TicketHub needs to know the address people use to reach it. Every link in
notification emails, every redirect and every single sign-on callback is
built from it. It also needs HTTPS, so passwords and sessions are encrypted.
Microsoft Entra ID, OIDC and SAML won't send users back to a plain `http://`
address.

**Admin → Address & HTTPS** shows the current setup at any time. It shows:
- the configured address, compared with how you actually reached the page;
- whether HTTPS is working, and whether a proxy in front is trusted;
- the certificate's issuer and expiry date;
- the exact callback URLs to paste into identity providers.

When something needs fixing, it gives the fix as a line to copy. Admins also
see a banner across the workspace until the problem is fixed.

- [Docker](#docker): set one variable, and the bundled Caddy handles certificates
- [Manual installs](#manual-installs): `php spark tickethub:url` plus a web server config
- [Choosing a certificate option](#choosing-a-certificate-option)
- [Troubleshooting](#troubleshooting)

---

## Docker

Everything is set in the `.env` next to `compose.yaml` (in Dockge or
Portainer, the stack's `.env` editor). Change it, then run
`docker compose up -d` or redeploy, and the new settings apply. The stack puts [Caddy](https://caddyserver.com), a
web server that handles certificates by itself, in front of TicketHub on ports
80 and 443.

```ini
TICKETHUB_DOMAIN=helpdesk.example.com
TICKETHUB_TLS=auto
```

With `TICKETHUB_DOMAIN` empty, you get a local demo on `http://localhost` with
no certificate.

Point the name's DNS record at the Docker host first (an internal DNS record
is fine for internal sites). Then pick one of these modes:

### `auto`: Let's Encrypt, for public sites

Nothing else to set. Caddy gets a free certificate that browsers trust, and
renews it about 30 days before it expires. Let's Encrypt checks you own the
name by connecting to the site, so **ports 80 and 443 must be reachable from
the internet**. Optionally set `TICKETHUB_ACME_EMAIL` to be warned about expiry
problems.

### `dns`: Let's Encrypt via DNS, for internal sites on a public domain

The site stays unreachable from outside. Caddy proves you own the domain by
creating a temporary DNS record through your DNS provider's API. You get a
certificate every browser trusts, renewed automatically. It needs a public
domain you control (a name like `helpdesk.corp.example.com` works; `helpdesk.local`
doesn't), and outbound internet access from the Docker host.

```ini
TICKETHUB_TLS=dns
TICKETHUB_DNS_PROVIDER=cloudflare     # cloudflare | azure | route53
```

| Provider | Settings | Permissions needed |
|---|---|---|
| Cloudflare | `CF_API_TOKEN` | An API token with **Zone → DNS → Edit** on the zone |
| Azure DNS | `AZURE_TENANT_ID`, `AZURE_CLIENT_ID`, `AZURE_CLIENT_SECRET`, `AZURE_SUBSCRIPTION_ID`, `AZURE_RESOURCE_GROUP_NAME` | An app registration with **DNS Zone Contributor** on the zone's resource group |
| Route 53 | `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY` (omit both to use the EC2 instance role) | `route53:ChangeResourceRecordSets`, `ListResourceRecordSets`, `GetChange`, `ListHostedZonesByName` |

If the name resolves differently inside and outside your network (split-horizon
DNS), that's fine. Caddy checks the record through public resolvers (1.1.1.1,
8.8.8.8).

### `files`: your own certificate, e.g. from a company CA

Use this when IT issues certificates from Active Directory Certificate Services
or another internal CA. Company PCs already trust it, so there are no browser
warnings. Put two PEM files in `docker/certs/`:

- `tls.crt`: the certificate, followed by any intermediate certificates
- `tls.key`: its private key, without a passphrase

Converting a `.pfx` is covered in [docker/certs/README.md](../docker/certs/README.md).
Renewing is manual: replace the files, then `docker compose restart caddy`.
Admin → Address & HTTPS warns 30 days ahead.

### `internal`: TicketHub's own certificate authority

Caddy creates its own certificate authority and issues and renews the site
certificate itself. This works for any name, including `helpdesk.lan`, with no
outside dependency. Browsers warn until the authority's **root certificate**
is installed on each computer. That's a one-time step:

1. Admin → Address & HTTPS → **Download root certificate**
   (or `docker compose cp caddy:/data/tickethub-root-ca.crt .`).
2. Distribute it as a trusted root CA:
   - **Group Policy:** Computer Configuration → Policies → Windows Settings →
     Security Settings → Public Key Policies → Trusted Root Certification
     Authorities → Import.
   - **Intune:** Devices → Configuration → Create → Templates → Trusted certificate.
   - **One PC:** double-click → Install Certificate → Local Machine → Trusted Root
     Certification Authorities.
   - **macOS:** add to the System keychain in Keychain Access, then set it to Always Trust.

The authority lives in the `caddy_data` volume. Keep that volume: deleting it
creates a new root, which you'd have to distribute again.

### `upstream`: HTTPS handled in front

Use this when a load balancer, reverse proxy or Cloudflare Tunnel already
terminates HTTPS and forwards plain http to port 80 on this host. Caddy trusts
its `X-Forwarded-*` headers from the private address ranges by default. Narrow
that with `TICKETHUB_UPSTREAM_PROXIES=10.0.0.5/32`.

### Ports 80 and 443 already in use

There are two ways to fix this in `.env`:

- **Give TicketHub its own IP address (recommended).** Add a second IP to the
  host (on TrueNAS: Network → Interfaces → your NIC → Aliases). Restrict the
  other service to the first IP; on TrueNAS that's System → General → GUI →
  Web Interface IPv4 Address. Then:
  ```ini
  TICKETHUB_HTTP_BIND=192.168.1.51:80
  TICKETHUB_HTTPS_BIND=192.168.1.51:443
  ```
  Point the DNS name at `192.168.1.51`. Everything else stays standard.
- **Use other ports:**
  ```ini
  TICKETHUB_HTTP_BIND=8080
  TICKETHUB_HTTPS_BIND=8443
  ```
  The site address becomes `https://helpdesk.example.com:8443/` automatically,
  and http:// requests redirect there. Let's Encrypt's `auto` mode needs the
  real ports 80 and 443, so use `dns`, `files` or `internal` here.

---

## Manual installs

### 1. Set the address

From the TicketHub folder on the server:

```bash
php spark tickethub:url https://helpdesk.example.com/
```

It checks the address, writes `app.baseURL` into `.env` and lists the callback
URLs to update in your identity providers. It takes effect on the next request.
Run it with no argument to see the current settings. `php spark tickethub:setup`
asks for the address too.

### 2. Put HTTPS in front

Pick whichever web server you already use.

**Caddy, recommended (automatic certificates):**

```caddy
helpdesk.example.com {
    root * /var/www/tickethub/public
    php_fastcgi unix//run/php/php8.4-fpm.sock
    file_server
    encode zstd gzip
}
```

For an internal CA or a `.pfx`, add `tls /etc/ssl/helpdesk.crt /etc/ssl/helpdesk.key`
or `tls internal` inside the block.

**nginx with certbot:**

```nginx
server {
    listen 80;
    server_name helpdesk.example.com;
    return 301 https://$host$request_uri;
}
server {
    listen 443 ssl;
    http2 on;
    server_name helpdesk.example.com;
    ssl_certificate     /etc/letsencrypt/live/helpdesk.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/helpdesk.example.com/privkey.pem;

    root /var/www/tickethub/public;
    index index.php;
    client_max_body_size 26m;

    location / { try_files $uri $uri/ /index.php?$args; }
    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param HTTPS on;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    }
}
```

```bash
sudo certbot --nginx -d helpdesk.example.com    # issues the certificate and sets up renewal
```

**Apache with certbot:** set `DocumentRoot /var/www/tickethub/public` with
`AllowOverride All` (the shipped `.htaccess` does the rewrites), then
`sudo certbot --apache -d helpdesk.example.com`.

### 3. Behind a separate reverse proxy or load balancer

If the proxy terminates HTTPS and talks http to TicketHub, tell TicketHub to
trust it. Otherwise the "visitor used HTTPS" header is ignored and you get a
redirect loop:

```bash
php spark tickethub:url https://helpdesk.example.com/ --trust-proxy=10.0.0.5
```

List only the proxy's own address or addresses, never a range that ordinary
visitors come from. The proxy must send `X-Forwarded-Proto: https` and
`X-Forwarded-For`. nginx does that with `proxy_set_header X-Forwarded-Proto
$scheme; proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;`, and
Caddy does it by default.

---

## Choosing a certificate option

| Your situation | Use |
|---|---|
| Public site | `auto` |
| Internal only, company has its own CA (AD CS) | `files` |
| Internal only, you own a public domain on Cloudflare, Azure DNS or Route 53 | `dns` |
| Internal only, neither of the above; or a lab | `internal` |
| A load balancer or tunnel already does HTTPS | `upstream` |

Plain http on a LAN still works for a closed test network, but it isn't
recommended: passwords cross the network unencrypted, and SSO won't work.
For a manual install that means `php spark tickethub:url http://helpdesk.lan/ --allow-http`.
In Docker, set `APP_BASE_URL=http://helpdesk.lan/` and leave `TICKETHUB_DOMAIN`
empty. Admin → Address & HTTPS will keep flagging it.

---

## Troubleshooting

Admin → Address & HTTPS names the problem and the fix. Common cases:

| Symptom | Cause | Fix |
|---|---|---|
| Endless redirect / "too many redirects" | A proxy terminates HTTPS but isn't trusted | `--trust-proxy=<proxy IP>` (manual); in Docker it's automatic |
| Sign-in works, but links in emails go to the wrong place | The address is set to something else | `tickethub:url`, or `TICKETHUB_DOMAIN` in Docker |
| Entra/OIDC says "redirect URI mismatch" | The callback registered at the provider is the old address | Copy the URLs from Admin → Address & HTTPS |
| Browser says "not private" with `internal` | The root certificate isn't installed on that PC | Download it from Admin → Address & HTTPS |
| Caddy keeps restarting | A setting is wrong or missing | `docker compose logs caddy` explains which one |
| `auto` never gets a certificate | Port 80 or 443 isn't reachable from the internet, or DNS doesn't point here yet | Open the ports, fix DNS, or switch to `dns`, `files` or `internal` |
