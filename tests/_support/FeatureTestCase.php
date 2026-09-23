<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Database\Seeds\TicketHubSeeder;
use CodeIgniter\Security\Security;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Database;
use Config\Services;
use mysqli;
use Throwable;

/**
 * Base class for HTTP feature tests that run against the `tests` database group
 * (tickethub_test — see tests/_support/bootstrap-db.php).
 *
 * Each test gets a freshly migrated and seeded database. When the database is
 * not reachable the whole test is skipped with a clear reason, so the suite
 * still passes on a laptop without a test database while CI runs everything.
 *
 * Helpers:
 *   - sessionFor($userId)                 session array for a signed-in user
 *   - postForm($path, $data, $session)    POST with a valid CSRF token
 *   - clearMustChangePassword()           seeded users start with the flag set
 */
abstract class FeatureTestCase extends CIUnitTestCase
{
    use FeatureTestTrait;
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $seed        = TicketHubSeeder::class;
    protected $seedOnce    = false;
    protected $namespace   = null;
    protected $DBGroup     = 'tests';

    /** Cached per process so an unreachable database is probed once, not per test. */
    private static ?string $dbProblem = null;
    private static bool $dbProbed     = false;

    protected function setUp(): void
    {
        $this->skipUnlessTestDatabase();

        parent::setUp();

        // The throttler is a shared service that would keep the previous test's
        // cache instance; rebind it to the fresh MockCache injected by setUp().
        Services::resetSingle('throttler');

        // Every seeded account must change its password at first sign-in. That is
        // right for a demo and wrong for nearly every test, so lift it here; tests
        // that exercise the flag set it again explicitly.
        $this->clearMustChangePassword();
    }

    /* ------------------------------------------------------------ database */

    protected function skipUnlessTestDatabase(): void
    {
        if (! self::$dbProbed) {
            self::$dbProbed  = true;
            self::$dbProblem = $this->probeTestDatabase();
        }
        if (self::$dbProblem !== null) {
            $this->markTestSkipped('tickethub_test database not reachable: ' . self::$dbProblem);
        }
    }

    /** Returns null when the `tests` group can be connected, otherwise the reason. */
    private function probeTestDatabase(): ?string
    {
        $cfg = (array) config(Database::class)->tests;

        // Cheap probe with a short timeout: the framework driver has none and a
        // firewalled host would otherwise stall the suite for minutes.
        if (($cfg['DBDriver'] ?? '') === 'MySQLi' && class_exists(mysqli::class)) {
            mysqli_report(MYSQLI_REPORT_OFF);
            $m = mysqli_init();
            $m->options(MYSQLI_OPT_CONNECT_TIMEOUT, 3);
            $ok = @$m->real_connect(
                (string) ($cfg['hostname'] ?? '127.0.0.1'),
                (string) ($cfg['username'] ?? ''),
                (string) ($cfg['password'] ?? ''),
                (string) ($cfg['database'] ?? ''),
                (int) ($cfg['port'] ?? 3306),
            );
            if (! $ok) {
                return sprintf(
                    '%s@%s:%d/%s — %s',
                    $cfg['username'] ?? '',
                    $cfg['hostname'] ?? '',
                    (int) ($cfg['port'] ?? 3306),
                    $cfg['database'] ?? '',
                    $m->connect_error ?: 'connection refused',
                );
            }
            $m->close();
        }

        try {
            $db = Database::connect($this->DBGroup, false);
            $db->initialize();
            $db->close();
        } catch (Throwable $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * Instead of running every migration's down() (which depends on each one
     * being a perfect inverse), start from an empty schema: drop everything.
     */
    protected function regressDatabase()
    {
        if ($this->migrate === false) {
            return;
        }
        // Deliberately not $this->db->listTables(): the 'tests' group's
        // connection is shared across test methods and listTables() caches
        // its result on it (BaseConnection::$dataCache['table_names']). A
        // later test can then read a snapshot an earlier test took
        // mid-migration and miss tables that snapshot predates, which then
        // survive to collide with the next migrate(). information_schema is
        // always a fresh read.
        $names = $this->db->query(
            'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()'
        )->getResultArray();
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($names as $row) {
            $table = $row['table_name'] ?? $row['TABLE_NAME'];
            $this->db->query('DROP TABLE IF EXISTS ' . $this->db->escapeIdentifiers($table));
        }
        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
        $this->db->resetDataCache();
    }

    protected function clearMustChangePassword(): void
    {
        $this->db->table('users')->where('must_change_password', 1)->update(['must_change_password' => 0]);
    }

    /* ------------------------------------------------------------- session */

    /** Session contents equivalent to AuthController::attempt() for this user. */
    protected function sessionFor(int $userId): array
    {
        $user = $this->db->table('users')->where('id', $userId)->get()->getRowArray();
        $this->assertNotNull($user, 'No seeded user with id ' . $userId);

        return [
            'user_id'       => (int) $user['id'],
            'role'          => $user['role'],
            'name'          => $user['name'],
            'session_epoch' => (int) ($user['session_epoch'] ?? 0),
        ];
    }

    protected function userByEmail(string $email): array
    {
        $user = $this->db->table('users')->where('email', $email)->get()->getRowArray();
        $this->assertNotNull($user, 'No seeded user ' . $email);

        return $user;
    }

    /* ---------------------------------------------------------------- CSRF */

    /**
     * Produces a token the CSRF filter (session-based, randomised) will accept and
     * stores the matching hash in the session that the next request will carry.
     *
     * @return array<string,string> POST field to merge into the form data
     */
    protected function csrfField(): array
    {
        $name = config('Security')->tokenName;
        $hash = bin2hex(random_bytes(16));

        // Security restores its hash from the session at construction, so seed the
        // session first and then replace the shared instance with a fresh one.
        $_SESSION[$name]      = $hash;
        $this->session[$name] = $hash;
        $security             = new Security(config('Security'));
        Services::injectMock('security', $security);

        return [$name => $security->getHash()];
    }

    /** POST a form as a browser would: with a session and a valid CSRF token. */
    protected function postForm(string $path, array $data = [], array $session = []): TestResponse
    {
        $this->withSession($session);

        return $this->post($path, $this->csrfField() + $data);
    }
}
