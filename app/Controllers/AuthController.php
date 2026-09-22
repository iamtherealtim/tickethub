<?php

namespace App\Controllers;

use App\Libraries\Audit;
use App\Libraries\Mailer;
use App\Libraries\Settings;

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

        return view('auth/login', [
            'error'        => $this->session->getFlashdata('error'),
            'azureEnabled' => Settings::get('azure_enabled') === '1' && Settings::get('azure_client_id') !== '',
        ]);
    }

    /* ---------- Microsoft Entra ID (Azure AD) SSO ---------- */

    /** A concrete tenant GUID is mandatory — see azureCallback() for why. */
    private function azureTenant(): ?string
    {
        $tenant = trim(Settings::get('azure_tenant_id'));

        return preg_match('/^[0-9a-f-]{36}$/i', $tenant) ? $tenant : null;
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
            $user = $this->db->table('users')->where('email', $email)->get()->getRowArray();
            // First successful sign-in binds this account to the directory object.
            if ($user && $azureOid !== '') {
                $this->db->table('users')->where('id', $user['id'])->update(['azure_oid' => $azureOid]);
            }
        }

        if (! $user) {
            if (Settings::get('azure_autoprovision') !== '1') {
                $this->session->setFlashdata('error', 'Microsoft sign-in failed: no matching account. Ask an administrator to invite you.');

                return redirect()->to('/login');
            }
            $now = date('Y-m-d H:i:s');
            $this->db->table('users')->insert([
                'name' => $name, 'email' => $email,
                'password_hash' => password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT),
                'role' => 'Requester', 'title' => 'Employee',
                'color' => ['brand', 'ink', 'violet', 'signal'][random_int(0, 3)],
                'active' => 1, 'azure_oid' => $azureOid ?: null,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $user = $this->db->table('users')->where('email', $email)->get()->getRowArray();
        }

        if (! (int) $user['active']) {
            $this->session->setFlashdata('error', 'This account has been deactivated. Contact an administrator.');

            return redirect()->to('/login');
        }

        $this->session->regenerate();
        $this->session->set([
            'user_id' => (int) $user['id'],
            'role'    => $user['role'],
            'name'    => $user['name'],
        ]);
        $agent = in_array($user['role'], ['Administrator', 'Supervisor', 'Agent'], true);
        $this->toast('Welcome back, ' . explode(' ', $user['name'])[0]);

        return redirect()->to($agent ? '/app/dashboard' : '/portal');
    }

    public function attempt()
    {
        $email    = trim((string) $this->request->getPost('email'));
        $password = (string) $this->request->getPost('password');

        // Throttle: 5 attempts per minute per IP+email.
        // Per-account bucket stops targeted guessing; per-IP bucket stops spraying
        // one common password across many accounts.
        $throttler = service('throttler');
        $ip        = $this->request->getIPAddress();
        $perAccount = $throttler->check('login_' . md5($ip . '|' . strtolower($email)), 5, MINUTE);
        $perIp      = $throttler->check('loginip_' . md5($ip), 20, 15 * MINUTE);
        if ($perAccount === false || $perIp === false) {
            Audit::log('login.throttled', $email);
            $this->session->setFlashdata('error', 'Too many attempts. Wait a few minutes, then try again.');

            return redirect()->to('/login');
        }

        $user = $this->db->table('users')->where('email', $email)->get()->getRowArray();

        if (! $user || ! password_verify($password, $user['password_hash'])) {
            Audit::log('login.failed', $email);
            $this->session->setFlashdata('error', 'That email and password combination does not match.');

            return redirect()->to('/login');
        }
        if (! (int) $user['active']) {
            $this->session->setFlashdata('error', 'This account has been deactivated. Contact an administrator.');

            return redirect()->to('/login');
        }

        // Migrate the stored hash if the cost/algorithm default has moved on.
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $this->db->table('users')->where('id', $user['id'])->update([
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ]);
        }

        $this->session->regenerate();
        $this->session->set([
            'user_id' => (int) $user['id'],
            'role'    => $user['role'],
            'name'    => $user['name'],
        ]);
        if ($this->request->getPost('remember')) {
            $this->issueRememberCookie((int) $user['id']);
        }
        Audit::log('login.success', $email, (int) $user['id']);

        $agent = in_array($user['role'], ['Administrator', 'Supervisor', 'Agent'], true);
        $this->toast('Welcome back, ' . explode(' ', $user['name'])[0]);

        return redirect()->to($agent ? '/app/dashboard' : '/portal');
    }

    public function logout()
    {
        $this->clearRememberCookie();
        $this->session->destroy();

        return redirect()->to('/login');
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
        $email = strtolower(trim((string) $this->request->getPost('email')));

        $throttler = service('throttler');
        if ($throttler->check('reset_' . md5($this->request->getIPAddress()), 5, MINUTE) === false) {
            $this->session->setFlashdata('error', 'Too many requests. Wait a minute, then try again.');

            return redirect()->to('/forgot');
        }
        if (! Mailer::configured()) {
            $this->session->setFlashdata('error', 'Email sending is not configured — ask an administrator to reset your password.');

            return redirect()->to('/forgot');
        }

        $user = $this->db->table('users')->where('email', $email)->where('active', 1)->get()->getRowArray();
        if ($user) {
            $token = bin2hex(random_bytes(24));
            $this->db->table('password_resets')->where('email', $email)->delete();
            $this->db->table('password_resets')->insert([
                'email'      => $email,
                'token_hash' => hash('sha256', $token),
                'expires_at' => date('Y-m-d H:i:s', time() + 3600),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            Mailer::send($email, 'Reset your TicketHub password',
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
        $this->db->table('users')->where('email', $row['email'])->update([
            'password_hash' => password_hash($pass, PASSWORD_DEFAULT),
            'remember_selector' => null, 'remember_validator' => null, 'remember_expires' => null,
            'must_change_password' => 0,
        ]);
        $this->db->table('password_resets')->where('email', $row['email'])->delete();
        Audit::log('password.reset_completed', $row['email']);
        $this->session->setFlashdata('error', '');
        $this->toast('Password changed — sign in with the new one');

        return redirect()->to('/login');
    }
}
