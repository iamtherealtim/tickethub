# Your own certificate (TICKETHUB_TLS=files)

Put two PEM files here, then `docker compose up -d`:

| File      | Contents                                                                 |
|-----------|--------------------------------------------------------------------------|
| `tls.crt` | The site certificate, followed by any intermediate certificates          |
| `tls.key` | Its private key, **without** a passphrase                                |

Other file names: set `TICKETHUB_CERT_FILE` / `TICKETHUB_KEY_FILE` in the Docker `.env` (next to `compose.yaml`).

Got a `.pfx` / `.p12` from Windows or your CA?

```bash
openssl pkcs12 -in site.pfx -clcerts -nokeys -out tls.crt
openssl pkcs12 -in site.pfx -cacerts -nokeys >> tls.crt   # append the chain
openssl pkcs12 -in site.pfx -nocerts -nodes  -out tls.key
```

After renewing, replace the files and run `docker compose restart caddy`.
Admin → Address & HTTPS shows the expiry date and warns 30 days ahead.

Nothing in this folder except this README is committed (see `.gitignore`).
