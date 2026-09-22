# TicketHub API

Base URL: `https://<your-tickethub>/api`
Interactive docs: `GET /api/docs` (Swagger UI) — machine-readable spec: `GET /api/openapi.json` (both public, no token needed).

## Authentication

Every call needs a bearer token created under **Admin → Integrations → API tokens**. A token *acts as* one person — pick an agent, supervisor or administrator when you create it — and every request is scoped exactly as that person's UI is:

| Token acts as | Sees / may change |
| --- | --- |
| Administrator | everything; can create users and organizations, change roles, assign to any agent or team |
| Supervisor / Agent | their own team's queue plus tickets assigned to them; can assign only within their team; can approve/reject changes (Supervisor) |
| Requester | only their own tickets, published KB articles and assets assigned to them |

```
Authorization: Bearer <token>
Content-Type: application/json
```

Tokens are shown once, can carry an expiry, and are revocable. Rate limit: **120 requests per minute per token** (`429` with `Retry-After` when exceeded).

## Conventions

* Lists return `{"data": [...], "page": 1, "per_page": 25, "total": 123}`. Use `?page=` and `?per_page=` (max 100).
* Errors return `{"error": "reason"}` with `400` (bad JSON), `401` (token), `403` (not allowed for this token's role/scope), `404` (missing or outside scope — the API never confirms that an out-of-scope ticket exists), `422` (validation), `429` (rate limit).
* Timestamps are `YYYY-MM-DD HH:MM:SS` in UTC.
* `PATCH` bodies are partial: send only the fields you want to change.

## Endpoints

| Resource | Endpoints |
| --- | --- |
| Me | `GET /me` |
| Tickets | `GET /tickets`, `POST /tickets`, `GET /tickets/{code}`, `PATCH /tickets/{code}`, `GET /tickets/{code}/messages`, `POST /tickets/{code}/reply` |
| Users | `GET /users?q=&role=&org=`, `GET /users/{id}`, `POST /users` (admin; creates a requester), `PATCH /users/{id}` |
| Organizations | `GET /organizations`, `GET /organizations/{id}`, `POST /organizations` (admin), `PATCH /organizations/{id}` (admin) |
| Knowledge base | `GET /kb/articles` (published only for requester tokens), `GET /kb/articles/{id}`, `POST /kb/articles`, `PATCH /kb/articles/{id}` |
| Assets | `GET /assets`, `GET /assets/{id}`, `POST /assets`, `PATCH /assets/{id}` |
| Changes | `GET /changes`, `GET /changes/{id}`, `POST /changes`, `POST /changes/{id}/approve`, `POST /changes/{id}/reject` |
| Problems | `GET /problems`, `GET /problems/{id}`, `POST /problems` |

Full parameter and schema detail is in `openapi.json`.

## Examples

Who am I?

```bash
curl -s https://desk.example.com/api/me -H "Authorization: Bearer $TOKEN"
```

Raise a ticket for someone (agent token) — routing rules, SLA targets and notifications apply exactly as in the UI:

```bash
curl -s https://desk.example.com/api/tickets \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"subject":"VPN drops every 10 minutes","body":"Started after the 2.4 client update.","requester_id":42,"priority":"High","category":"Network"}'
```

Assign, re-prioritise and tag it in one call:

```bash
curl -s -X PATCH https://desk.example.com/api/tickets/INC-2107 \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"agent_id":3,"priority":"Urgent","tags":["vpn","client-2.4"]}'
```

Resolve it and leave a public reply:

```bash
curl -s -X PATCH https://desk.example.com/api/tickets/INC-2107 -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" -d '{"status":"Resolved"}'
curl -s -X POST https://desk.example.com/api/tickets/INC-2107/reply -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" -d '{"body":"Fixed by rolling the client back to 2.3.","kind":"reply"}'
```

List a team's open tickets, newest first:

```bash
curl -s "https://desk.example.com/api/tickets?open=1&group_id=2&per_page=50" -H "Authorization: Bearer $TOKEN"
```

Find people in an organization, then create a requester (admin token):

```bash
curl -s "https://desk.example.com/api/users?org=7&role=Requester" -H "Authorization: Bearer $TOKEN"
curl -s https://desk.example.com/api/users -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" \
  -d '{"name":"Dana Reyes","email":"dana.reyes@acme.example","dept":"Finance","org_id":7}'
```

Create an organization and claim everyone with a matching email domain:

```bash
curl -s https://desk.example.com/api/organizations -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" \
  -d '{"name":"Acme Ltd","domain":"acme.example","auto_assign":true}'
```

Approve a change (supervisor/admin token, not the change's owner):

```bash
curl -s -X POST https://desk.example.com/api/changes/12/approve -H "Authorization: Bearer $TOKEN"
```

Publish a KB article:

```bash
curl -s https://desk.example.com/api/kb/articles -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"title":"Reset your MFA device","body":"Open Settings > Security ...","category":"Accounts & access","tags":["mfa"],"status":"Published"}'
```

## Outbound webhooks

Configured under **Admin → Webhooks**. Each endpoint has a URL, a per-endpoint signing secret (shown once) and a list of subscribed events (or `*`). TicketHub POSTs JSON with a 5-second timeout:

```
POST <your url>
Content-Type: application/json
X-TicketHub-Event: ticket.updated
X-TicketHub-Delivery: 1234            # unique per delivery; use it to de-duplicate retries
X-TicketHub-Signature: sha256=<hex HMAC-SHA256 of the raw body, keyed with the endpoint secret>

{"event":"ticket.updated","occurred_at":"2026-09-22T06:39:12+00:00","data":{"ticket":{
  "code":"INC-2107","subject":"…","status":"Open","priority":"High","type":"Incident","category":"Software",
  "source":"API","group":"Applications","agent":{"name":"Priya Raman","email":"…"},
  "requester":{"name":"…","email":"…"},"tags":["vpn"],"escalated":false,
  "created_at":"…","updated_at":"…","resolved_at":null,"url":"https://…/app/tickets/INC-2107"}}}
```

Verify the signature before trusting a payload (PHP: `hash_equals($header, 'sha256=' . hash_hmac('sha256', $rawBody, $secret))`). Respond with any 2xx within 5 seconds; anything else (or a timeout) is retried after 1 min, 5 min, 30 min, 2 h and 12 h. An endpoint that fails 20 times in a row is disabled automatically and can be re-enabled from the admin page, which also shows recent deliveries with their response and a **Retry** button, and can send a `webhook.test` event.

Events:

| Event | When |
| --- | --- |
| `ticket.created` | a ticket is created (UI, portal, email, catalog or API) |
| `ticket.updated` | any change, reply or note on a ticket |
| `ticket.assigned` | the assignee changed (sent alongside `ticket.updated`) |
| `ticket.replied` | a public reply was posted |
| `ticket.resolved` | status became Resolved |
| `ticket.closed` | status became Closed |
| `ticket.escalated` | the ticket was escalated |
| `ticket.time_reached` | a time-based automation fired on it |
| `webhook.test` | sent from Admin → Webhooks → Send test |

Retries run from `php spark tickethub:webhooks` — schedule it every few minutes next to `tickets:cron`.
