# TicketHub

TicketHub is a self-hosted IT service desk: tickets (incidents and service
requests) with SLAs, business-hours calendars and routing rules; a self-service
employee portal with a knowledge base and service catalog; problems, changes
and assets; automations; email in and out (SMTP, Microsoft 365 via Graph, or a
generic inbound webhook); Microsoft Entra ID single sign-on; a PDQ Connect
asset import; and a small bearer-token JSON API.

It is a single CodeIgniter 4 application backed by MySQL. No JavaScript build
step, no queue workers, no Composer needed at runtime.

## Requirements

- PHP 8.2 or newer with the `intl`, `mbstring`, `curl`, `openssl`, `json` and
  `mysqlnd` extensions
- MySQL 8.0+ or MariaDB 10.6+
- A web server that can point its document root at `public/` (Apache with
  `mod_rewrite`, nginx, or Caddy)
- Cron (or the Windows Task Scheduler) for the background job
- Composer is **not** required to run the app: the framework lives in
  `system/` and the app autoloads from `app/`. Composer is only used to
  install PHPUnit for the test suite.

## Install

```bash
git clone <this repo> tickethub && cd tickethub
cp env .env                 # then edit .env (see below)
php spark key:generate      # writes encryption.key into .env
php spark migrate           # creates the schema (no demo data in production)
php spark tickethub:setup --email you@company.com --name "Your Name"
```

`tickethub:setup` creates the first Administrator and prints a one-time
password; you are asked to choose your own at first sign-in. It refuses to
run when an Administrator already exists unless you pass `--force`.

For a local demo instead, leave `CI_ENVIRONMENT = development` and run
`php spark db:seed TicketHubSeeder` — this loads fictional teams, people and
tickets. Every seeded account signs in with the password `password` and is
forced to change it. The seeder refuses to run in production unless
`TICKETHUB_ALLOW_DEMO_SEED=1` is set in the environment.

To try it without a web server: `php spark serve` and open
http://localhost:8080.

## Production checklist

Copy `env.production.example` to `.env` and fill in the values. In short:

1. **`CI_ENVIRONMENT = production`** — turns off the debug toolbar and
   detailed error pages, enables HTTPS-only + HSTS, marks cookies `Secure`,
   hides the demo-login block, and blocks the demo seeder.
2. **`app.baseURL`** — the public `https://` URL, with a trailing slash.
3. **`encryption.key`** — run `php spark key:generate`. Without it, SMTP /
   Entra / PDQ / webhook secrets are stored in plaintext and the Admin page
   shows a warning.
4. **Database** — `database.default.*` in `.env`; the DB user needs DDL rights
   for migrations.
5. **Web server document root = `public/`**. Never expose the project root.
   `public/.htaccess` handles rewrites on Apache; for nginx use
   `try_files $uri $uri/ /index.php?$args;`.
6. **`writable/` must be writable** by the PHP user (sessions, cache, logs,
   uploaded attachments live there):
   `chown -R www-data:www-data writable && chmod -R ug+rwX writable`.
7. **Cron** — the background job sends SLA warnings, runs time-based
   automations and polls the Microsoft 365 mailbox. Every 5 minutes is plenty:

   ```
   */5 * * * * cd /var/www/tickethub && php spark tickets:cron >> writable/logs/cron.log 2>&1
   ```

8. **First admin** — `php spark tickethub:setup --email ...` (see Install).
9. **Email** — Admin → Email settings (SMTP host, port, credentials, from
   address). Needed for invites, password resets and notifications. Send a
   test from the same page.
10. **Microsoft 365 mailbox → tickets** (optional) — Admin → Email settings →
    "Microsoft 365 mailbox": uses the Entra app registration below with the
    application permission `Mail.ReadWrite`. Polled by the cron job.
11. **Single sign-on** (optional) — Admin → Single sign-on. Register a Web app
    in Entra ID with redirect URI `https://<host>/auth/azure/callback`, paste
    tenant id (a GUID; `common`/`organizations` are accepted but let any work
    account sign in), client id and client secret. For automatic role
    mapping, add the **groups claim** to the ID token in the app's *Token
    configuration* and paste the object ids of your agent and administrator
    groups.
12. **Inbound email webhook** (optional) — Admin → Email settings → Inbound.
    Your mail provider POSTs to `/api/inbound-email` with the
    `X-Inbound-Secret` header shown there.
13. **Backups** — the MySQL database plus `writable/uploads/`.

Sessions expire after 8 hours of inactivity. Changing or resetting a password
signs out every other browser for that account.

## API

Create a token under Admin → Integrations → API tokens. Each token acts as
one chosen agent/supervisor/administrator (their role and team scope apply,
and the audit log names them), can be given an expiry in days, and is limited
to 120 requests per minute. Tokens are shown once and stored hashed.

```
Authorization: Bearer <token>
Content-Type: application/json

GET  /api/tickets?status=Open&open=1&page=1&per_page=25
GET  /api/tickets/INC-2101
POST /api/tickets          {"subject": "...", "body": "...", "requester_id": 7,
                            "priority": "High", "type": "Incident",
                            "category": "Network", "group_id": 2}
POST /api/tickets/INC-2101/reply   {"body": "...", "kind": "reply" | "note"}
```

Responses are JSON. Errors are `{"error": "..."}` with 400 (bad JSON),
401/403 (auth), 404, 422 (validation) or 429 (rate limit). API calls never
receive a session cookie.

## Development

```bash
composer install            # only for PHPUnit
php spark migrate && php spark db:seed TicketHubSeeder
php spark serve
vendor/bin/phpunit
```

Useful commands: `php spark tickets:cron` (run the scheduler once),
`php spark pdq:test` (check the PDQ Connect key), `php spark tickethub:setup`.

## License

The application code is released under the MIT license (see `LICENSE`).
CodeIgniter is © the CodeIgniter Foundation, MIT licensed.
