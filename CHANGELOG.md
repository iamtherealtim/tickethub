# Changelog

All notable changes to TicketHub are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Upgrade notes for operators live in [docs/UPGRADING.md](docs/UPGRADING.md).

## [Unreleased]

### Added

- **SAML 2.0 single sign-on** — alongside Entra ID, generic OIDC and LDAP.
  Metadata-URL import for the identity provider's entity ID, SSO URL and
  signing certificate, or manual entry; attribute-based role mapping.
  Signature verification is delegated to `onelogin/php-saml` rather than
  hand-rolled; every assertion must be signed. This is the one optional
  feature that needs `composer install` — everything else still runs
  without it.
- **HTTPS out of the box with Docker.** A bundled Caddy now sits in front of
  the app on ports 80 and 443. Set `TICKETHUB_DOMAIN` and choose
  `TICKETHUB_TLS`:
  - `auto`: Let's Encrypt.
  - `dns`: Let's Encrypt via Cloudflare, Azure DNS or Route 53, for internal
    sites.
  - `files`: your own or company-CA certificate.
  - `internal`: a self-run authority, with a root-certificate download for
    GPO/Intune.
  - `upstream`: HTTPS is handled in front.

  Misconfigurations stop with a message saying which setting to fix. See
  [docs/https.md](docs/https.md).
- **Admin → Address & HTTPS.** Shows the configured address against how you
  actually reached the page, whether HTTPS and any proxy are working, and the
  certificate's issuer, names and expiry. Every problem comes with the exact
  line that fixes it. It also lists the callback URLs to give Entra, OIDC,
  SAML and the inbound-email service. Administrators see a banner across the
  workspace while something is wrong.
- **`php spark tickethub:url`** shows or sets the site address and trusted
  proxies in `.env`, validates the address, and lists the callback URLs to
  update elsewhere. `tickethub:setup` gains `--url` and asks for the address
  when it's still localhost.

### Changed

- **PHP 8.3 is now the minimum** (was 8.2, whose security support ends on
  31 December 2026). `public/index.php` and `spark` refuse to start on older
  versions. The Docker image moves to PHP 8.4, and CI tests 8.3, 8.4 and 8.5.
  See [docs/UPGRADING.md](docs/UPGRADING.md) before upgrading a manual
  install.
- **Stylesheet is now built ahead of time with Tailwind CSS v4** instead of
  compiled in the browser by the Tailwind Play CDN (v3). Pages load one
  ~50 KB cached CSS file instead of a ~400 KB script, render without a flash
  of unstyled content, and work with no outside CDN except Google Fonts.
  `cdn.tailwindcss.com` is gone from the Content-Security-Policy. The built
  file is committed, so deployments still need no Node. Checked page by page
  against 1.0.0 (every workspace, admin and portal page plus their dialogs,
  light and dark): identical, except that fields without an explicit
  background now use the theme's surface colour in dark mode instead of the
  browser's grey, and file pickers get the flat bordered button their
  classes always asked for.
  Needs Safari 16.4+, Chrome 111+ or Firefox 128+.

### Fixed

- **Docker quick start redirected to a dead https:// page.** It ran in
  production mode on `http://localhost:8080`, and production mode forced
  HTTPS. HTTPS is now enforced when the site address is `https://`, and the
  Secure cookie flag follows the same rule, so a plain-http local demo works.
- **Redirect loop behind a TLS-terminating proxy.** `app.proxyIPs` set in
  `.env` (and Docker's `APP_PROXY_IPS`) was silently ignored, because
  CodeIgniter only fills array settings key by key. It's now parsed as a
  comma-separated list, e.g. `app.proxyIPs = 10.0.0.5/32`.
- **Docker ignored changes to `.env.docker` after the first boot.** The
  environment, address, proxies and database settings are now re-applied
  on every start. The encryption key and any keys you added by hand are kept.

### Security

## [1.0.0] - 2026-09-22

First public release. TicketHub is a self-hosted IT service desk built on
CodeIgniter 4 and MySQL/MariaDB: one PHP application, no build step, no
queue workers.

### Added

- **Tickets** — incidents and service requests with codes (`INC-`, `SR-`),
  statuses, priorities, categories, tags, tasks, watchers, links, merges,
  bulk actions, saved views, time tracking, custom fields and CSV export.
- **Routing and teams** — groups, routing rules, a default team, claim and
  escalate flows, approvals for service requests.
- **SLA with business hours** — per-priority response/resolution targets,
  business-hours calendars with holidays, SLA pause on *Pending*, warnings
  and breach tracking, reopen counting.
- **Problems and changes** — problem records with root cause and workaround,
  KB linkage; change requests with risk, windows, approvals and state flow
  (including *Cancelled*); tickets link to both.
- **Assets** — inventory with assignment, ticket linkage, CSV import and a
  PDQ Connect sync.
- **Service catalog and knowledge base** — catalog items with dynamic forms
  and approvals; articles with categories, tags, votes and suggestions while
  typing a ticket.
- **Automations** — condition/action rules run on events and on a schedule
  (auto-close, nudges, escalation), with a run log.
- **Email in and out** — SMTP with editable notification templates,
  Microsoft 365 mailbox polling via Graph, a generic inbound-email webhook,
  threading by message id, reopen-on-reply rules, daily digest.
- **Self-service portal** — raise and follow tickets, reply, rate resolved
  tickets, browse the catalog and KB, announcements with expiry.
- **Reports and dashboard** — workload, SLA attainment, CSAT, exports.
- **Authentication and hardening** — bcrypt passwords with rules, forced
  password change, login throttling, remember-me with server-side expiry,
  session epoch (password change signs out other browsers), audit log,
  CSRF everywhere except the API, secure headers, production toggles for
  HTTPS/HSTS/Secure cookies.
- **Single sign-on** — Microsoft Entra ID with group-to-role mapping;
  generic OIDC and LDAP/Active Directory sign-in.
- **Two-factor authentication** — TOTP with recovery codes.
- **JSON API** — bearer tokens bound to a user (their role and scope apply),
  expiry, revocation, rate limiting; tickets list/show/create/reply and
  further resources.
- **Outbound webhooks** — signed event deliveries with retries and a
  delivery log (`tickethub:webhooks`).
- **Organizations** — group requesters by organization for scoping and
  reporting.
- **Canned responses, Markdown and ticket templates** — reusable replies,
  Markdown-formatted messages, recurring and templated tickets.
- **Data retention** — scheduled purge of closed tickets, audit rows and
  delivery logs (`tickethub:retention`).
- **Internationalisation and dark mode** — translatable UI with per-user
  language and theme preferences.
- **Notifications** — in-app notification centre with per-user preferences.
- **Operations** — `tickethub:setup` for the first administrator, production
  `.env` template, cron scheduler (`tickets:cron`), Docker image and Compose
  stack (app, MariaDB, scheduler), GitHub Actions CI, PHPUnit feature suite.

### Security

- Demo seeder refuses to run in production and forces a password change for
  every seeded account.
- API responses never carry a session cookie; tokens are stored hashed.

[Unreleased]: https://github.com/<org>/tickethub/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/<org>/tickethub/releases/tag/v1.0.0
