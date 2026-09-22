# TicketHub tests

```bash
composer install
composer test                                # = php vendor/bin/phpunit --no-coverage
php vendor/bin/phpunit tests/unit            # fast, no database
php vendor/bin/phpunit --filter ApiTest      # one class
php vendor/bin/phpunit --display-skipped     # show why tests were skipped
```

## Layout

| Directory | What lives there | Needs a database |
| --- | --- | --- |
| `tests/unit/` | Helpers (`TicketHubHelperTest`), config toggles (`ConfigTest`), language files, framework health | no |
| `tests/feature/` | HTTP tests through the real router, filters and controllers: `AuthTest`, `ScopeTest`, `ApiTest` | yes (`tickethub_test`) |
| `tests/feature/CsrfTest.php` | CSRF is enforced before any controller runs | no |
| `tests/session/` | Session handling smoke test | no |
| `tests/_support/` | `bootstrap-db.php` (PHPUnit bootstrap), `FeatureTestCase` (base class for DB-backed feature tests), `Libraries/ConfigReader` | – |

## The test database

Feature tests run against the `tests` database group — a **separate**
database, `tickethub_test`, that is dropped, migrated (all namespaces) and
seeded with `TicketHubSeeder` before every test. Your working database is never
touched; the bootstrap refuses to run if the two names are equal.

Connection details are resolved by `tests/_support/bootstrap-db.php`, in order:

1. `TH_TEST_DB_HOST`, `TH_TEST_DB_PORT`, `TH_TEST_DB_NAME`, `TH_TEST_DB_USER`,
   `TH_TEST_DB_PASSWORD` environment variables (this is what CI sets);
2. `database.tests.*` entries in `.env`;
3. `database.default.*` from `.env` with the database name replaced by
   `tickethub_test`.

Driver-level settings (MySQLi, no prefix, utf8mb4, strict mode off) are pinned
in `phpunit.dist.xml`.

Create the database once and grant your `.env` user access:

```sql
CREATE DATABASE tickethub_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL ON tickethub_test.* TO 'tickethub'@'%';
```

If the database is not reachable, every DB-backed test is **skipped** with the
reason (`tickethub_test database not reachable: …`) and the rest of the suite
still runs. CI passes `--fail-on-skipped` so that can never hide a problem
there.

## Writing a feature test

Extend `Tests\Support\FeatureTestCase`. It gives you:

- a fresh, seeded database per test, with every seeded user's
  `must_change_password` flag cleared (set it again in tests that need it);
- `$this->sessionFor($userId)` — a session array equivalent to signing in;
- `$this->postForm($path, $data, $session)` — a browser-style POST carrying a
  valid CSRF token;
- `$this->userByEmail($email)` — seeded user rows;
- `$this->db` — the `tests` connection for assertions.

Seeded people worth knowing: `maya.ortiz@tickethub.co` (Administrator, id 1),
`devin.park@…` (Agent, group 1), `priya.raman@…` (Agent, group 2),
`jordan.whitfield@…` and `camille.roy@…` (Requesters). Ticket `SR-4379` is a
group-1 ticket requested by Jordan.

```php
final class WatchersTest extends FeatureTestCase
{
    public function testAgentCanWatchATicketInTheirGroup(): void
    {
        $devin  = $this->userByEmail('devin.park@tickethub.co');
        $result = $this->postForm('app/tickets/SR-4379/watchers', ['user_id' => $devin['id']], $this->sessionFor((int) $devin['id']));

        $result->assertRedirect();
        $this->seeInDatabase('ticket_watchers', ['user_id' => $devin['id']]);
    }
}
```

Tests that only need the framework (no data) can extend
`CodeIgniter\Test\CIUnitTestCase` with `FeatureTestTrait` directly, as
`CsrfTest` does.

## Coverage

`composer test:coverage` runs PHPUnit with the coverage settings from
`phpunit.dist.xml` (HTML report in `build/logs/html/`). It needs Xdebug
(`xdebug.mode=coverage`) or PCOV.

Copy `phpunit.dist.xml` to `phpunit.xml` (git-ignored) to tailor the
configuration locally.

Further reading: [CodeIgniter testing guide](https://codeigniter.com/user_guide/testing/index.html),
[PHPUnit documentation](https://docs.phpunit.de/).
