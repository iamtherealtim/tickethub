<?php

declare(strict_types=1);

namespace App\Libraries;

use CodeIgniter\HTTP\IncomingRequest;
use Config\App;

/**
 * Admin → Address & HTTPS: what address TicketHub believes it lives at, how
 * the current request actually reached it, the certificate in front of it,
 * and plain-language fixes when those disagree.
 *
 * check() is pure (arrays in, issues out) so every rule is unit-testable;
 * the gatherers around it read config, the request and the TLS handshake.
 */
final class SiteAddress
{
    public const CERT_CACHE_KEY = 'th_site_cert';
    public const CERT_TTL       = 3600;

    /**
     * Readable copy of Caddy's internal root CA certificate, published by
     * docker/caddy/start.sh onto the caddy_data volume, which the app mounts
     * read-only (docker-compose.yml).
     */
    public const ROOT_CA_PATH = '/caddy-data/tickethub-root-ca.crt';

    /** @return array{url: string, scheme: string, host: string, port: int, authority: string} */
    public static function parse(string $url): array
    {
        $p      = parse_url($url) ?: [];
        $scheme = strtolower($p['scheme'] ?? 'http');
        $host   = strtolower($p['host'] ?? '');
        $port   = (int) ($p['port'] ?? ($scheme === 'https' ? 443 : 80));

        return [
            'url'       => $url,
            'scheme'    => $scheme,
            'host'      => $host,
            'port'      => $port,
            'authority' => $host . ($port === ($scheme === 'https' ? 443 : 80) ? '' : ':' . $port),
        ];
    }

    public static function isLocalHost(string $host): bool
    {
        $host = trim(strtolower($host), '[]');

        return $host === 'localhost' || str_ends_with($host, '.localhost')
            || str_starts_with($host, '127.') || $host === '::1';
    }

    /**
     * Normalises what an operator typed into a base URL: scheme required,
     * no path beyond "/", no query, always a trailing slash.
     *
     * @throws \InvalidArgumentException with a message fit for the operator
     */
    public static function normalise(string $input): string
    {
        $input = trim($input);
        if ($input !== '' && ! preg_match('#^[a-z][a-z0-9+.-]*://#i', $input)) {
            $input = 'https://' . $input;
        }
        $p = parse_url($input);
        if ($p === false || empty($p['host'])) {
            throw new \InvalidArgumentException('That is not a valid address. Example: https://helpdesk.example.com/');
        }
        $scheme = strtolower($p['scheme'] ?? '');
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('The address must start with https:// (or http:// for a local demo).');
        }
        if (isset($p['query']) || isset($p['fragment']) || isset($p['user']) || isset($p['pass'])) {
            throw new \InvalidArgumentException('Use just the address — no ?query, #fragment or user:password@.');
        }
        $host = strtolower($p['host']);
        if (! preg_match('/^(\[[0-9a-f:.]+\]|[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*)$/', $host)) {
            throw new \InvalidArgumentException('"' . $p['host'] . '" is not a valid host name.');
        }
        $path = '/' . trim($p['path'] ?? '', '/');

