<?php

namespace App\Controllers;

use App\Libraries\Audit;
use App\Libraries\NotificationPrefs;
use App\Libraries\Settings;
use App\Libraries\Totp;

class AccountController extends BaseController
{
    /** Forced first-run password change (see App\Filters\MustChangePassword). */
    public function newPassword()
    {
        if (! $this->me) {
            return redirect()->to('/login');
        }
        if (! (int) $this->me['must_change_password']) {
            return redirect()->to($this->isAgentRole() ? '/app/dashboard' : '/portal');
        }

        return view('auth/new_password', ['me' => $this->me]);
    }

    public function password()
    {
        $current = (string) $this->request->getPost('current');
        $new     = (string) $this->request->getPost('password');
        $confirm = (string) $this->request->getPost('confirm');

        if (! password_verify($current, $this->me['password_hash'])) {
            $this->toast('Your current password is not right', 'warn');
        } elseif ($problem = self::passwordProblem($new, $this->me['email'])) {
            $this->toast($problem, 'warn');
        } elseif ($new !== $confirm) {
            $this->toast('The two new passwords do not match', 'warn');
        } else {
            $this->db->table('users')->where('id', $this->me['id'])->update([
                'password_hash' => password_hash($new, PASSWORD_DEFAULT),
                'must_change_password' => 0,
            ]);
            // A password change must evict remembered devices and stale sessions,
            // and any reset link still in someone's inbox.
            $this->invalidateOtherSessions();
            $this->bumpSessionEpoch();
            $this->db->table('password_resets')->where('email', $this->me['email'])->delete();
            Audit::log('password.changed', $this->me['email']);
            $this->toast('Password changed — other devices have been signed out');

            return redirect()->to($this->isAgentRole() ? '/app/dashboard' : '/portal');
        }

        return redirect()->back();
    }

    /* ---------- shared bits for the account pages ---------- */

    /**
     * Account pages render inside whichever shell the person normally uses:
     * the agent workspace for agent roles, the help portal for requesters.
     */
    private function accountShared(string $title): array
    {
        if ($this->isAgentRole()) {
            return $this->agentShared() + ['layout' => 'layouts/agent', 'title' => $title, 'nav' => 'account'];
        }
        $open = $this->db->table('tickets')->where('requester_id', $this->me['id'])->whereIn('status', TH_OPEN_STATES)->countAllResults();

        return ['me' => $this->me, 'portalOpenCount' => $open, 'users' => $this->users(), 'layout' => 'layouts/portal', 'title' => $title, 'pnav' => 'account'];
    }

    /** Is this account in scope of the admin's "2FA required" policy? */
    public static function mfaRequiredFor(array $user): bool
    {
        $policy = Settings::get('mfa_required_roles', 'none');
        if ($policy === 'all') {
            return true;
        }
        if ($policy === 'agents') {
            return in_array($user['role'] ?? '', ['Administrator', 'Supervisor', 'Agent'], true);
        }

        return false;
    }

    /* ---------- two-factor authentication ---------- */

    public function security()
    {
        $enabled  = ! empty($this->me['totp_enabled_at']);
        $pending  = $enabled ? null : (string) $this->session->get('totp_pending_secret');
        $recovery = json_decode((string) ($this->me['totp_recovery'] ?? '[]'), true) ?: [];

        return view('account/security', $this->accountShared('Security') + [
            'totpEnabled'   => $enabled,
            'totpEnabledAt' => $this->me['totp_enabled_at'] ?? null,
            'pendingSecret' => $pending,
            'pendingUri'    => $pending ? Totp::uri($pending, $this->me['email']) : null,
            'recoveryLeft'  => count($recovery),
            'newCodes'      => $this->session->getFlashdata('recovery_codes'),
            'mfaRequired'   => self::mfaRequiredFor($this->me),
            'localPassword' => $this->hasLocalPassword(),
            'authProvider'  => $this->me['auth_provider'] ?? null,
        ]);
    }

    /** SSO/LDAP accounts hold a random password nobody knows; confirm those with a TOTP code instead. */
    private function hasLocalPassword(): bool
    {
        return in_array($this->me['auth_provider'] ?? 'local', ['local', '', null], true);
    }

    /** Step 1: mint a secret and show the QR. Nothing is saved until a code verifies. */
    public function securityEnrol()
    {
        if (! empty($this->me['totp_enabled_at'])) {
            $this->toast('Two-factor authentication is already on', 'warn');

            return redirect()->to('/account/security');
        }
        $this->session->set('totp_pending_secret', Totp::generateSecret());

        return redirect()->to('/account/security');
    }

