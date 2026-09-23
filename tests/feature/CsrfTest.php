<?php

declare(strict_types=1);

namespace Tests\Feature;

use CodeIgniter\Security\Exceptions\SecurityException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The CSRF filter is global for every non-API route. The filter runs before any
 * controller (and before the database is touched), so this needs no test DB.
 *
 * Outside production the framework throws instead of redirecting back, so a
 * missing token surfaces as a SecurityException carrying HTTP 403.
 *
 * @internal
 */
final class CsrfTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    #[DataProvider('protectedPostRoutes')]
    public function testPostWithoutTokenIsForbidden(string $path, array $data): void
    {
        try {
            $result = $this->post($path, $data);
        } catch (SecurityException $e) {
            $this->assertSame(403, $e->getCode());

            return;
        }

        $result->assertStatus(403);
    }

    public static function protectedPostRoutes(): iterable
    {
        yield 'login'  => ['login', ['email' => 'maya.ortiz@tickethub.co', 'password' => 'password']];
        yield 'logout' => ['logout', []];
        yield 'forgot' => ['forgot', ['email' => 'maya.ortiz@tickethub.co']];
    }

    public function testCsrfIsGlobalAndOnlyNamedWebhooksAreExempt(): void
    {
        $filters = config('Filters');

        $this->assertArrayHasKey('csrf', $filters->globals['before']);
        // Every exemption here must be a request that cannot carry our token
        // because it originates elsewhere (the API's bearer auth, or an IdP
        // posting a signed SAML response) — never a route reachable from a
        // browser session. Widening this list is a deliberate, one-at-a-time
        // decision, which is why the test spells out the exact set rather than
        // just asserting csrf has *some* exceptions.
        $this->assertSame(['api/*', 'auth/saml/acs'], $filters->globals['before']['csrf']['except']);
    }

    /** The ACS exemption is narrow: it lets the request past the filter, nothing more. */
    public function testSamlAcsIsCsrfExemptButStillRequiresAValidResponse(): void
    {
        $result = $this->post('auth/saml/acs', []);
        // No SecurityException — the filter let it through; the controller
        // then rejects it on its own terms (no SAMLResponse / SSO not
        // configured) and redirects to the login page rather than 500ing.
        $result->assertRedirectTo('/login');
    }
}