        return $scheme . '://' . $host . (isset($p['port']) ? ':' . (int) $p['port'] : '') . rtrim($path, '/') . '/';
    }

    /** True when $ip is inside one of the proxyIPs CIDRs (CodeIgniter's own rule). */
    public static function ipInProxies(string $ip, array $proxyIPs): bool
    {
        foreach (array_keys($proxyIPs) as $cidr) {
            if (self::ipInCidr($ip, (string) $cidr)) {
                return true;
            }
        }

        return false;
    }

    public static function ipInCidr(string $ip, string $cidr): bool
    {
        [$net, $bits] = str_contains($cidr, '/') ? explode('/', $cidr, 2) : [$cidr, null];
        $a = @inet_pton($ip);
        $b = @inet_pton($net);
        if ($a === false || $b === false || strlen($a) !== strlen($b)) {
            return false;
        }
        $bits = $bits === null ? strlen($a) * 8 : max(0, min(strlen($a) * 8, (int) $bits));
        $bytes = intdiv($bits, 8);
        if (substr($a, 0, $bytes) !== substr($b, 0, $bytes)) {
            return false;
        }
        $rem = $bits % 8;
        if ($rem === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rem)) & 0xFF;

        return (ord($a[$bytes]) & $mask) === (ord($b[$bytes]) & $mask);
    }

    /** How the platform is set up, from the container environment (empty for manual installs). */
    public static function platform(): array
    {
        $docker = getenv('TICKETHUB_DOCKER') === '1';

        return [
            'docker'   => $docker,
            'domain'   => $docker ? strtolower(trim((string) getenv('TICKETHUB_DOMAIN'))) : '',
            'tls'      => $docker ? strtolower(trim((string) getenv('TICKETHUB_TLS'))) : '',
            'provider' => $docker ? strtolower(trim((string) getenv('TICKETHUB_DNS_PROVIDER'))) : '',
        ];
    }

    /** Snapshot of config + request for check(). */
    public static function gather(IncomingRequest $request): array
    {
        /** @var App $app */
        $app    = config(App::class);
        $remote = (string) ($request->getServer('REMOTE_ADDR') ?? '');
        $xfp    = $request->getHeaderLine('X-Forwarded-Proto');

        return [
            'config' => [
                'baseURL'     => $app->baseURL,
                'forceHttps'  => $app->forceGlobalSecureRequests,
                'proxyIPs'    => $app->proxyIPs,
                'environment' => ENVIRONMENT,
                'platform'    => self::platform(),
            ],
            'request' => [
                'secure'  => $request->isSecure(),
                'host'    => strtolower($request->getHeaderLine('Host')),
                'remote'  => $remote,
                'xfp'     => $xfp,
                'xff'     => $request->getHeaderLine('X-Forwarded-For'),
                'trusted' => $remote !== '' && self::ipInProxies($remote, $app->proxyIPs),
            ],
        ];
    }

    /**
     * The rules. Each issue: level (error|warning|info), title, detail, and an
     * optional fix — a line to paste somewhere, with where in `fixWhere`.
     *
     * @param array{config: array, request: array} $state
     * @param array|null                            $cert result of certificate()
     *
     * @return list<array{level: string, title: string, detail: string, fix?: string, fixWhere?: string}>
     */
    public static function check(array $state, ?array $cert = null): array
    {
        $c       = $state['config'];
        $r       = $state['request'];
        $base    = self::parse($c['baseURL']);
        $prod    = $c['environment'] === 'production';
        $docker  = ! empty($c['platform']['docker']);
        $issues  = [];
        $setWhere = $docker ? 'the Docker .env (next to compose.yaml), then `docker compose up -d`' : 'the server shell, in the TicketHub folder';

        // 1. Reached on a different address than the configured one.
        $reqAuthority = self::requestAuthority($r['host'], $r['secure']);
        if ($r['host'] !== '' && $reqAuthority !== $base['authority']) {
            $seen = ($r['secure'] ? 'https' : 'http') . '://' . $reqAuthority . '/';
            $reqHost = (string) preg_replace('/:\d+$/', '', $reqAuthority);
            // Suggest the https form unless this is plainly a local demo (the
            // command refuses http:// for real hosts anyway).
            $suggest = $r['secure'] || self::isLocalHost($reqHost) ? $seen : 'https://' . $reqHost . '/';
            $issues[] = [
                'level'    => 'warning',
                'title'    => 'You opened TicketHub at a different address than it is configured for',
                'detail'   => 'This page came through ' . $seen . ', but TicketHub builds every link, email and sign-in callback from ' . $base['url'] . '. People following links in emails will land on the configured address. If ' . $reqHost . ' is the right name, change the setting.',
                'fix'      => $docker ? 'TICKETHUB_DOMAIN=' . $reqHost : 'php spark tickethub:url ' . $suggest,
                'fixWhere' => $setWhere,
            ];
        }

        // 2. The configured address itself is plain http.
        if ($base['scheme'] === 'http') {
            if (self::isLocalHost($base['host'])) {
                // Development on localhost is the normal dev setup — say nothing.
                if ($prod) {
                    $issues[] = [
                        'level'  => 'info',
                        'title'  => 'Running as a local demo over plain http',
                        'detail' => 'Fine on this computer. Before other people use it, give it a real address with HTTPS.',
                    ];
                }
            } elseif ($prod) {
                $issues[] = [
                    'level'    => 'error',
                    'title'    => 'The site address is http:// — sign-ins are not encrypted',
                    'detail'   => 'Passwords and session cookies cross the network in the clear, and Microsoft Entra, OIDC and SAML sign-in refuse non-HTTPS callbacks. Put HTTPS in front and switch the address to https://.',
                    'fix'      => $docker ? "TICKETHUB_DOMAIN={$base['host']}\nTICKETHUB_TLS=internal   # or auto | dns | files" : 'php spark tickethub:url https://' . $base['authority'] . '/',
                    'fixWhere' => $setWhere,
                ];
            }
        }

        // 3. Configured for https, but this request is not seen as secure.
        if ($base['scheme'] === 'https' && ! $r['secure']) {
            if ($r['xfp'] !== '' && ! $r['trusted']) {
                $issues[] = [
                    'level'    => 'error',
                    'title'    => 'A proxy in front of TicketHub is not trusted',
                    'detail'   => 'The request came from ' . $r['remote'] . ' with X-Forwarded-Proto: ' . $r['xfp'] . ', but that address is not in app.proxyIPs, so TicketHub ignores the header. With HTTPS enforced this shows up as an endless redirect loop. Trust the proxy (only the proxy — never a range that ordinary visitors come from).',
                    'fix'      => $docker ? 'APP_PROXY_IPS=' . $r['remote'] . '/32' : 'php spark tickethub:url ' . $base['url'] . ' --trust-proxy=' . $r['remote'],
                    'fixWhere' => $setWhere,
                ];
            } else {
                $issues[] = [
                    'level'  => 'warning',
                    'title'  => 'This page was not served over HTTPS',
                    'detail' => 'The address is https:// but this request arrived as plain http and was not redirected, because app.forceGlobalSecureRequests is off. Anyone on the network path can read the session.',
                ];
            }
        }

        // 4. Development mode reachable by others.
        if (! $prod && ! self::isLocalHost($base['host'])) {
            $issues[] = [
                'level'  => 'warning',
                'title'  => 'Development mode on a shared address',
                'detail' => 'CI_ENVIRONMENT is "' . $c['environment'] . '": detailed error pages and the debug toolbar are on, HTTPS is not enforced and the demo seeder is allowed. Use production for anything other people reach.',
                'fix'      => $docker ? 'CI_ENVIRONMENT=production' : 'CI_ENVIRONMENT = production',
                'fixWhere' => $docker ? 'the Docker .env (next to compose.yaml), then `docker compose up -d`' : '.env',
            ];
        }

        // 5. Certificate.
        if ($cert !== null && $base['scheme'] === 'https') {
            array_push($issues, ...self::certIssues($cert, $base['host'], $c['platform']['tls'] ?? ''));
        }

        return $issues;
    }

    /** @return list<array{level: string, title: string, detail: string}> */
    public static function certIssues(array $cert, string $host, string $tlsMode, ?int $now = null): array
    {
        $now ??= time();
        if (! empty($cert['error'])) {
            return [[
                'level'  => 'warning',
                'title'  => 'Could not read the certificate',
                'detail' => 'TicketHub tried to connect to ' . $cert['target'] . ' to inspect the certificate and got: ' . $cert['error'] . '. This check runs from the server itself, so a firewall or DNS that only works from outside can cause this; if browsers show a padlock you can ignore it.',
            ]];
        }
        $out  = [];
        $days = (int) floor(($cert['validTo'] - $now) / 86400);
        $auto = in_array($tlsMode, ['auto', 'dns', 'internal'], true);
        if ($cert['validTo'] < $now) {
            $out[] = ['level' => 'error', 'title' => 'The certificate has expired', 'detail' => 'It expired on ' . gmdate('j M Y', $cert['validTo']) . '. Browsers now block the site. ' . ($auto ? 'Automatic renewal is failing — check `docker compose logs caddy`.' : 'Install a renewed certificate.')];
        } elseif ($days < 14 || (! $auto && $days < 30)) {
            $out[] = ['level' => 'warning', 'title' => 'The certificate expires in ' . $days . ' day' . ($days === 1 ? '' : 's'), 'detail' => $auto ? 'It should have renewed by now; check `docker compose logs caddy` for renewal errors.' : 'Renew it before ' . gmdate('j M Y', $cert['validTo']) . ' and replace the files' . ($tlsMode === 'files' ? ' in the certs/ folder next to compose.yaml, then `docker compose restart caddy`.' : '.')];
        }
        if (! self::certCoversHost($cert['names'] ?? [], $host)) {
            $out[] = ['level' => 'error', 'title' => 'The certificate is not for ' . $host, 'detail' => 'It covers ' . (implode(', ', $cert['names'] ?? []) ?: 'no names') . '. Browsers show a security warning. Get a certificate that includes ' . $host . '.'];
        }
        if (empty($cert['trusted']) && $cert['validTo'] >= $now) {
            $out[] = $tlsMode === 'internal'
                ? ['level' => 'info', 'title' => 'Certificate from TicketHub\'s own internal authority', 'detail' => 'Browsers warn until the authority\'s root certificate is installed on each computer — see "Trusting the internal certificate" below.']
                : ['level' => 'info', 'title' => 'This server does not recognise the certificate\'s issuer', 'detail' => 'Expected for a company (internal) certificate authority: what matters is that your users\' computers trust it. If browsers show a warning, install the issuing CA\'s certificate on them (usually via Group Policy or Intune).'];
        }

        return $out;
    }

    public static function certCoversHost(array $names, string $host): bool
    {
        foreach ($names as $n) {
            $n = strtolower($n);
            if ($n === $host) {
                return true;
            }
            if (str_starts_with($n, '*.') && substr_count($host, '.') === substr_count($n, '.')
                && str_ends_with($host, substr($n, 1))) {
                return true;
            }
        }

        return false;
    }

    /** host[:port] as sent, with the default port for the scheme dropped. */
    private static function requestAuthority(string $hostHeader, bool $secure): string
    {
        $default = $secure ? '443' : '80';
        if (preg_match('/^(.*):(\d+)$/', $hostHeader, $m) && ! str_ends_with($m[1], ':')) {
            return $m[2] === $default ? $m[1] : $hostHeader;
        }

        return $hostHeader;
    }

    /**
     * Connects to the site's TLS endpoint and reads its certificate. In Docker
     * it asks the bundled Caddy directly (the public name may not resolve from
     * inside the container); otherwise the configured host.
     */
    public static function certificate(bool $useCache = true): ?array
    {
        $base = self::parse(config(App::class)->baseURL);
        if ($base['scheme'] !== 'https' || $base['host'] === '') {
            return null;
        }
        $key = self::CERT_CACHE_KEY . '_' . md5($base['authority']);
        if ($useCache && is_array($hit = cache($key))) {
            return $hit;
        }

        $p       = self::platform();
        $connect = ($p['docker'] && $p['domain'] !== '' && $p['tls'] !== 'upstream') ? 'caddy' : $base['host'];
        $port    = $connect === 'caddy' ? 443 : $base['port'];
        $result  = self::inspect($connect, $port, $base['host']);
        cache()->save($key, $result, self::CERT_TTL);

        return $result;
    }

    public static function clearCache(): void
    {
        $base = self::parse(config(App::class)->baseURL);
        cache()->delete(self::CERT_CACHE_KEY . '_' . md5($base['authority']));
    }

    /** Cached certificate result only — never opens a connection (for the banner). */
    public static function cachedCertificate(): ?array
    {
        $base = self::parse(config(App::class)->baseURL);
        $hit  = cache(self::CERT_CACHE_KEY . '_' . md5($base['authority']));

        return is_array($hit) ? $hit : null;
    }

    public static function inspect(string $connect, int $port, string $sni): array
    {
        $target = $connect . ':' . $port;
        // $verify checks the issuer chain only; whether the name matches is
        // judged separately (certCoversHost) so the two problems are reported apart.
        $open   = static function (bool $verify) use ($connect, $port, $sni, &$err) {
            $ctx = stream_context_create(['ssl' => [
                'peer_name'         => $sni,
                'SNI_enabled'       => true,
                'capture_peer_cert' => true,
                'verify_peer'       => $verify,
                'verify_peer_name'  => false,
                'allow_self_signed' => ! $verify,
            ]]);
            $errno    = 0;
            $errstr   = '';
            $warnings = [];
            set_error_handler(static function (int $no, string $msg) use (&$warnings): bool {
                $warnings[] = $msg;

                return true;
            });

            try {
                $s = stream_socket_client('ssl://' . $connect . ':' . $port, $errno, $errstr, 4, STREAM_CLIENT_CONNECT, $ctx);
            } finally {
                restore_error_handler();
            }
            if ($s === false) {
                // OpenSSL's own reason ("certificate verify failed") beats "Unable to connect".
                $err = trim($errstr);
                foreach ($warnings as $w) {
                    if (preg_match('/error:[0-9A-F]+:[^:]*:[^:]*:(.+)$/i', $w, $m) || preg_match('/(certificate verify failed|getaddrinfo.*|Connection refused|timed out)/i', $w, $m)) {
                        $err = trim($m[1]);

                        break;
                    }
                }
            }

            return [$s, $ctx];
        };

        $err = '';
        [$s, $ctx] = $open(false);
        if ($s === false) {
            return ['target' => $target, 'error' => $err ?: 'connection failed', 'checkedAt' => time()];
        }
        $pem = stream_context_get_params($s)['options']['ssl']['peer_certificate'] ?? null;
        fclose($s);
        $x = $pem ? openssl_x509_parse($pem) : false;
        if (! $x) {
            return ['target' => $target, 'error' => 'no certificate presented', 'checkedAt' => time()];
        }

        $names = [];
        foreach (explode(',', (string) ($x['extensions']['subjectAltName'] ?? '')) as $san) {
            $san = trim($san);
            if (str_starts_with($san, 'DNS:')) {
                $names[] = substr($san, 4);
            } elseif (str_starts_with($san, 'IP Address:')) {
                $names[] = substr($san, 11);
            }
        }
        if ($names === [] && isset($x['subject']['CN'])) {
            $names[] = (string) $x['subject']['CN'];
        }

        $err = '';
        [$v] = $open(true);
        $trusted = $v !== false;
        if ($v) {
            fclose($v);
        }

        $issuer = $x['issuer']['O'] ?? $x['issuer']['CN'] ?? '';

        return [
            'target'      => $target,
            'names'       => $names,
            'issuer'      => is_array($issuer) ? implode(', ', $issuer) : (string) $issuer,
            'issuerCN'    => is_array($x['issuer']['CN'] ?? '') ? implode(', ', $x['issuer']['CN']) : (string) ($x['issuer']['CN'] ?? ''),
            'validFrom'   => (int) $x['validFrom_time_t'],
            'validTo'     => (int) $x['validTo_time_t'],
            'trusted'     => $trusted,
            'trustError'  => $trusted ? '' : $err,
            'checkedAt'   => time(),
        ];
    }

    /** Addresses operators paste into identity providers and other systems. */
    public static function callbacks(): array
    {
        return [
            ['Microsoft Entra ID — redirect URI (Web)', site_url('auth/azure/callback')],
            ['OIDC / Google — redirect URI', site_url('auth/oidc/callback')],
            ['SAML — Assertion Consumer Service (ACS) URL', site_url('auth/saml/acs')],
            ['SAML — service provider metadata', site_url('auth/saml/metadata')],
            ['Inbound email webhook (POST)', site_url('api/inbound-email')],
            ['REST API base', site_url('api')],
            ['Self-service portal', site_url('portal')],
        ];
    }
}
