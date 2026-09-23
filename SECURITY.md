# Security Policy

TicketHub holds the kind of data attackers like — who works where, which
laptop they have, password-reset conversations — so we take reports seriously
and try to make reporting easy.

## Supported versions

| Version | Supported |
| ------- | --------- |
| 1.x     | Yes — security fixes land on `main` and are tagged as a patch release |
| < 1.0 (pre-release commits) | No — upgrade to 1.x |

## Reporting a vulnerability

**Please do not open a public issue or pull request for security problems.**

Use GitHub's private reporting instead:
[github.com/iamtherealtim/tickethub/security/advisories/new](https://github.com/iamtherealtim/tickethub/security/advisories/new).
It reaches the maintainer directly, stays private until a fix ships, and
needs no email address on your end.

If you would rather not use GitHub, open an issue asking for another way to
reach us — say only that you have a security report, not the details — and
we will follow up privately.

Include as much of the following as you can:

- the version or commit you tested (`git rev-parse HEAD`),
- the role you were signed in as (or unauthenticated / API token),
- steps to reproduce, a request/response capture or a proof-of-concept,
- what you think the impact is (data exposure, privilege escalation, RCE, …).

If you need to send something sensitive, ask for a PGP key in your first
message and we will reply with one.

## What to expect

| When | What |
| --- | --- |
| within 3 business days | acknowledgement that we received the report |
| within 10 business days | our assessment: confirmed / not a vulnerability / need more information |
| as soon as practical, normally well inside 90 days | a fix released as a patch version, with credit to you in `CHANGELOG.md` unless you prefer to stay anonymous |

We follow a **90-day coordinated disclosure** window: we ask that you keep the
details private until a fix is released or 90 days have passed since your
report, whichever comes first. If a fix needs longer (for example a design
change to the API), we will tell you why and agree on a new date rather than
go silent. Actively exploited issues are disclosed as soon as a fix exists.

## In scope

- The TicketHub application code in `app/` and `public/`, including the
  JSON API, the inbound-email webhook, SSO/OIDC/LDAP sign-in, 2FA, outbound
  webhooks and the Docker packaging in this repository.
- Default configuration shipped in `app/Config/` and `env.production.example`.

## Out of scope

- The vendored CodeIgniter framework in `system/` — report those to the
  [CodeIgniter project](https://github.com/codeigniter4/CodeIgniter4/security)
  (but tell us too so we can ship the upgrade).
- Issues that require `CI_ENVIRONMENT = development`, a disabled `encryption.key`,
  or ignoring the production checklist in `README.md`.
- Denial of service by volume, missing best-practice headers on a deployment
  we do not run, or reports from automated scanners with no demonstrated impact.
- Social engineering of maintainers or users.

## Hardening reminders for operators

The production checklist in `README.md` is the short version. In particular:
run with `CI_ENVIRONMENT = production`, set `encryption.key`, put `public/`
(and nothing else) behind the web server, keep `writable/` outside the
document root, rotate API tokens and the inbound-email secret when people
leave, and back up the database and `writable/uploads/` together.
