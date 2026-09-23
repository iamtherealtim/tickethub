<?php

use App\Libraries\EnvFile;
use App\Libraries\SiteAddress;
use CodeIgniter\Config\DotEnv;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;

/**
 * Site address / HTTPS setup: the .env writer shared with the Docker
 * entrypoint, proxy parsing, address normalisation and the rules behind
 * Admin → Address & HTTPS.
 *
 * @internal
 */
final class SiteAddressTest extends CIUnitTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/th-env-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', array_filter(glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [], 'is_file'));
        @rmdir($this->dir);
        parent::tearDown();
    }

    /** What CodeIgniter itself reads back from a file. */
    private function parseWithCodeIgniter(string $contents): array
    {
        file_put_contents($this->dir . '/.env', $contents);
        $vars = (new class ($this->dir) extends DotEnv {
            protected function setVariable(string $name, string $value = '')
            {
                // Parse only; never leak into the test process environment.
            }
        })->parse();

        return $vars ?? [];
    }

    public function testEnvFileValuesRoundTripThroughCodeIgniter(): void
    {
        $values = [
            'app.baseURL'              => 'https://helpdesk.example.com/',
            'database.default.password' => 'p@ss "quoted" \\ back\\slash # not a comment',
            'single'                   => "it's",
            'spaces'                   => 'two words',
            'empty'                    => '',
            'proxies'                  => '10.0.0.0/8,fc00::/7',
            'key'                      => 'hex2bin:00ff',
        ];
        $env = EnvFile::open($this->dir . '/.env');
        foreach ($values as $k => $v) {
            $env->set($k, $v);
        }
        $env->save();

        $read = $this->parseWithCodeIgniter((string) file_get_contents($this->dir . '/.env'));
        foreach ($values as $k => $v) {
            $this->assertSame($v, $read[$k] ?? null, $k);
            $this->assertSame($v, EnvFile::open($this->dir . '/.env')->get($k), $k . ' via EnvFile::get');
        }
    }

    public function testEnvFileKeepsEverythingElseAndReplacesInPlace(): void
    {
        $path = $this->dir . '/.env';
        file_put_contents($path, "# my notes\nCI_ENVIRONMENT = production\n\n# app.baseURL = 'http://old/'\napp.baseURL = 'http://localhost:8080/'\ncustom.thing = keep-me\n");

        EnvFile::open($path)->set('app.baseURL', 'https://new.example/')->set('app.proxyIPs', '10.0.0.5/32', 'APP')->remove('nothing.here')->save();

        $this->assertSame(
            "# my notes\nCI_ENVIRONMENT = production\n\n# app.baseURL = 'http://old/'\napp.baseURL = https://new.example/\ncustom.thing = keep-me\n\n# APP\napp.proxyIPs = 10.0.0.5/32\n",
            file_get_contents($path)
        );
        EnvFile::open($path)->remove('custom.thing')->save();
        $this->assertStringNotContainsString('custom.thing', (string) file_get_contents($path));
        $this->assertStringContainsString("# app.baseURL = 'http://old/'", (string) file_get_contents($path), 'comments are never touched');
    }

    public function testProxyIpsParsing(): void
    {
        $this->assertSame(
            ['10.0.0.0/8' => 'X-Forwarded-For', '172.16.0.0/12' => 'X-Forwarded-For', 'fc00::/7' => 'X-Forwarded-For'],
            App::parseProxyIPs('10.0.0.0/8, 172.16.0.0/12,fc00::/7')
        );
        $this->assertSame(['10.0.0.5/32' => 'X-Real-IP', 'fd00::beef' => 'X-Forwarded-For'], App::parseProxyIPs('10.0.0.5/32:X-Real-IP fd00::beef'));
        $this->assertSame([], App::parseProxyIPs('  '));
    }

    public function testNormalise(): void
    {
        $this->assertSame('https://helpdesk.example.com/', SiteAddress::normalise('helpdesk.example.com'));
        $this->assertSame('https://helpdesk.example.com/', SiteAddress::normalise('HTTPS://Helpdesk.Example.com'));
        $this->assertSame('https://example.com:8443/desk/', SiteAddress::normalise('https://example.com:8443/desk'));
        $this->assertSame('http://localhost/', SiteAddress::normalise('http://localhost'));
        foreach (['ftp://x.com', 'https://', 'https://x.com/?a=1', 'https://u:p@x.com', 'https://bad_host.com', ''] as $bad) {
            try {
                SiteAddress::normalise($bad);
                $this->fail('accepted ' . $bad);
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testCidr(): void
    {
        $this->assertTrue(SiteAddress::ipInCidr('172.18.0.4', '172.16.0.0/12'));
        $this->assertFalse(SiteAddress::ipInCidr('172.32.0.1', '172.16.0.0/12'));
        $this->assertTrue(SiteAddress::ipInCidr('10.1.2.3', '10.1.2.3'));
        $this->assertTrue(SiteAddress::ipInCidr('fd12::1', 'fc00::/7'));
        $this->assertFalse(SiteAddress::ipInCidr('10.0.0.1', 'fc00::/7'));
        $this->assertFalse(SiteAddress::ipInCidr('nonsense', '10.0.0.0/8'));
    }

    private function state(array $config = [], array $request = []): array
    {
        return [
            'config' => $config + [
                'baseURL' => 'https://desk.example.com/', 'forceHttps' => true, 'proxyIPs' => [],
                'environment' => 'production', 'platform' => ['docker' => false, 'domain' => '', 'tls' => '', 'provider' => ''],
            ],
            'request' => $request + [
                'secure' => true, 'host' => 'desk.example.com', 'remote' => '203.0.113.9', 'xfp' => '', 'xff' => '', 'trusted' => false,
            ],
        ];
    }

    private function titles(array $issues): array
    {
        return array_map(static fn ($i) => $i['level'] . ': ' . $i['title'], $issues);
    }

    public function testHealthySetupHasNoIssues(): void
    {
        $this->assertSame([], SiteAddress::check($this->state()));
        // Explicit default port in the Host header is the same address.
        $this->assertSame([], SiteAddress::check($this->state([], ['host' => 'desk.example.com:443'])));
    }

    public function testDifferentAddressIsFlagged(): void
    {
        $issues = SiteAddress::check($this->state([], ['host' => '10.0.0.20']));
        $this->assertCount(1, $issues);
        $this->assertSame('warning', $issues[0]['level']);
        $this->assertSame('php spark tickethub:url https://10.0.0.20/', $issues[0]['fix']);

        $docker = SiteAddress::check($this->state(['platform' => ['docker' => true, 'domain' => 'desk.example.com', 'tls' => 'auto', 'provider' => '']], ['host' => 'helpdesk.corp:443']));
        $this->assertSame('TICKETHUB_DOMAIN=helpdesk.corp', $docker[0]['fix']);
    }

    public function testUntrustedProxyExplainsTheRedirectLoop(): void
    {
        $issues = SiteAddress::check($this->state([], ['secure' => false, 'xfp' => 'https', 'remote' => '10.0.0.5']));
        $this->assertSame('error', $issues[0]['level']);
        $this->assertStringContainsString('--trust-proxy=10.0.0.5', $issues[0]['fix']);
    }

    public function testPlainHttp(): void
    {
        $this->assertSame(
            ['info: Running as a local demo over plain http'],
            $this->titles(SiteAddress::check($this->state(['baseURL' => 'http://localhost/', 'forceHttps' => false], ['secure' => false, 'host' => 'localhost'])))
        );
        $lan = SiteAddress::check($this->state(['baseURL' => 'http://helpdesk.lan/', 'forceHttps' => false], ['secure' => false, 'host' => 'helpdesk.lan']));
        $this->assertSame('error', $lan[0]['level']);
    }

    public function testDevelopmentOnSharedAddressWarns(): void
    {
        $this->assertContains('warning: Development mode on a shared address', $this->titles(SiteAddress::check($this->state(['environment' => 'development']))));
        $this->assertSame([], SiteAddress::check($this->state(['environment' => 'development', 'baseURL' => 'http://localhost:8081/'], ['secure' => false, 'host' => 'localhost:8081'])));
    }

    public function testCertificateRules(): void
    {
        $now  = 1_800_000_000;
        $good = ['target' => 'x:443', 'names' => ['*.example.com'], 'validTo' => $now + 60 * 86400, 'trusted' => true];
        $this->assertSame([], SiteAddress::certIssues($good, 'desk.example.com', 'auto', $now));

        $this->assertSame(['error'], array_column(SiteAddress::certIssues(['validTo' => $now - 1] + $good, 'desk.example.com', 'auto', $now), 'level'));
        // Own certificates warn a month ahead; auto-renewing ones only when renewal is clearly failing.
        $soon = ['validTo' => $now + 20 * 86400] + $good;
        $this->assertCount(1, SiteAddress::certIssues($soon, 'desk.example.com', 'files', $now));
        $this->assertCount(0, SiteAddress::certIssues($soon, 'desk.example.com', 'auto', $now));
        $this->assertSame('error', SiteAddress::certIssues($good, 'other.org', 'auto', $now)[0]['level']);
        $this->assertFalse(SiteAddress::certCoversHost(['*.example.com'], 'a.b.example.com'));
        $this->assertSame('info', SiteAddress::certIssues(['trusted' => false] + $good, 'desk.example.com', 'internal', $now)[0]['level']);
        $this->assertSame('warning', SiteAddress::certIssues(['target' => 'caddy:443', 'error' => 'refused'], 'desk.example.com', 'auto', $now)[0]['level']);
    }
}
