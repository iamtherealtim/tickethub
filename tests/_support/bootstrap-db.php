<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap for TicketHub.
 *
 * Runs CodeIgniter's own test bootstrap first, then configures the `tests`
 * database group used by the feature tests:
 *
 *   1. TH_TEST_DB_HOST / TH_TEST_DB_PORT / TH_TEST_DB_NAME / TH_TEST_DB_USER /
 *      TH_TEST_DB_PASSWORD environment variables win when set (this is what CI uses).
 *   2. Otherwise `database.tests.*` from .env, when present.
 *   3. Otherwise `database.default.*` from .env with the database renamed to
 *      `tickethub_test`, so a developer's normal credentials are reused against a
 *      separate database and the real one is never touched.
 *
 * Tests that need the database skip themselves when it is not reachable
 * (see Tests\Support\FeatureTestCase), so the unit tests always run.
 */

require __DIR__ . '/../../system/Test/bootstrap.php';

// The framework starter does not map the tests/_support namespace (that is
// normally composer's autoload-dev); register it so Tests\Support\* resolves.
service('autoloader')->addNamespace('Tests\Support', __DIR__);

(static function (): void {
    $envFile = HOMEPATH . '.env';
    $ini     = [];
    if (is_file($envFile)) {
        $parsed = @parse_ini_file($envFile, false, INI_SCANNER_RAW);
        if (is_array($parsed)) {
            $ini = $parsed;
        } else {
            // parse_ini_file chokes on a few valid dotenv lines; fall back to a
            // forgiving line parser so one odd entry does not disable DB tests.
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || ! str_contains($line, '=')) {
                    continue;
                }
                [$k, $v] = array_map('trim', explode('=', $line, 2));
                $ini[$k] = trim($v, "\"'");
            }
        }
    }
    // parse_ini_file keeps surrounding quotes in RAW mode; strip them.
    $ini = array_map(static fn ($v) => is_string($v) ? trim($v, "\"'") : $v, $ini);

    $pick = static function (string $envVar, string $testsKey, string $defaultKey, ?string $fallback) use ($ini): ?string {
        $v = getenv($envVar);
        if ($v !== false && $v !== '') {
            return $v;
        }
        if (isset($ini[$testsKey]) && $ini[$testsKey] !== '') {
            return (string) $ini[$testsKey];
        }
        if ($defaultKey !== '' && isset($ini[$defaultKey]) && $ini[$defaultKey] !== '') {
            return (string) $ini[$defaultKey];
        }

        return $fallback;
    };

    $settings = [
        'hostname' => $pick('TH_TEST_DB_HOST', 'database.tests.hostname', 'database.default.hostname', '127.0.0.1'),
        'port'     => $pick('TH_TEST_DB_PORT', 'database.tests.port', 'database.default.port', '3306'),
        'database' => $pick('TH_TEST_DB_NAME', 'database.tests.database', '', 'tickethub_test'),
        'username' => $pick('TH_TEST_DB_USER', 'database.tests.username', 'database.default.username', 'tickethub'),
        'password' => $pick('TH_TEST_DB_PASSWORD', 'database.tests.password', 'database.default.password', ''),
        'DBDriver' => 'MySQLi',
        'DBPrefix' => '',
        'charset'  => 'utf8mb4',
        'DBCollat' => 'utf8mb4_unicode_ci',
        'strictOn' => 'false',
    ];

    // A test database must never be the working database.
    if (($ini['database.default.database'] ?? '') !== '' && $settings['database'] === $ini['database.default.database']) {
        fwrite(STDERR, "bootstrap-db: refusing to run tests against the working database '{$settings['database']}'.\n");
        $settings['database'] = 'tickethub_test';
    }

    foreach ($settings as $key => $value) {
        $name = 'database.tests.' . $key;
        $value = (string) $value;
        putenv($name . '=' . $value);
        $_ENV[$name]    = $value;
        $_SERVER[$name] = $value;
    }
})();
