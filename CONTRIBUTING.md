# Contributing to TicketHub

Thanks for helping make TicketHub better. This guide covers the development
setup, the conventions the codebase follows, and how a change gets in.

Please be kind: everyone here is bound by the [Code of Conduct](CODE_OF_CONDUCT.md).
Security problems go through [SECURITY.md](SECURITY.md), never a public issue.

## Development setup

Requirements: PHP 8.2+ with `intl`, `mbstring`, `mysqli`, `curl`, `openssl`,
`gd` (and `ldap` if you work on directory sign-in), MySQL 8 / MariaDB 10.6+,
and Composer (for the test tooling only — the framework is vendored in `system/`).

```bash
git clone https://github.com/<org>/tickethub.git && cd tickethub
composer install                     # PHPUnit, php-cs-fixer, coding standard
cp env .env                          # CI_ENVIRONMENT = development, database.default.*
php spark key:generate               # encryption.key
php spark migrate --all
php spark db:seed TicketHubSeeder    # fictional teams, people, tickets
php spark serve                      # http://localhost:8080
```

Every seeded account signs in with the password `password` and is asked to
change it. `maya.ortiz@tickethub.co` is an Administrator, `devin.park@…` an
Agent, `jordan.whitfield@…` a Requester.

Prefer Docker? `cp docker/.env.docker.example .env.docker` and
`docker compose up -d --build` gives you the same thing on http://localhost:8080
(set `CI_ENVIRONMENT=development` in `.env.docker` for the debug toolbar).

Useful commands while developing:

