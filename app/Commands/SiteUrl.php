<?php

namespace App\Commands;

use App\Libraries\EnvFile;
use App\Libraries\SiteAddress;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\App;

/**
 * Shows or changes the address TicketHub is served at (app.baseURL in .env),
 * and which reverse proxies it trusts for X-Forwarded-* headers.
 *
 *   php spark tickethub:url
 *   php spark tickethub:url https://helpdesk.example.com/
 *   php spark tickethub:url https://helpdesk.example.com/ --trust-proxy=10.0.0.5
 *
 * In Docker the address comes from the Docker .env instead (TICKETHUB_DOMAIN),
 * which the container applies on every start.
 */
class SiteUrl extends BaseCommand
{
    protected $group       = 'TicketHub';
    protected $name        = 'tickethub:url';
    protected $description = 'Shows or sets the site address (and trusted proxies) in .env.';
    protected $usage       = 'tickethub:url [address] [--trust-proxy=<ip,...>] [--no-trust-proxy] [--allow-http]';
    protected $arguments   = [
        'address' => 'The address people type, e.g. https://helpdesk.example.com/ (https:// is assumed if left out). Omit to show the current settings.',
    ];
    protected $options = [
        '--trust-proxy'    => 'IPs or CIDR ranges of the reverse proxy / load balancer in front of TicketHub, comma-separated. Only the proxy — never a range visitors come from.',
        '--no-trust-proxy' => 'Remove app.proxyIPs (TicketHub is reached directly).',
        '--allow-http'     => 'Accept a plain http:// address for something other than localhost (not recommended: sign-ins are not encrypted).',
    ];

    public function run(array $params)
    {
        $envPath = ROOTPATH . '.env';
        $input   = trim((string) ($params[0] ?? ''));
        $trust   = self::option('trust-proxy', $params);
        $untrust = self::flag('no-trust-proxy', $params);

        if ($input === '' && $trust === null && ! $untrust) {
            return $this->show();
        }

        if (getenv('TICKETHUB_DOCKER') === '1' && is_link($envPath)) {
            CLI::error('This install runs in Docker: the address comes from the Docker .env and is re-applied on every start.');
            CLI::write('Set TICKETHUB_DOMAIN=helpdesk.example.com (and TICKETHUB_TLS) in the Docker .env on the host, then run: docker compose up -d', 'yellow');

            return EXIT_ERROR;
        }
        if (! is_file($envPath)) {
            CLI::error('No .env file at ' . $envPath . '. Copy env.production.example to .env first.');

            return EXIT_ERROR;
        }
        if (! is_writable($envPath)) {
            CLI::error($envPath . ' is not writable by this user. Run the command as the file\'s owner (or with sudo -u).');

            return EXIT_ERROR;
        }

        $env = EnvFile::open($envPath);

        if ($input !== '') {
            try {
                $url = SiteAddress::normalise($input);
            } catch (\InvalidArgumentException $e) {
                CLI::error($e->getMessage());

                return EXIT_USER_INPUT;
            }
            $p = SiteAddress::parse($url);
            if ($p['scheme'] === 'http' && ! SiteAddress::isLocalHost($p['host'])
                && ! self::flag('allow-http', $params)) {
                CLI::error('http:// means passwords and sessions travel unencrypted, and Entra/OIDC/SAML sign-in will refuse the callback.');
                CLI::write('Set up HTTPS (docs/https.md) and use https://' . $p['authority'] . '/ — or pass --allow-http for a closed test network.', 'yellow');

                return EXIT_USER_INPUT;
            }
            $env->set('app.baseURL', $url, 'APP');
        }

        if ($untrust) {
            $env->remove('app.proxyIPs');
        } elseif ($trust !== null) {
            $list = [];
            foreach (preg_split('/[\s,]+/', trim((string) $trust)) ?: [] as $ip) {
                if ($ip === '') {
                    continue;
                }
                [$addr] = explode('/', $ip, 2);
                if (filter_var($addr, FILTER_VALIDATE_IP) === false) {
                    CLI::error('"' . $ip . '" is not an IP address or CIDR range.');

                    return EXIT_USER_INPUT;
                }
                $list[] = str_contains($ip, '/') ? $ip : $ip . (str_contains($ip, ':') ? '/128' : '/32');
            }
            if ($list === []) {
                CLI::error('--trust-proxy needs at least one IP address.');

                return EXIT_USER_INPUT;
            }
            $env->set('app.proxyIPs', implode(',', $list), 'APP');
        }

        $env->save();
        SiteAddress::clearCache();

        $url  = $env->get('app.baseURL') ?? config(App::class)->baseURL;
        $base = rtrim($url, '/') . '/';
        CLI::newLine();
        CLI::write('Saved to ' . $envPath, 'green');
        CLI::write('  Site address:     ' . $base);
        CLI::write('  Trusted proxies:  ' . ($env->get('app.proxyIPs') ?? 'none (reached directly)'));
        CLI::newLine();
        CLI::write('Takes effect on the next request — no restart needed.', 'cyan');
        if (str_starts_with($base, 'https://')) {
            CLI::write('HTTPS itself comes from your web server or proxy; see docs/https.md if it is not set up yet.');
        }
        CLI::newLine();
        CLI::write('If you use them, update these in the other systems too:', 'cyan');
        foreach ([
            'Entra ID redirect URI'  => 'auth/azure/callback',
            'OIDC redirect URI'      => 'auth/oidc/callback',
            'SAML ACS URL'           => 'auth/saml/acs',
            'SAML metadata'          => 'auth/saml/metadata',
            'Inbound email webhook'  => 'api/inbound-email',
        ] as $label => $path) {
            CLI::write('  ' . str_pad($label, 24) . $base . $path);
        }
        CLI::newLine();

        return EXIT_SUCCESS;
    }

    /**
     * An option given as `--name value` or `--name=value` (CodeIgniter's parser
     * only understands the first and files the second under "name=value").
     */
    public static function option(string $name, array $params = []): ?string
    {
        if (is_string($params[$name] ?? null)) {
            return $params[$name];
        }
        $value = CLI::getOption($name);
        if (is_string($value)) {
            return $value;
        }
        foreach (array_merge(array_keys(CLI::getOptions()), array_keys($params)) as $key) {
            if (is_string($key) && str_starts_with($key, $name . '=')) {
                return substr($key, strlen($name) + 1);
            }
        }

        return null;
    }

    public static function flag(string $name, array $params = []): bool
    {
        return array_key_exists($name, $params) || array_key_exists($name, CLI::getOptions());
    }

    private function show(): int
    {
        $app  = config(App::class);
        $plat = SiteAddress::platform();
        CLI::write('Site address:     ' . $app->baseURL);
        CLI::write('HTTPS enforced:   ' . ($app->forceGlobalSecureRequests ? 'yes (redirect, HSTS, Secure cookies)' : 'no'));
        CLI::write('Trusted proxies:  ' . ($app->proxyIPs ? implode(', ', array_keys($app->proxyIPs)) : 'none'));
        CLI::write('Environment:      ' . ENVIRONMENT . ($plat['docker'] ? ' (Docker, TICKETHUB_TLS=' . ($plat['tls'] ?: 'auto') . ')' : ''));
        CLI::newLine();
        CLI::write($plat['docker']
            ? 'To change it: edit TICKETHUB_DOMAIN in the Docker .env on the host, then docker compose up -d'
            : 'To change it: php spark tickethub:url https://helpdesk.example.com/', 'yellow');

        return EXIT_SUCCESS;
    }
}