    /** Step 2: a correct code from the app proves the secret was captured; enable it. */
    public function securityEnable()
    {
        $secret = (string) $this->session->get('totp_pending_secret');
        if ($secret === '' || ! empty($this->me['totp_enabled_at'])) {
            return redirect()->to('/account/security');
        }
        if (service('throttler')->check('totp_enable_' . $this->me['id'], 10, MINUTE) === false) {
            $this->toast('Too many attempts — wait a minute', 'warn');

            return redirect()->to('/account/security');
        }
        if (! Totp::verify($secret, (string) $this->request->getPost('code'))) {
            $this->toast('That code did not match. Check the time on your phone and try the current code.', 'warn');

            return redirect()->to('/account/security');
        }
        $codes = Totp::generateRecoveryCodes();
        $this->db->table('users')->where('id', $this->me['id'])->update([
            'totp_secret'     => Totp::encryptSecret($secret),
            'totp_enabled_at' => date('Y-m-d H:i:s'),
            'totp_recovery'   => json_encode(Totp::hashRecovery($codes)),
        ]);
        $this->session->remove('totp_pending_secret');
        // A remembered device was trusted before 2FA existed; make it sign in
        // again (with a code) rather than keep skipping the second factor.
        $this->invalidateOtherSessions();
        $this->session->setFlashdata('recovery_codes', $codes);
        Audit::log('2fa.enabled', $this->me['email']);
        $this->toast('Two-factor authentication is on');

        return redirect()->to('/account/security');
    }

    public function securityRecovery()
    {
        if (empty($this->me['totp_enabled_at'])) {
            return redirect()->to('/account/security');
        }
        if (! $this->confirmIdentity()) {
            return redirect()->to('/account/security');
        }
        $codes = Totp::generateRecoveryCodes();
        $this->db->table('users')->where('id', $this->me['id'])->update(['totp_recovery' => json_encode(Totp::hashRecovery($codes))]);
        $this->session->setFlashdata('recovery_codes', $codes);
        Audit::log('2fa.recovery_regenerated', $this->me['email']);
        $this->toast('New recovery codes generated — the old ones no longer work');

        return redirect()->to('/account/security');
    }

    public function securityDisable()
    {
        if (empty($this->me['totp_enabled_at'])) {
            return redirect()->to('/account/security');
        }
        if (! $this->confirmIdentity()) {
            return redirect()->to('/account/security');
        }
        $this->db->table('users')->where('id', $this->me['id'])->update([
            'totp_secret' => null, 'totp_enabled_at' => null, 'totp_recovery' => null,
        ]);
        Audit::log('2fa.disabled', $this->me['email']);
        $this->toast(self::mfaRequiredFor($this->me)
            ? 'Two-factor authentication is off — your role requires it, so set it up again before continuing'
            : 'Two-factor authentication is off', self::mfaRequiredFor($this->me) ? 'warn' : 'ok');

        return redirect()->to('/account/security');
    }

    /**
     * Re-authenticate before a sensitive 2FA change: the current password for
     * local accounts, a current TOTP code for accounts signed in via SSO/LDAP.
     */
    private function confirmIdentity(): bool
    {
        if (service('throttler')->check('2fa_confirm_' . $this->me['id'], 5, MINUTE) === false) {
            $this->toast('Too many attempts — wait a minute', 'warn');

            return false;
        }
        if ($this->hasLocalPassword()) {
            if (password_verify((string) $this->request->getPost('password'), $this->me['password_hash'])) {
                return true;
            }
            $this->toast('Your password is not right', 'warn');

            return false;
        }
        if (Totp::verify(Totp::decryptSecret($this->me['totp_secret']), (string) $this->request->getPost('code'))) {
            return true;
        }
        $this->toast('Enter the current code from your authenticator app to confirm', 'warn');

        return false;
    }

    /* ---------- notification preferences ---------- */

    public function notifications()
    {
        return view('account/notifications', $this->accountShared('Notifications') + [
            'triggers' => NotificationPrefs::triggers(),
            'prefs'    => NotificationPrefs::load((int) $this->me['id']),
            'digest'   => (bool) ($this->me['notify_digest'] ?? 0),
            'digestOn' => true,
        ]);
    }

    public function saveNotifications()
    {
        $posted = (array) ($this->request->getPost('pref') ?? []);
        $prefs  = [];
        foreach (array_keys(NotificationPrefs::triggers()) as $trigger) {
            $prefs[$trigger] = [
                'email'  => ! empty($posted[$trigger]['email']),
                'in_app' => ! empty($posted[$trigger]['in_app']),
            ];
        }
        NotificationPrefs::save((int) $this->me['id'], $prefs, (bool) $this->request->getPost('digest'));
        Audit::log('notifications.prefs_saved', $this->me['email']);
        $this->toast('Notification preferences saved');

        return redirect()->to('/account/notifications');
    }

    /**
     * Sign out every other session for this account (see App\Filters\SessionEpoch)
     * while keeping this one: the new epoch is written into the current session.
     */
    private function bumpSessionEpoch(): void
    {
        $this->db->table('users')->where('id', $this->me['id'])->set('session_epoch', 'session_epoch + 1', false)->update();
        $row = $this->db->table('users')->select('session_epoch')->where('id', $this->me['id'])->get()->getRowArray();
        $this->session->set('session_epoch', (int) ($row['session_epoch'] ?? 0));
    }
}
