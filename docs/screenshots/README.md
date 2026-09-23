# Screenshots

The README embeds these images, captured at 1440×900 in both light and dark
mode, signed in as the seeded administrator (`maya.ortiz@tickethub.co`)
except the portal shots, which use `jordan.whitfield@tickethub.co`.

| File | Page | Notes |
|------|------|-------|
| `dashboard.png` / `dashboard-dark.png` | `/app/dashboard` | KPIs, breach horizon, volume chart, agent workload |
| `ticket.png` / `ticket-dark.png` | `/app/tickets/INC-2090` | Conversation, Markdown reply editor, SLA and properties panel |
| `portal.png` / `portal-dark.png` | `/portal` | Hero search, quick actions, notices, open requests |
| `admin-sla.png` / `admin-sla-dark.png` | `/app/admin/sla` | Admin nav plus the SLA policies table |

Dark mode is set via `localStorage.setItem('th-theme', 'dark')` (what the
in-app toggle does), then reloading the page.

Regenerate all eight after any palette or layout change so the README does
not drift from the product. The debug toolbar icon that only appears with
`CI_ENVIRONMENT=development` is removed from the page before capture — it
would never appear in a production deployment either.

## Status

Captured 2026-09-23 against a locally seeded instance, using a small
Chrome DevTools Protocol script (no browser extension or paid tool needed —
any Chromium build's `--remote-debugging-port` works the same way).