| Command | What it does |
| --- | --- |
| `php spark serve` | Dev server on port 8080 |
| `php spark migrate --all` / `php spark migrate:rollback` | Apply / undo migrations |
| `php spark db:seed TicketHubSeeder` | Demo data (refuses to run in production) |
| `php spark tickets:cron` | One pass of the scheduler (SLA warnings, automations, mailbox poll) |
| `php spark tickethub:setup --email you@example.com` | First administrator on an empty database |
| `php spark routes` | List every registered route and its filters |
| `composer test` | PHPUnit |
| `composer cs` / `composer cs:fix` | Coding style check / fix |
| `find app tests -name '*.php' -exec php -l {} \; \| grep -v 'No syntax errors'` | Syntax check (what CI's lint job runs) |

## Coding style

TicketHub follows the [CodeIgniter coding standard](https://github.com/CodeIgniter/coding-standard)
(PER-CS based, 4-space indent, `declare(strict_types=1)` optional but welcome
in new files). `composer cs` runs `php-cs-fixer fix --dry-run` and `composer cs:fix`
applies the fixes.

Both scripts expect a `.php-cs-fixer.dist.php` in the project root. If it is
missing on your checkout, create it with:

```php
<?php

use CodeIgniter\CodingStandard\CodeIgniter4;
use Nexus\CsConfig\Factory;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->files()
    ->in([__DIR__ . '/app', __DIR__ . '/tests'])
    ->exclude(['Views'])
    ->notName('*.tab.php');

return Factory::create(new CodeIgniter4(), [], ['finder' => $finder])->forProjects();
```

A few conventions that are not enforced by the fixer:

- **Controllers are thin.** Intake, scoping and notifications live in
  `App\Controllers\BaseController` and `App\Libraries\*` (`TicketIntake`,
  `AutomationEngine`, `Mailer`, …) so the UI, the API and email ingestion
  behave identically. If you find yourself duplicating a query from another
  controller, move it down.
- **Scope everything through `canSeeTicket()` / `scopeWhere()`.** Agents see
  their group's tickets (or ones assigned to them), requesters only their
  own, administrators everything. The portal, workspace and API must never
  disagree.
- **Never trust the request for identity.** Who the user is comes from the
  session (or the API token); which records they may touch comes from the
  helpers above.
- **Escape on output.** Views use `esc()`. Ticket bodies are sanitised on the
  way in (see `th_sanitize_html()` in `app/Helpers/tickethub_helper.php`).
- **Settings, not constants.** Anything an administrator might want to change
  belongs in the `settings` table via `App\Libraries\Settings`, surfaced on an
  admin tab. Secrets are stored encrypted when `encryption.key` is set.
- **Log, don't echo.** `log_message()`; the CLI commands use `CLI::write()`.

## Tests

```bash
composer test                                  # everything
php vendor/bin/phpunit tests/unit              # fast, no database
php vendor/bin/phpunit --filter ScopeTest      # one class
```

- `tests/unit/` — pure PHP: helpers, config toggles. No database.
- `tests/feature/` — HTTP-level tests through the real router, filters and
  controllers, against a **separate** `tickethub_test` database that is
  dropped, migrated and seeded for every test. They extend
  `Tests\Support\FeatureTestCase`, which gives you `sessionFor($userId)`,
  `postForm($path, $data, $session)` (adds a valid CSRF token) and the seeded
  fixtures. When the test database is not reachable these tests **skip**
  themselves with the reason printed; in CI they must run
  (`--fail-on-skipped`).

To run the feature tests locally, create the database once and grant your
`.env` user access to it:

```sql
CREATE DATABASE tickethub_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL ON tickethub_test.* TO 'tickethub'@'%';
```

The connection details come from `TH_TEST_DB_HOST/PORT/NAME/USER/PASSWORD`
environment variables, then `database.tests.*` in `.env`, then
`database.default.*` with the database renamed to `tickethub_test`
(see `tests/_support/bootstrap-db.php` and `tests/README.md`).

Every pull request that changes behaviour should come with a test. Route or
permission changes get a feature test; helper changes get a unit test.

## How to add things

### A migration

1. Create `app/Database/Migrations/YYYY-MM-DD-HHMMSS_ShortName.php` extending
   `CodeIgniter\Database\Migration`. Use the next free timestamp for today so
   ordering stays obvious.
2. Write `up()` **and** a real `down()`. Raw SQL is fine (most existing
   migrations use it); keep it MySQL/MariaDB compatible, `utf8mb4`, InnoDB.
   Guard optional steps with `$this->db->fieldExists()` / `tableExists()` so
   the migration is safe to re-run.
3. New tables that hold per-instance configuration should be seeded with
   sensible defaults in the migration itself (see `2026-07-25-000005_AuthHardening.php`
   for a pattern), not in `TicketHubSeeder` — the seeder is demo data only.
4. Run `php spark migrate --all`, then `php spark migrate:rollback` and
   `migrate --all` again to prove `down()` works.
5. Mention the migration under **Unreleased** in `CHANGELOG.md`; operators
   read that before upgrading (see `docs/UPGRADING.md`).

### An admin plug-in tab

The Admin area discovers its tabs from the file system, so a feature can add
its own settings page without touching `AdminController`:

```
app/Views/agent/admin/<id>.tab.php           # tab metadata + data provider (see below)
app/Views/agent/admin/<id>.php               # the tab body (rendered inside the admin layout)
app/Controllers/Admin/<Name>Controller.php   # POST handlers for that tab
```

`<id>.tab.php` returns an array: `label`, `icon`, `order` (position in the tab
bar) and a `data` closure that receives the request and the current user and
returns the variables the body view needs:

```php
<?php
// app/Views/agent/admin/webhooks.tab.php
return [
    'label' => 'Webhooks',
    'icon'  => 'zap',
    'order' => 90,
    'data'  => static function ($request, $me): array {
        $rows = db_connect()->table('webhook_endpoints')->orderBy('name')->get()->getResultArray();

        return ['webhookRows' => $rows];
    },
];
```

- `<id>` is the URL segment (`[a-z][a-z0-9_-]*`): the tab is served at
  `/app/admin/<id>` by `AdminController::index/$1`, which includes the
  `.tab.php` metadata, calls its `data` closure and renders `<id>.php` with
  those variables plus the shared admin ones (`$me`, `$settings`, …).
- Controllers under `app/Controllers/Admin/` extend `App\Controllers\BaseController`
  and must sit behind the `adminAuth` filter — register their routes in a
  module route file (next section) inside
  `$routes->group('app/admin', ['filter' => 'adminAuth'], …)`.
- Read and write settings through `App\Libraries\Settings::get()/set()`;
  mark secrets so they are encrypted at rest.
- Use `$this->toast()` for feedback and redirect back to the tab.

Look at an existing tab (for example `app/Views/agent/admin/canned.tab.php`
with `canned.php` and `app/Controllers/Admin/CannedController.php`) for the
markup conventions — tables, forms and the confirm-dialog pattern.

### A module route file

`app/Config/Routes.php` ends by requiring every `app/Config/Routes/*.php`
with `$routes` in scope, so a module owns its routes:

```php
<?php
// app/Config/Routes/webhooks.php

/** @var \CodeIgniter\Router\RouteCollection $routes */
$routes->group('app/admin', ['filter' => 'adminAuth'], static function ($routes) {
    $routes->post('webhooks', 'Admin\WebhooksController::create');
    $routes->post('webhooks/(:num)/delete', 'Admin\WebhooksController::delete/$1');
});
```

Rules of thumb:

- Pick the right filter: `agentAuth` (agents+), `adminAuth` (administrators),
  `portalAuth` (any signed-in user), `apiAuth` (bearer token, CSRF-exempt).
- Only `api/*` is exempt from CSRF; everything else must be a form POST with
  `csrf_field()`.
- Prefer explicit routes over auto-routing (auto-routing is off).
- Check `php spark routes` afterwards for collisions.

### A CLI command

`app/Commands/<Name>.php` extending `CodeIgniter\CLI\BaseCommand`, `$group = 'TicketHub'`,
`$name = 'tickethub:<verb>'`. Long-running commands should take a lock like
`tickets:cron` does so overlapping cron runs are harmless. Add it to the
cron table in `README.md` and to `docker/cron.sh` if it must run on a schedule.

## Pull request process

1. **Open an issue first** for anything bigger than a small fix so we can
   agree on the approach before you invest time.
2. Branch from `main`: `feat/<topic>`, `fix/<topic>`, `docs/<topic>`.
3. Keep PRs focused. A schema change plus its UI is one PR; three unrelated
   fixes are three PRs.
4. Before pushing: `composer cs` and `composer test`. CI runs the same plus
   a `php -l` sweep and a Docker build.
5. Fill in the PR template. Explain *why*, link the issue, add screenshots
   for UI changes (light and dark mode), and add a line under **Unreleased**
   in `CHANGELOG.md`.
6. A maintainer reviews within a few days. Expect questions about scoping,
   migrations and tests — those are the areas where mistakes hurt operators.
7. Squash-merge is the default; the PR title becomes the commit message, so
   write it as a changelog line (`Add webhook retry backoff`, not `fixes`).

### Commit messages

Imperative mood, short subject, blank line, then the why. Reference issues
with `Closes #123` / `Refs #123`.

## Licence

By contributing you agree that your contributions are licensed under the
project's MIT licence (see `LICENSE`).
