<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\FeatureTestCase;

/**
 * Sign-in, sign-out and the forced password change.
 *
 * @internal
 */
final class AuthTest extends FeatureTestCase
{
    private const ADMIN_EMAIL = 'maya.ortiz@tickethub.co';
    private const AGENT_EMAIL = 'devin.park@tickethub.co';
    private const DEMO_PASSWORD = 'password';

    public function testLoginPageRenders(): void
    {
        $this->get('login')->assertOK();
    }

    public function testValidCredentialsSignInAndRedirectToDashboard(): void
    {
        $result = $this->postForm('login', ['email' => self::ADMIN_EMAIL, 'password' => self::DEMO_PASSWORD]);

        $result->assertRedirectTo('/app/dashboard');
        $result->assertSessionHas('user_id', $this->userByEmail(self::ADMIN_EMAIL)['id']);
        $result->assertSessionHas('role', 'Administrator');
    }

    public function testRequesterLandsOnThePortal(): void
    {
        $result = $this->postForm('login', ['email' => 'jordan.whitfield@tickethub.co', 'password' => self::DEMO_PASSWORD]);

        $result->assertRedirectTo('/portal');
        $result->assertSessionHas('role', 'Requester');
    }

    public function testWrongPasswordIsRejected(): void
    {
        $result = $this->postForm('login', ['email' => self::AGENT_EMAIL, 'password' => 'definitely-not-it']);

        $result->assertRedirectTo('/login');
        $result->assertSessionMissing('user_id');
        $this->assertSame(1, $this->db->table('audit_log')->where('action', 'login.failed')->countAllResults());
    }

    public function testUnknownEmailIsRejectedTheSameWay(): void
    {
        $result = $this->postForm('login', ['email' => 'nobody@tickethub.co', 'password' => self::DEMO_PASSWORD]);

        $result->assertRedirectTo('/login');
        $result->assertSessionMissing('user_id');
    }

    public function testDeactivatedAccountCannotSignIn(): void
    {
        // Luis Ferreira is seeded with active = 0.
        $result = $this->postForm('login', ['email' => 'luis.ferreira@tickethub.co', 'password' => self::DEMO_PASSWORD]);

        $result->assertRedirectTo('/login');
        $result->assertSessionMissing('user_id');
    }

    public function testLogoutViaGetOnlyShowsTheConfirmationPage(): void
    {
        $session = $this->sessionFor($this->userByEmail(self::AGENT_EMAIL)['id']);

        $result = $this->withSession($session)->get('logout');

        $result->assertOK();
        $result->assertNotRedirect();
        $result->assertSee('logout'); // the auto-submitting POST form
    }

    public function testLogoutRequiresPost(): void
    {
        $session = $this->sessionFor($this->userByEmail(self::AGENT_EMAIL)['id']);

        $result = $this->postForm('logout', [], $session);

        $result->assertRedirectTo('/login');
    }

    public function testAnonymousLogoutRedirectsToLogin(): void
    {
        $this->get('logout')->assertRedirectTo('/login');
    }

    public function testMustChangePasswordRedirectsEveryPage(): void
    {
        $user = $this->userByEmail(self::ADMIN_EMAIL);
        $this->db->table('users')->where('id', $user['id'])->update(['must_change_password' => 1]);

        $session = $this->sessionFor((int) $user['id']);

        $this->withSession($session)->get('app/dashboard')->assertRedirectTo('/account/new-password');
        $this->withSession($session)->get('app/tickets')->assertRedirectTo('/account/new-password');
        $this->withSession($session)->get('portal')->assertRedirectTo('/account/new-password');

        // The change-password form itself stays reachable.
        $this->withSession($session)->get('account/new-password')->assertOK();
    }

    public function testFreshSeededLoginIsSentToChangePassword(): void
    {
        $user = $this->userByEmail(self::AGENT_EMAIL);
        $this->db->table('users')->where('id', $user['id'])->update(['must_change_password' => 1]);

        // Sign-in itself succeeds ...
        $this->postForm('login', ['email' => self::AGENT_EMAIL, 'password' => self::DEMO_PASSWORD])
            ->assertRedirectTo('/app/dashboard');

        // ... but the next page is the forced change.
        $this->withSession($this->sessionFor((int) $user['id']))->get('app/dashboard')
            ->assertRedirectTo('/account/new-password');
    }

    /**
     * Five attempts per minute per IP+email; the sixth is throttled even when
     * the password is right. Kept last so no other test shares its bucket.
     */
    public function testLockoutAfterSixAttempts(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->postForm('login', ['email' => self::AGENT_EMAIL, 'password' => 'wrong-' . $i])
                ->assertRedirectTo('/login');
        }

        $result = $this->postForm('login', ['email' => self::AGENT_EMAIL, 'password' => self::DEMO_PASSWORD]);

        $result->assertRedirectTo('/login');
        $result->assertSessionMissing('user_id');
        $this->assertSame(1, $this->db->table('audit_log')->where('action', 'login.throttled')->countAllResults());
    }
}
