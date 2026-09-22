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

    public function testCsrfIsGlobalAndOnlyTheApiIsExempt(): void
    {
        $filters = config('Filters');

        $this->assertArrayHasKey('csrf', $filters->globals['before']);
        $this->assertSame(['api/*'], $filters->globals['before']['csrf']['except']);
    }
}
