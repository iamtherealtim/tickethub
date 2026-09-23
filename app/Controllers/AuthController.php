<?php

namespace App\Controllers;

use App\Libraries\Audit;
use App\Libraries\Ldap;
use App\Libraries\Mailer;
use App\Libraries\Oidc;
use App\Libraries\Saml;
use App\Libraries\Settings;
use App\Libraries\Totp;
use App\Libraries\Users;

class AuthController extends BaseController
{
    public function index()
    {
        if ($this->me) {
            return redirect()->to($this->isAgentRole() ? '/app/dashboard' : '/portal');
        }

        return redirect()->to('/login');
    }

    public function login()
    {
        if ($this->me) {
            return redirect()->to($this->isAgentRole() ? '/app/dashboard' : '/portal');
        }

        $error = $this->session->getFlashdata('error');
        if (! $error && $this->request->getGet('signed_out')) {
            $error = 'Your password was changed, so this device was signed out. Sign in again.';
        }

        return view('auth/login', [
            'error'        => $error,
            'azureEnabled' => Settings::get('azure_enabled') === '1' && Settings::get('azure_client_id') !== '',
            'oidcEnabled'  => Oidc::enabled(),
            'oidcLabel'    => Oidc::buttonLabel(),
            'ldapEnabled'  => Ldap::enabled(),
            'samlEnabled'  => Saml::enabled(),
            'samlLabel'    => Saml::buttonLabel(),
        ]);
    }

    /* ---------- Microsoft Entra ID (Azure AD) SSO ---------- */

    /**
     * A bcrypt hash of a random string, used so a login attempt against an
     * unknown email costs the same as one against a real account. Without it
     * the response time reveals which emails exist.
     */
    private const DUMMY_HASH = '$2y$10$u8XEVazbiqADTLzbsqloiu7ioInWHMpb45SzZbky2LoF/95rYTr5i';

