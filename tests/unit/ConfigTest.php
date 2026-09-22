<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use Config\App;
use Config\Cookie;
use Config\Filters;
use Config\Security;
use Config\Session;

/**
 * Sanity checks on the production toggles: the hardening that switches on with
 * CI_ENVIRONMENT=production must stay off in every other environment, or the
 * dev server and this very test suite would redirect everything to https.
 *
 * @internal
 */
final class ConfigTest extends CIUnitTestCase
{
    public function testSuiteRunsInTheTestingEnvironment(): void
    {
        $this->assertSame('testing', ENVIRONMENT);
    }

    public function testHttpsIsNotForcedOutsideProduction(): void
    {
        $this->assertFalse((new App())->forceGlobalSecureRequests);
    }

    public function testCookiesAreNotMarkedSecureOutsideProduction(): void
    {
        $cookie = new Cookie();

        $this->assertFalse($cookie->secure);
        $this->assertTrue($cookie->httponly);
        $this->assertContains(strtolower((string) $cookie->samesite), ['lax', 'strict']);
    }

    public function testCsrfFailuresThrowRatherThanRedirectOutsideProduction(): void
    {
        $security = new Security();

        $this->assertFalse($security->redirect);
        $this->assertSame('session', $security->csrfProtection);
        $this->assertTrue($security->tokenRandomize);
        $this->assertTrue($security->regenerate);
    }

    public function testSessionDefaults(): void
    {
        $session = new Session();

        $this->assertSame(28800, $session->expiration, 'Sessions expire after 8 hours of inactivity');
        $this->assertGreaterThan(0, $session->timeToUpdate);
    }

    public function testAuthFiltersAreRegistered(): void
    {
        $filters = new Filters();

        foreach (['agentAuth', 'portalAuth', 'adminAuth', 'apiAuth', 'mustChangePw', 'sessionEpoch', 'csrf'] as $alias) {
            $this->assertArrayHasKey($alias, $filters->aliases, 'Filter alias ' . $alias . ' missing');
        }
        $this->assertContains('forcehttps', $filters->required['before']);
        $this->assertContains('secureheaders', $filters->globals['after']);
    }
}
