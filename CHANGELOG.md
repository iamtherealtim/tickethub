# Changelog

All notable changes to TicketHub are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Upgrade notes for operators live in [docs/UPGRADING.md](docs/UPGRADING.md).

## [Unreleased]

### Added

### Changed

### Fixed

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