    /**
     * The tenant segment of the Microsoft login URL: a directory GUID only.
     * The multi-tenant aliases ("common" / "organizations") are refused on
     * purpose — with them anyone can create their own Entra tenant, set a
     * user's mail attribute to one of ours and be issued a valid token for it
     * (the "nOAuth" account-takeover pattern). A GUID lets azureCallback() pin
     * every token to this one directory.
     */
    private function azureTenant(): ?string
    {
        $tenant = strtolower(trim(Settings::get('azure_tenant_id')));

        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $tenant) ? $tenant : null;
    }

    /**
     * Map the id_token `groups` claim onto a TicketHub role. Only active when an
     * admin has set at least one group id; then membership decides Administrator
     * > Agent > Requester. Returns null when the claim cannot be trusted (not
     * configured, or Entra omitted it because the user is in too many groups).
     */
    private function roleFromGroups(array $claims): ?string
    {
        $adminGroup = strtolower(trim(Settings::get('sso_admin_group')));
        $agentGroup = strtolower(trim(Settings::get('sso_agent_group')));
        if ($adminGroup === '' && $agentGroup === '') {
            return null;
        }
        if (! isset($claims['groups']) || ! is_array($claims['groups'])) {
            // "hasgroups"/"_claim_names" means overage: the list must be fetched from Graph, which we do not do.
            log_message('warning', 'Azure SSO: role mapping configured but the id_token carried no groups claim (overage or claim not enabled).');

            return null;
        }
        $groups = array_map('strtolower', array_map('strval', $claims['groups']));
        if ($adminGroup !== '' && in_array($adminGroup, $groups, true)) {
            return 'Administrator';
        }
        if ($agentGroup !== '' && in_array($agentGroup, $groups, true)) {
            return 'Agent';
        }

        return 'Requester';
    }

    public function azure()
    {
        $tenant = $this->azureTenant();
        if (Settings::get('azure_enabled') !== '1' || Settings::get('azure_client_id') === '' || $tenant === null) {
            $this->session->setFlashdata('error', 'Microsoft sign-in is not enabled or not fully configured.');

            return redirect()->to('/login');
        }
        $state = bin2hex(random_bytes(16));
        $nonce = bin2hex(random_bytes(16));
        $this->session->set(['azure_state' => $state, 'azure_nonce' => $nonce]);

        $params = http_build_query([
            'client_id'     => Settings::get('azure_client_id'),
            'response_type' => 'code',
            'redirect_uri'  => site_url('auth/azure/callback'),
            'response_mode' => 'query',
            'scope'         => 'openid profile email User.Read',
            'state'         => $state,
            'nonce'         => $nonce,
        ]);

        return redirect()->to('https://login.microsoftonline.com/' . $tenant . '/oauth2/v2.0/authorize?' . $params);
    }

    /** Decode a JWT payload without verifying (transport trust is established below). */
    private function jwtPayload(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return [];
        }
        $json = base64_decode(strtr($parts[1], '-_', '+/'), true);

        return $json ? (json_decode($json, true) ?: []) : [];
    }

    public function azureCallback()
    {
        $state = (string) $this->request->getGet('state');
        $code  = (string) $this->request->getGet('code');
        $saved = (string) $this->session->get('azure_state');
        $this->session->remove('azure_state');

        if ($err = $this->request->getGet('error')) {
            $desc = (string) ($this->request->getGet('error_description') ?? '');
            $this->session->setFlashdata('error', 'Microsoft sign-in failed: ' . $err . ($desc ? ' — ' . strtok($desc, "\n") : ''));

            return redirect()->to('/login');
        }
        if ($code === '' || $saved === '' || ! hash_equals($saved, $state)) {
            $this->session->setFlashdata('error', 'Microsoft sign-in failed: invalid state. Please try again.');

            return redirect()->to('/login');
        }

        $tenant = $this->azureTenant();
        if ($tenant === null) {
            $this->session->setFlashdata('error', 'Microsoft sign-in is not fully configured.');

            return redirect()->to('/login');
        }
        $client = service('curlrequest', ['timeout' => 10, 'http_errors' => false]);

        try {
            $res = $client->post('https://login.microsoftonline.com/' . $tenant . '/oauth2/v2.0/token', [
                'form_params' => [
                    'client_id'     => Settings::get('azure_client_id'),
                    'client_secret' => Settings::get('azure_client_secret'),
                    'grant_type'    => 'authorization_code',
                    'code'          => $code,
                    'redirect_uri'  => site_url('auth/azure/callback'),
                    'scope'         => 'openid profile email User.Read',
                ],
            ]);
            $token = json_decode((string) $res->getBody(), true) ?: [];
            if (empty($token['access_token'])) {
                // Log the error code only — descriptions disclose tenant/app details.
                log_message('error', 'Azure SSO token error: {err}', ['err' => (string) ($token['error'] ?? 'unknown')]);
                $this->session->setFlashdata('error', 'Microsoft sign-in failed. Contact an administrator if this continues.');

                return redirect()->to('/login');
            }

            /*
             * Pin the identity to the configured tenant. Without this, anyone can
             * spin up their own Entra tenant, set a user's mail attribute to one of
             * ours and be issued a valid token for it (the "nOAuth" pattern).
             * The id_token comes straight from Microsoft's token endpoint over TLS
             * in this back-channel exchange, so its claims are trustworthy here.
             */
            $claims = $this->jwtPayload((string) ($token['id_token'] ?? ''));
            $nonce  = (string) $this->session->get('azure_nonce');
            $this->session->remove('azure_nonce');

            if (! $claims
                || ! hash_equals(strtolower($tenant), strtolower((string) ($claims['tid'] ?? '')))
                || ! hash_equals(Settings::get('azure_client_id'), (string) ($claims['aud'] ?? ''))
                || ($nonce !== '' && ! hash_equals($nonce, (string) ($claims['nonce'] ?? '')))
                || (int) ($claims['exp'] ?? 0) < time()) {
                log_message('error', 'Azure SSO rejected: identity token failed tenant/audience/nonce validation.');
                $this->session->setFlashdata('error', 'Microsoft sign-in failed: that account is not part of this organisation.');

                return redirect()->to('/login');
            }
            $azureOid = (string) ($claims['oid'] ?? '');

            $meRes = $client->get('https://graph.microsoft.com/v1.0/me', [
                'headers' => ['Authorization' => 'Bearer ' . $token['access_token']],
            ]);
            $profile = json_decode((string) $meRes->getBody(), true) ?: [];
        } catch (\Throwable $e) {
            log_message('error', 'Azure SSO exception: {msg}', ['msg' => $e->getMessage()]);
            $this->session->setFlashdata('error', 'Microsoft sign-in failed: could not reach Microsoft. Check the network and try again.');

            return redirect()->to('/login');
        }

        $email = strtolower((string) ($profile['mail'] ?? $profile['userPrincipalName'] ?? ''));
        $name  = (string) ($profile['displayName'] ?? $email);
        if ($email === '') {
            $this->session->setFlashdata('error', 'Microsoft sign-in failed: your account has no email address we can match.');

            return redirect()->to('/login');
        }

        // Match on the immutable directory object id first; email can be renamed.
        $user = null;
        if ($azureOid !== '') {
            $user = $this->db->table('users')->where('azure_oid', $azureOid)->get()->getRowArray();
        }
        if (! $user) {
            $user = Users::byEmail($email);
            if ($user && ! empty($user['azure_oid']) && $user['azure_oid'] !== $azureOid) {
                // Already bound to a different directory object: a second
                // identity claiming the same mailbox never takes the account over.
                log_message('warning', 'Azure SSO: {email} is bound to another directory object; refusing to rebind.', ['email' => $email]);
                Audit::log('sso.rebind_refused', $email, (int) $user['id']);
                $this->session->setFlashdata('error', 'Microsoft sign-in failed: this account is linked to a different Microsoft identity. Contact an administrator.');

                return redirect()->to('/login');
            }
            // First successful sign-in binds this account to the directory object.
            if ($user && $azureOid !== '' && empty($user['azure_oid'])) {
                $this->db->table('users')->where('id', $user['id'])->update(['azure_oid' => $azureOid]);
            }
        }

        $mappedRole = $this->roleFromGroups($claims);

        if (! $user && Users::emailTaken($email)) {
            // A look-alike of an existing address (accents, case): never link, never duplicate.
            $this->session->setFlashdata('error', 'Microsoft sign-in failed: that email conflicts with an existing account. Contact an administrator.');

            return redirect()->to('/login');
        }
        if (! $user) {
            if (Settings::get('azure_autoprovision') !== '1') {
                $this->session->setFlashdata('error', 'Microsoft sign-in failed: no matching account. Ask an administrator to invite you.');

                return redirect()->to('/login');
            }
            $now  = date('Y-m-d H:i:s');
            $role = $mappedRole ?? 'Requester';
            $this->db->table('users')->insert([
                'name' => $name, 'email' => $email,
                'password_hash' => password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT),
                'role' => $role, 'title' => $role === 'Requester' ? 'Employee' : 'Support Analyst',
                'group_id' => $role === 'Requester' ? null : ((int) Settings::get('default_group_id', '1') ?: null),
                'color' => ['brand', 'ink', 'violet', 'signal'][random_int(0, 3)],
                'active' => 1, 'azure_oid' => $azureOid ?: null,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $user = $this->db->table('users')->where('id', $this->db->insertID())->get()->getRowArray();
            Audit::log('sso.provisioned', $email . ' as ' . $role, (int) $user['id']);
        } elseif ($mappedRole !== null && $mappedRole !== $user['role']) {
            // Directory group membership is the source of truth once mapping is on.
            // Supervisor has no directory equivalent, so it is only ever set by hand.
            $lastAdmin = $user['role'] === 'Administrator'
                && $this->db->table('users')->where('role', 'Administrator')->where('active', 1)->countAllResults() <= 1;
            if ($user['role'] === 'Supervisor' && $mappedRole === 'Agent') {
                // Keep the manual promotion.
            } elseif ($lastAdmin) {
                log_message('warning', 'Azure SSO: refusing to demote the last active Administrator ({email}) via group mapping.', ['email' => $email]);
            } else {
                $update = ['role' => $mappedRole, 'updated_at' => date('Y-m-d H:i:s')];
                if ($mappedRole !== 'Requester' && empty($user['group_id'])) {
                    $update['group_id'] = (int) Settings::get('default_group_id', '1') ?: null;
                }
                $this->db->table('users')->where('id', $user['id'])->update($update);
                Audit::log('sso.role_mapped', $email . ' ' . $user['role'] . ' → ' . $mappedRole, (int) $user['id']);
                $user['role'] = $mappedRole;
            }
        }

        if (! (int) $user['active']) {
            $this->session->setFlashdata('error', 'This account has been deactivated. Contact an administrator.');

            return redirect()->to('/login');
        }

        return $this->beginLogin($user, false, 'azure');
    }

    public function attempt()
    {
        $email    = Users::normalize((string) $this->request->getPost('email'));
        $password = (string) $this->request->getPost('password');

        // Throttle: 5 attempts per minute per IP+email.
        // Per-account bucket stops targeted guessing; per-IP bucket stops spraying
        // one common password across many accounts.
        $throttler = service('throttler');
        $ip        = $this->request->getIPAddress();
        $perAccount = $throttler->check('login_' . md5($ip . '|' . $email), 5, MINUTE);
        $perIp      = $throttler->check('loginip_' . md5($ip), 20, 15 * MINUTE);
        if ($perAccount === false || $perIp === false) {
            Audit::log('login.throttled', $email);
            $this->session->setFlashdata('error', 'Too many attempts. Wait a few minutes, then try again.');

            return redirect()->to('/login');
        }

        $user = Users::byEmail($email);

        // Directory accounts: when LDAP is on and this is not a local account,
        // the directory decides. Local users keep their local password.
        $provider     = 'local';
        $directoryOwns = Ldap::enabled() && $user && ($user['auth_provider'] ?? '') === 'ldap';
        if (Ldap::enabled() && (! $user || ($user['auth_provider'] ?? '') === 'ldap')) {
            try {
                $entry = Ldap::authenticate($email, $password);
            } catch (\Throwable $e) {
                log_message('error', 'LDAP login error: {msg}', ['msg' => $e->getMessage()]);
                Audit::log('login.failed', $email . ' (directory unreachable)');
                $this->session->setFlashdata('error', 'The directory could not be reached. Try again shortly or contact an administrator.');

                return redirect()->to('/login');
            }
            if ($entry) {
                $user = $this->provisionDirectoryUser($user, $entry, $email);
                if ($user === null) {
                    return redirect()->to('/login');
                }
                $provider = 'ldap';
            } elseif (! $user) {
                password_verify($password, self::DUMMY_HASH); // same cost as a local miss
            }
        }

        // Constant-time-ish: unknown emails still pay for one bcrypt verify.
        $ok = $provider === 'ldap' || ($user
            ? password_verify($password, $user['password_hash'])
            : (password_verify($password, self::DUMMY_HASH) && false));
        // A directory-owned account whose directory bind just failed does not
        // get a second chance on a local password: the directory decides, so
        // someone disabled in AD stays out even if a local hash exists.
        if ($directoryOwns && $provider !== 'ldap') {
            $ok = false;
        }

        // SSO-required accounts get the same answer whether or not the password
        // was right, so this message never confirms a guessed password.
        if ($user && $provider === 'local' && (int) $user['active'] && $this->localLoginBlocked($user)) {
            Audit::log('login.sso_required', $email);
            $this->session->setFlashdata('error', 'Your role requires single sign-on — use one of the buttons below instead of a password.');

            return redirect()->to('/login');
        }
        if (! $ok) {
            Audit::log('login.failed', $email);
            $this->session->setFlashdata('error', 'That email and password combination does not match.');

            return redirect()->to('/login');
        }
        if (! (int) $user['active']) {
            $this->session->setFlashdata('error', 'This account has been deactivated. Contact an administrator.');

            return redirect()->to('/login');
        }

        // Migrate the stored hash if the cost/algorithm default has moved on.
        if ($provider === 'local' && password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $this->db->table('users')->where('id', $user['id'])->update([
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ]);
        }

        return $this->beginLogin($user, (bool) $this->request->getPost('remember'), $provider);
    }

    /**
     * The `sso_required_roles` policy (none | agents | all): true when this
     * account must not be let in on a plain local password, even a correct
     * one. Administrators are always exempt — a misconfigured or unreachable
     * identity provider must never be able to lock every admin out of their
     * own instance with no way back in. LDAP accounts are unaffected by
     * this check entirely (attempt() only calls it for $provider === 'local').
     */
    private function localLoginBlocked(array $user): bool
    {
        return Users::localPasswordBlocked($user);
    }

    /* ---------- shared login completion + two-factor ---------- */

    /**
     * Credentials have been verified. Either finish the login, or — when the
     * account has TOTP enabled — park it in the session and ask for a code.
     */
    private function beginLogin(array $user, bool $remember, string $provider)
    {
        if (! empty($user['totp_enabled_at'])) {
            $this->session->regenerate();
            $this->session->set([
                'pending_2fa' => [
                    'user_id'  => (int) $user['id'],
                    'remember' => $remember,
                    'provider' => $provider,
                    'at'       => time(),
                ],
            ]);

            return redirect()->to('/login/2fa');
        }

        return $this->finishLogin($user, $remember, $provider);
    }

    /** The one place a session is minted: session_epoch, remember cookie, audit, landing page. */
    private function finishLogin(array $user, bool $remember, string $provider)
    {
        $this->session->regenerate();
        $this->session->remove('pending_2fa');
        $this->session->set([
            'user_id'       => (int) $user['id'],
            'role'          => $user['role'],
            'name'          => $user['name'],
            'session_epoch' => (int) ($user['session_epoch'] ?? 0),
        ]);
        if ($remember) {
            $this->issueRememberCookie((int) $user['id']);
        }
        if (($user['auth_provider'] ?? null) !== $provider) {
            try {
                $this->db->table('users')->where('id', $user['id'])->update(['auth_provider' => $provider]);
            } catch (\Throwable $e) {
                // column not migrated yet
            }
        }
        Audit::log('login.success', $user['email'] . ' via ' . $provider, (int) $user['id']);

        $agent = in_array($user['role'], ['Administrator', 'Supervisor', 'Agent'], true);
        $this->toast('Welcome back, ' . explode(' ', $user['name'])[0]);

        // withCookies(): a RedirectResponse does not inherit cookies set on the
        // controller response, so the remember-me cookie would otherwise be lost.
        return redirect()->to($agent ? '/app/dashboard' : '/portal')->withCookies();
    }

    /** The user parked by beginLogin(), or null when there is none / it went stale. */
    private function pendingTwoFactorUser(): ?array
    {
        $p = $this->session->get('pending_2fa');
        if (! is_array($p) || empty($p['user_id']) || (int) ($p['at'] ?? 0) < time() - 10 * MINUTE) {
            $this->session->remove('pending_2fa');

            return null;
        }
        $user = $this->db->table('users')->where('id', (int) $p['user_id'])->get()->getRowArray();
        if (! $user || ! (int) $user['active'] || empty($user['totp_enabled_at'])) {
            $this->session->remove('pending_2fa');

            return null;
        }

        return $user;
    }

    public function twoFactor()
    {
        if ($this->me) {
            return redirect()->to($this->isAgentRole() ? '/app/dashboard' : '/portal');
        }
        if (! $this->pendingTwoFactorUser()) {
            return redirect()->to('/login');
        }

        return view('auth/two_factor', ['error' => $this->session->getFlashdata('error')]);
    }

    public function twoFactorVerify()
    {
        $user = $this->pendingTwoFactorUser();
        if (! $user) {
            $this->session->setFlashdata('error', 'Your sign-in expired. Start again.');

            return redirect()->to('/login');
        }
        $throttler = service('throttler');
        // Two buckets: per IP+account (quick feedback) and per account alone,
        // so spreading guesses over many IPs does not multiply the budget.
        // 20 per 15 minutes across all IPs keeps a 6-digit guess (3 valid
        // codes at a time) to well under 1% per day.
        if ($throttler->check('twofa_' . md5($this->request->getIPAddress() . '|' . $user['id']), 5, MINUTE) === false
            || $throttler->check('twofa_user_' . $user['id'], 20, 15 * MINUTE) === false) {
            Audit::log('login.2fa_throttled', $user['email'], (int) $user['id']);
            $this->session->setFlashdata('error', 'Too many attempts. Wait a few minutes, then try again.');

            return redirect()->to('/login/2fa');
        }

        $code = trim((string) $this->request->getPost('code'));
        $ok   = false;
        if (preg_match('/^\d{6}$/', preg_replace('/\s+/', '', $code) ?? '')) {
            $step = Totp::verifyStep(Totp::decryptSecret($user['totp_secret']), $code);
            $last = isset($user['totp_last_step']) ? (int) $user['totp_last_step'] : null;
            if ($step !== null && ($last === null || $step > $last)) {
                // Conditional write: two requests racing with the same code
                // cannot both succeed.
                $q = $this->db->table('users')->where('id', $user['id']);
                $last === null ? $q->where('totp_last_step IS NULL', null, false) : $q->where('totp_last_step', $last);
                if ($this->db->fieldExists('totp_last_step', 'users')) {
                    $q->update(['totp_last_step' => $step]);
                    $ok = $this->db->affectedRows() === 1;
                } else {
                    $ok = true;
                }
            }
        } else {
            $stored = (string) ($user['totp_recovery'] ?? '[]');
            $left   = Totp::consumeRecovery(json_decode($stored, true) ?: [], $code);
            if ($left !== null) {
                // Only succeeds if nobody consumed a code between our read and
                // this write — a single recovery code can never be spent twice.
                $this->db->table('users')->where('id', $user['id'])->where('totp_recovery', $stored)
                    ->update(['totp_recovery' => json_encode($left)]);
                $ok = $this->db->affectedRows() === 1;
            }
            if ($ok) {
                Audit::log('login.2fa_recovery_used', $user['email'] . ' (' . count($left) . ' left)', (int) $user['id']);
                if (count($left) <= 2) {
                    $this->session->setFlashdata('toast', ['msg' => 'Only ' . count($left) . ' recovery codes left — generate new ones under Security', 'kind' => 'warn']);
                }
            }
        }
        if (! $ok) {
            Audit::log('login.2fa_failed', $user['email'], (int) $user['id']);
            $this->session->setFlashdata('error', 'That code is not right. Codes change every 30 seconds — try the current one.');

            return redirect()->to('/login/2fa');
        }
        $p = (array) $this->session->get('pending_2fa');

        return $this->finishLogin($user, ! empty($p['remember']), (string) ($p['provider'] ?? 'local'));
    }

    /* ---------- LDAP / Active Directory ---------- */

    /**
     * Create or refresh the local account for a directory user who has just
     * authenticated. Returns the (fresh) user row, or null with a flash error
     * set when the account cannot be used.
     */
    private function provisionDirectoryUser(?array $user, array $entry, string $login): ?array
    {
        $email = $entry['email'] !== '' ? $entry['email'] : strtolower($login);
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->session->setFlashdata('error', 'Directory sign-in failed: your directory account has no email address we can use.');

            return null;
        }
        if (! $user) {
            // The login may have been a sAMAccountName; match the mail attribute too.
            $user = Users::byEmail($email);
            if (! $user && Users::emailTaken($email)) {
                $this->session->setFlashdata('error', 'Directory sign-in failed: that email conflicts with an existing account. Contact an administrator.');

                return null;
            }
            // A local Administrator is the break-glass account: a directory entry
            // that happens to carry the same mail attribute never converts it.
            if ($user && ($user['auth_provider'] ?? 'local') !== 'ldap' && $user['role'] === 'Administrator') {
                Audit::log('ldap.admin_link_refused', $email, (int) $user['id']);
                $this->session->setFlashdata('error', 'That email belongs to a local administrator account. Sign in with its email and password instead.');

                return null;
            }
        }
        $mappedRole = Ldap::roleFromGroups($entry['groups']);
        $now        = date('Y-m-d H:i:s');
        $profile    = array_filter([
            'name'  => mb_substr($entry['name'], 0, 100),
            'title' => mb_substr($entry['title'], 0, 100),
            'phone' => mb_substr($entry['phone'], 0, 40),
            'dept'  => mb_substr($entry['dept'], 0, 60),
        ], static fn ($v) => $v !== '');

        if (! $user) {
            if (Settings::get('ldap_create_users', '1') !== '1') {
                $this->session->setFlashdata('error', 'Directory sign-in failed: no matching TicketHub account. Ask an administrator to invite you.');

                return null;
            }
            $role = $mappedRole ?? 'Requester';
            $this->db->table('users')->insert($profile + [
                'name' => $email, 'email' => $email,
                'password_hash' => password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT),
                'role' => $role, 'title' => $role === 'Requester' ? 'Employee' : 'Support Analyst',
                'group_id' => $role === 'Requester' ? null : ((int) Settings::get('default_group_id', '1') ?: null),
                'color' => ['brand', 'ink', 'violet', 'signal'][random_int(0, 3)],
                'active' => 1, 'auth_provider' => 'ldap',
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $user = $this->db->table('users')->where('id', $this->db->insertID())->get()->getRowArray();
            Audit::log('ldap.provisioned', $email . ' as ' . $role, (int) $user['id']);

            return $user;
        }

        $update = $profile + ['auth_provider' => 'ldap', 'updated_at' => $now];
        $this->applyMappedRole($user, $mappedRole, $update, 'ldap');
        $this->db->table('users')->where('id', $user['id'])->update($update);

        return $this->db->table('users')->where('id', $user['id'])->get()->getRowArray();
    }

    /**
     * Fold a directory-mapped role into $update, with the same safety rules as
     * the Entra mapping: Supervisors keep their manual promotion when the
     * directory says Agent, and the last active Administrator is never demoted.
     */
    private function applyMappedRole(array $user, ?string $mappedRole, array &$update, string $source): void
    {
        if ($mappedRole === null || $mappedRole === $user['role']) {
            return;
        }
        $lastAdmin = $user['role'] === 'Administrator'
            && $this->db->table('users')->where('role', 'Administrator')->where('active', 1)->countAllResults() <= 1;
        if ($user['role'] === 'Supervisor' && $mappedRole === 'Agent') {
            return;
        }
        if ($lastAdmin) {
            log_message('warning', '{src}: refusing to demote the last active Administrator ({email}) via group mapping.', ['src' => $source, 'email' => $user['email']]);

            return;
        }
        $update['role'] = $mappedRole;
        if ($mappedRole !== 'Requester' && empty($user['group_id'])) {
            $update['group_id'] = (int) Settings::get('default_group_id', '1') ?: null;
        }
        Audit::log($source . '.role_mapped', $user['email'] . ' ' . $user['role'] . ' → ' . $mappedRole, (int) $user['id']);
    }

    /* ---------- generic OpenID Connect (Google, Okta, Keycloak, …) ---------- */

    public function oidc()
    {
        if (! Oidc::enabled()) {
            $this->session->setFlashdata('error', 'Single sign-on is not enabled or not fully configured.');

            return redirect()->to('/login');
        }

        try {
            return redirect()->to(Oidc::authorizationUrl(site_url('auth/oidc/callback')));
        } catch (\Throwable $e) {
            log_message('error', 'OIDC start failed: {msg}', ['msg' => $e->getMessage()]);
            $this->session->setFlashdata('error', 'Single sign-on failed: ' . $e->getMessage());

            return redirect()->to('/login');
        }
    }

    public function oidcCallback()
    {
        $label = Oidc::buttonLabel();
        if ($err = $this->request->getGet('error')) {
            $desc = (string) ($this->request->getGet('error_description') ?? '');
            $this->session->setFlashdata('error', $label . ' failed: ' . $err . ($desc ? ' — ' . strtok($desc, "\n") : ''));

            return redirect()->to('/login');
        }
        if (! Oidc::enabled()) {
            return redirect()->to('/login');
        }

        try {
            $claims = Oidc::handleCallback(
                (string) $this->request->getGet('code'),
                (string) $this->request->getGet('state'),
                site_url('auth/oidc/callback')
            );
        } catch (\RuntimeException $e) {
            log_message('error', 'OIDC callback rejected: {msg}', ['msg' => $e->getMessage()]);
            $this->session->setFlashdata('error', $label . ' failed: ' . $e->getMessage());

            return redirect()->to('/login');
        } catch (\Throwable $e) {
            log_message('error', 'OIDC exception: {msg}', ['msg' => $e->getMessage()]);
            $this->session->setFlashdata('error', $label . ' failed: could not reach the identity provider.');

            return redirect()->to('/login');
        }

        $email = strtolower(trim((string) ($claims['email'] ?? '')));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->session->setFlashdata('error', $label . ' failed: the provider returned no email address (add the "email" scope).');

            return redirect()->to('/login');
        }
        if (array_key_exists('email_verified', $claims) && ! filter_var($claims['email_verified'], FILTER_VALIDATE_BOOLEAN)) {
            $this->session->setFlashdata('error', $label . ' failed: your email address is not verified with the provider.');

            return redirect()->to('/login');
        }
        $name = trim((string) ($claims['name'] ?? trim(($claims['given_name'] ?? '') . ' ' . ($claims['family_name'] ?? '')))) ?: $email;

        return $this->ssoSignIn(
            'oidc',
            'oidc|' . rtrim(Settings::get('oidc_issuer'), '/') . '|' . (string) $claims['sub'],
            $email,
            $name,
            Oidc::roleFromClaims($claims),
            $label,
            // Linking to an account that already exists needs a positive
            // verification from the provider, not just the absence of a "no".
            filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN)
        );
    }

    /**
     * Shared tail of the OIDC and SAML callbacks: find or create the account
     * for a verified external identity, then hand over to beginLogin().
     *
     * - The provider's own stable id ($subject) is bound on first sign-in;
     *   a later sign-in with the same email but a different subject is
     *   refused, so an IdP account that merely claims an address cannot take
     *   over whoever already owns it.
     * - Emails are matched exactly (Users::byEmail), never through the
     *   accent/case-folding column collation; a look-alike is a conflict.
     * - An optional "<provider>_allowed_domains" list restricts who may sign
     *   in at all (essential when the issuer is a public one like Google).
     * - $canLinkExisting=false (an unverified email) may create a new account
     *   but never attaches to an existing one.
     */
    private function ssoSignIn(string $provider, string $subject, string $email, string $name, ?string $mappedRole, string $label, bool $canLinkExisting = true)
    {
        $email   = Users::normalize($email);
        $allowed = array_values(array_filter(array_map(
            static fn ($d) => strtolower(ltrim(trim($d), '@')),
            explode(',', Settings::get($provider . '_allowed_domains'))
        )));
        $domain = strtolower((string) substr((string) strrchr($email, '@'), 1));
        if ($allowed && ! in_array($domain, $allowed, true)) {
            Audit::log($provider . '.domain_refused', $email);
            $this->session->setFlashdata('error', $label . ' failed: accounts from ' . $domain . ' cannot sign in here.');

            return redirect()->to('/login');
        }

        $user = Users::byEmail($email);
        if (! $user && Users::emailTaken($email)) {
            $this->session->setFlashdata('error', $label . ' failed: that email conflicts with an existing account. Contact an administrator.');

            return redirect()->to('/login');
        }
        $hasColumn = $this->db->fieldExists('sso_subject', 'users');

        if ($user) {
            $bound = $hasColumn ? (string) ($user['sso_subject'] ?? '') : '';
            if ($bound !== '' && ! hash_equals($bound, $subject)) {
                log_message('warning', '{p}: {email} is bound to another identity; refusing sign-in.', ['p' => $provider, 'email' => $email]);
                Audit::log($provider . '.rebind_refused', $email, (int) $user['id']);
                $this->session->setFlashdata('error', $label . ' failed: this account is linked to a different identity. Contact an administrator.');

                return redirect()->to('/login');
            }
            if ($bound === '' && ! $canLinkExisting) {
                $this->session->setFlashdata('error', $label . ' failed: the provider did not confirm your email address, so it cannot be linked to an existing account.');

                return redirect()->to('/login');
            }
            $update = [];
            if ($hasColumn && $bound === '') {
                $update['sso_subject'] = $subject;
                Audit::log($provider . '.linked', $email, (int) $user['id']);
            }
            $this->applyMappedRole($user, $mappedRole, $update, $provider);
            if ($update) {
                $update['updated_at'] = date('Y-m-d H:i:s');
                $this->db->table('users')->where('id', $user['id'])->update($update);
                $user = array_merge($user, $update);
            }
        } else {
            $now  = date('Y-m-d H:i:s');
            $role = $mappedRole ?? 'Requester';
            $row  = [
                'name' => mb_substr($name, 0, 100), 'email' => $email,
                'password_hash' => password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT),
                'role' => $role, 'title' => $role === 'Requester' ? 'Employee' : 'Support Analyst',
                'group_id' => $role === 'Requester' ? null : ((int) Settings::get('default_group_id', '1') ?: null),
                'color' => ['brand', 'ink', 'violet', 'signal'][random_int(0, 3)],
                'active' => 1, 'auth_provider' => $provider,
                'created_at' => $now, 'updated_at' => $now,
            ];
            if ($hasColumn) {
                $row['sso_subject'] = $subject;
            }
            $this->db->table('users')->insert($row);
            $user = $this->db->table('users')->where('id', $this->db->insertID())->get()->getRowArray();
            Audit::log($provider . '.provisioned', $email . ' as ' . $role, (int) $user['id']);
        }

        if (! (int) $user['active']) {
            $this->session->setFlashdata('error', 'This account has been deactivated. Contact an administrator.');

            return redirect()->to('/login');
        }

        return $this->beginLogin($user, false, $provider);
    }

    /* ---------- SAML 2.0 ---------- */

    public function saml()
    {
        if (! Saml::enabled()) {
            $this->session->setFlashdata('error', 'Single sign-on is not enabled or not fully configured.');

            return redirect()->to('/login');
        }

        try {
            $start  = Saml::beginLogin();
            $secure = $this->request->isSecure();
            // Ties the response to this browser (see Saml::processAcs). The IdP
            // answers with a cross-site POST, which only carries a cookie marked
            // SameSite=None — and browsers only accept that on a Secure cookie,
            // so the binding applies on HTTPS (always, in production).
            $this->response->setCookie([
                'name' => 'th_saml', 'value' => $start['nonce'], 'expire' => 900,
                'path' => '/', 'httponly' => true, 'secure' => $secure,
                'samesite' => $secure ? 'None' : 'Lax',
            ]);

            return redirect()->to($start['url'])->withCookies();
        } catch (\Throwable $e) {
            log_message('error', 'SAML start failed: {msg}', ['msg' => $e->getMessage()]);
            $this->session->setFlashdata('error', 'Single sign-on failed: could not build the sign-in request.');

            return redirect()->to('/login');
        }
    }

    /** Assertion Consumer Service — the IdP POSTs the signed response here. */
    public function samlAcs()
    {
        $label = Saml::buttonLabel();
        if (! Saml::enabled()) {
            return redirect()->to('/login');
        }

        try {
            $result = Saml::processAcs((string) ($this->request->getCookie('th_saml') ?? ''), $this->request->isSecure());
            $this->response->deleteCookie('th_saml');
        } catch (\RuntimeException $e) {
            log_message('error', 'SAML sign-in rejected: {msg}', ['msg' => $e->getMessage()]);
            $this->session->setFlashdata('error', $label . ' failed: ' . $e->getMessage());

            return redirect()->to('/login');
        } catch (\Throwable $e) {
            log_message('error', 'SAML exception: {msg}', ['msg' => $e->getMessage()]);
            $this->session->setFlashdata('error', $label . ' failed: could not reach the identity provider.');

            return redirect()->to('/login');
        }

        $email = $result['email'];

        return $this->ssoSignIn(
            'saml',
            'saml|' . Settings::get('saml_idp_entity_id') . '|' . $result['nameId'],
            $email,
            $result['name'] !== '' ? $result['name'] : $email,
            Saml::roleFromAttributes($result['attributes']),
            $label
        );
    }

    /** SP metadata for the IdP to import — public, no session, no secrets. */
    public function samlMetadata()
    {
        if (! Saml::available()) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        try {
            $xml = Saml::metadataXml();
        } catch (\Throwable $e) {
            log_message('error', 'SAML metadata generation failed: {msg}', ['msg' => $e->getMessage()]);

            return $this->response->setStatusCode(500)->setBody('Could not generate metadata.');
        }

        return $this->response->setContentType('text/xml')->setBody($xml);
    }

    /**
     * GET /logout: a link cannot carry a CSRF token, so it renders a tiny page
     * that immediately POSTs the real logout. Stops third-party pages from
     * signing people out with an <img src="/logout">.
     */
    public function logoutConfirm()
    {
        if (! $this->me) {
            return redirect()->to('/login');
        }

        return view('auth/logout');
    }

    public function logout()
    {
        $this->clearRememberCookie();
        $this->session->destroy();

        return redirect()->to('/login')->withCookies();
    }

    /* ---------- forgot / reset password ---------- */

    public function forgot()
    {
        return view('auth/forgot', [
            'sent'  => $this->session->getFlashdata('sent'),
            'error' => $this->session->getFlashdata('error'),
        ]);
    }

    public function sendReset()
    {
        $email = Users::normalize((string) $this->request->getPost('email'));

        $throttler = service('throttler');
        if ($throttler->check('reset_' . md5($this->request->getIPAddress()), 5, MINUTE) === false) {
            $this->session->setFlashdata('error', 'Too many requests. Wait a minute, then try again.');

            return redirect()->to('/forgot');
        }
        if (! Mailer::configured()) {
            $this->session->setFlashdata('error', 'Email sending is not configured — ask an administrator to reset your password.');

            return redirect()->to('/forgot');
        }

        // Exact match only (see Users::byEmail): the loose column collation
        // would let "maya@tickethüb.co" find maya@tickethub.co's account.
        $user = Users::byEmail($email, true);
        // Accounts that sign in through a directory or identity provider never
        // get a local password by email — that would be a way around the
        // provider (a user disabled in AD, or the IdP's own MFA).
        if ($user && in_array($user['auth_provider'] ?? 'local', ['ldap', 'azure', 'oidc', 'saml'], true)) {
            Audit::log('password.reset_refused_sso', $user['email'], (int) $user['id']);
            $user = null;
        }
        if ($user) {
            $to    = (string) $user['email']; // the stored address, never the typed one
            $token = bin2hex(random_bytes(24));
            $this->db->table('password_resets')->where('email', $to)->delete();
            $this->db->table('password_resets')->insert([
                'email'      => $to,
                'token_hash' => hash('sha256', $token),
                'expires_at' => date('Y-m-d H:i:s', time() + 3600),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            Mailer::send($to, 'Reset your TicketHub password',
                'Hi ' . explode(' ', $user['name'])[0] . ",\n\nSomeone (hopefully you) asked to reset your TicketHub password."
                . " The link below works once and expires in an hour:\n\n" . site_url('reset/' . $token)
                . "\n\nIf this was not you, ignore this message — nothing changes.\n\n— TicketHub");
            Audit::log('password.reset_requested', $email, (int) $user['id']);
        }
        // Same response whether or not the account exists.
        $this->session->setFlashdata('sent', 'If an account exists for ' . $email . ', a reset link is on its way. It expires in an hour.');

        return redirect()->to('/forgot');
    }

    private function resetRow(string $token): ?array
    {
        $row = $this->db->table('password_resets')->where('token_hash', hash('sha256', $token))->get()->getRowArray();
        if (! $row || strtotime($row['expires_at']) < time()) {
            return null;
        }

        return $row;
    }

    public function reset(string $token)
    {
        if (! $this->resetRow($token)) {
            $this->session->setFlashdata('error', 'That reset link is invalid or has expired. Request a new one.');

            return redirect()->to('/forgot');
        }

        return view('auth/reset', ['token' => $token, 'error' => $this->session->getFlashdata('error')]);
    }

    public function doReset(string $token)
    {
        $row = $this->resetRow($token);
        if (! $row) {
            $this->session->setFlashdata('error', 'That reset link is invalid or has expired. Request a new one.');

            return redirect()->to('/forgot');
        }
        $pass    = (string) $this->request->getPost('password');
        $confirm = (string) $this->request->getPost('confirm');
        if ($problem = self::passwordProblem($pass, $row['email'])) {
            $this->session->setFlashdata('error', $problem);

            return redirect()->to('/reset/' . $token);
        }
        if ($pass !== $confirm) {
            $this->session->setFlashdata('error', 'The two passwords do not match.');

            return redirect()->to('/reset/' . $token);
        }
        $user = Users::byEmail((string) $row['email'], true);
        if (! $user) {
            $this->db->table('password_resets')->where('token_hash', $row['token_hash'])->delete();
            $this->session->setFlashdata('error', 'That reset link is invalid or has expired. Request a new one.');

            return redirect()->to('/forgot');
        }
        // By id: exactly the account the link was issued for, nothing the
        // collation happens to consider equal.
        $this->db->table('users')->where('id', $user['id'])->update([
            'password_hash' => password_hash($pass, PASSWORD_DEFAULT),
            'remember_selector' => null, 'remember_validator' => null, 'remember_expires' => null,
            'must_change_password' => 0,
        ]);
        // Every existing session for this account is now stale (see App\Filters\SessionEpoch).
        $this->db->table('users')->where('id', $user['id'])->set('session_epoch', 'session_epoch + 1', false)->update();
        $this->db->table('password_resets')->where('email', $user['email'])->delete();
        Audit::log('password.reset_completed', $row['email']);
        $this->session->setFlashdata('error', '');
        $this->toast('Password changed — sign in with the new one');

        return redirect()->to('/login');
    }
}
