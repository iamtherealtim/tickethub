# Upgrading TicketHub

The running version is `TICKETHUB_VERSION` in `app/Config/Constants.php`; it is
shown at the bottom of the workspace sidebar, in the account menu, in the
portal footer and on the sign-in page. Releases are tagged with the same
number.

## Steps

0. **Check the PHP version** (`php -v`, and the version your web server runs;
   they can differ). Releases after 1.0.0 need **PHP 8.3 or newer**. On an
   older PHP the site and `spark` stop with a version message instead of
   running, so upgrade PHP first. Ubuntu 24.04 ships 8.3; Debian 12 ships
   8.2 and needs a newer PHP from a backport repository. The Docker image
   already includes PHP 8.4.

1. **Back up** the database and the `writable/` directory (attachments,
   sessions, logs live there):

   ```bash
   mysqldump -u tickethub -p tickethub > tickethub-$(date +%F).sql
   tar czf writable-$(date +%F).tgz writable
   ```

2. **Pull the release.** With git: `git fetch && git checkout v1.1.0` (or
   `git pull` on a tracking branch). With an archive: unpack it over the old
   tree, then restore your `.env` if the unpack replaced it. `.env` is never
   part of a release.

3. **Run the migrations.** Every schema change ships as a migration; they are
   idempotent and safe to re-run:

   ```bash
   php spark migrate
   ```

   Check with `php spark migrate:status` — every row should read as applied.

4. **Clear the cache** so config, settings and compiled views pick up the new
   code:

   ```bash
   php spark cache:clear
   ```

5. **Check the cron entry.** The background job must still point at the
   right path and run as the web user:

   ```
   */5 * * * * cd /var/www/tickethub && php spark tickets:cron >> writable/logs/cron.log 2>&1
   ```

   Run it once by hand (`php spark tickets:cron`) and confirm it exits 0.

6. **Read the release notes** for anything that needs a settings change (new
   Admin → General options, new `.env` keys in `env.production.example`).

7. **Smoke test**: sign in, open a ticket, load the portal, and look at the
   version string in the sidebar.

If anything is wrong, restore the database dump and `writable/`, check out the
previous tag, and run `php spark cache:clear` again. Migrations can be stepped
back with `php spark migrate:rollback`, but restoring the dump is simpler and
safer.

## How migrations are versioned

Migration files live in `app/Database/Migrations/` and are named with a
timestamp prefix, `YYYY-MM-DD-HHMMSS_Description.php` (for example
`2026-03-14-101500_AddTicketSlaPauseColumns.php`). CodeIgniter runs them in
timestamp order and records each applied file in the `migrations` table, so:

- a release can contain any number of migrations and `php spark migrate`
  applies only the ones your database has not seen;
- two developers can add migrations in parallel without renumbering — the
  timestamp orders them;
- never rename or edit a migration that has shipped; add a new one that
  corrects it.

Create a new one with `php spark make:migration AddSomething`, which writes the
timestamped file for you.
