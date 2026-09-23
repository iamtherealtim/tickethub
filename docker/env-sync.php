<?php

/**
 * Brings the generated writable/.env in line with the Docker .env on every start,
 * so changing the domain, certificate mode, database or environment and running
 * `docker compose up -d` actually takes effect. (It used to be written once, on
 * first boot, and silently ignored every later change.)
 *
 *   php docker/env-sync.php <path-to-.env>
 *
 * Only the keys below are managed. encryption.key is created once and then
 * left alone unless ENCRYPTION_KEY is set explicitly; anything else an
 * operator adds to the file by hand is kept.
 */

declare(strict_types=1);

require __DIR__ . '/../app/Libraries/EnvFile.php';

use App\Libraries\EnvFile;

$path = $argv[1] ?? '';
if ($path === '') {
    fwrite(STDERR, "usage: php docker/env-sync.php <path-to-.env>\n");
    exit(2);
}

$get = static fn (string $k, string $default = ''): string => ($v = getenv($k)) === false || trim($v) === '' ? $default : trim($v);

// Site address: explicit APP_BASE_URL wins; otherwise it follows the domain.
// Every TLS mode with a domain — including "upstream" (HTTPS ends before Caddy)
// — means visitors use https://. No domain = local demo on http://localhost.
$domain = strtolower((string) preg_replace(['#^[a-z]*://#i', '#[/:].*$#'], '', $get('TICKETHUB_DOMAIN')));
$baseUrl = $get('APP_BASE_URL');
if ($baseUrl === 'http://localhost:8080/') {
    // The pre-Caddy template's default. The stack now answers on port 80 (or
    // on TICKETHUB_DOMAIN), so honouring it would point every link at a dead port.
    fwrite(STDOUT, "[entrypoint] ignoring APP_BASE_URL=http://localhost:8080/ (the old default) — delete that line from the Docker .env; set TICKETHUB_DOMAIN instead\n");
    $baseUrl = '';
}
if ($baseUrl === '') {
    // "443", "8443" or "192.168.1.51:443" — only a non-standard port shows up in the address.
    $port = static fn (string $bind, string $default): string => ($p = (string) preg_replace('/^.*:/', '', $bind)) === $default || $p === '' ? '' : ':' . $p;
    $tls  = strtolower($get('TICKETHUB_TLS', 'auto'));
    $baseUrl = $domain !== ''
        ? 'https://' . $domain . ($tls === 'upstream' ? '' : $port($get('TICKETHUB_HTTPS_BIND', '443'), '443')) . '/'
        : 'http://localhost' . $port($get('TICKETHUB_HTTP_BIND', '80'), '80') . '/';
}
$baseUrl = rtrim($baseUrl, '/') . '/';

// The app container publishes no ports: every request reaches it through the
// bundled Caddy on the compose network, so the private ranges are exactly
// "the proxy". Override with APP_PROXY_IPS if you publish the app yourself.
$proxies = $get('APP_PROXY_IPS', '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,fc00::/7');

$isNew = ! is_file($path);
if ($isNew) {
    file_put_contents($path, "# Written by docker/entrypoint.sh from the Docker .env. Managed keys (environment,\n"
        . "# address, proxies, database) are re-applied on every start; edit the Docker .env\n"
        . "# instead. Other keys you add here are kept. Mount your own .env to opt out.\n");
}
$env = EnvFile::open($path);

$env->set('CI_ENVIRONMENT', $get('CI_ENVIRONMENT', 'production'));
$env->set('app.baseURL', $baseUrl, 'APP');
$env->set('app.proxyIPs', $proxies, 'APP');
if ($get('APP_FORCE_HTTPS') !== '') {
    $env->set('app.forceGlobalSecureRequests', $get('APP_FORCE_HTTPS') === 'true' || $get('APP_FORCE_HTTPS') === '1' ? 'true' : 'false', 'APP');
} else {
    $env->remove('app.forceGlobalSecureRequests');
}

foreach ([
    'hostname' => ['DB_HOST', 'db'],
    'port'     => ['DB_PORT', '3306'],
    'database' => ['DB_NAME', 'tickethub'],
    'username' => ['DB_USER', 'tickethub'],
    'password' => ['DB_PASSWORD', ''],
] as $key => [$var, $default]) {
    $env->set('database.default.' . $key, $get($var, $default), 'DATABASE');
}
$env->set('database.default.DBDriver', 'MySQLi', 'DATABASE');
$env->set('database.default.charset', 'utf8mb4', 'DATABASE');
$env->set('database.default.DBCollat', 'utf8mb4_unicode_ci', 'DATABASE');

$key = $get('ENCRYPTION_KEY');
if ($key !== '') {
    $env->set('encryption.key', $key, 'ENCRYPTION');
} elseif ($env->get('encryption.key') === null || $env->get('encryption.key') === '') {
    $env->set('encryption.key', 'hex2bin:' . bin2hex(random_bytes(32)), 'ENCRYPTION');
    fwrite(STDOUT, "[entrypoint] generated a new encryption.key (set ENCRYPTION_KEY to keep it stable across rebuilt volumes)\n");
}

$env->save(0640);
fwrite(STDOUT, '[entrypoint] ' . ($isNew ? 'created' : 'updated') . " {$path}: address {$baseUrl}, environment " . $get('CI_ENVIRONMENT', 'production') . "\n");
