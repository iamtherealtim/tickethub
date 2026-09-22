<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\Audit;
use App\Libraries\Ldap;
use App\Libraries\Oidc;
use App\Libraries\Settings;

/** Admin → Identity tab: MFA policy, generic OIDC, LDAP/AD. Routes in Config/Routes/identity.php. */
class IdentityController extends BaseController
{
    private const TAB = '/app/admin/identity';

    public function saveMfa()
    {
        $policy = (string) $this->request->getPost('mfa_required_roles');
        if (! in_array($policy, ['none', 'agents', 'all'], true)) {
            $policy = 'none';
        }
        Settings::set('mfa_required_roles', $policy);
        Audit::log('settings.mfa_policy', $policy);
        $this->toast('Two-factor policy saved');

        return redirect()->to(self::TAB);
    }

    public function resetTwoFactor(int $id)
    {
        $u = $this->db->table('users')->where('id', $id)->get()->getRowArray();
        if (! $u) {
            $this->toast('That user no longer exists', 'warn');

            return redirect()->to(self::TAB);
        }
        $this->db->table('users')->where('id', $id)->update([
            'totp_secret' => null, 'totp_enabled_at' => null, 'totp_recovery' => null,
            // Their remembered devices are no longer 2FA-backed; make them sign in fresh.
            'remember_selector' => null, 'remember_validator' => null, 'remember_expires' => null,
        ]);
        Audit::log('2fa.reset_by_admin', $u['email'] . ' by ' . $this->me['email'], $id);
        $this->toast('Two-factor reset for ' . $u['name'] . ' — they can enrol again at next sign-in');

        return redirect()->to(self::TAB);
    }

    public function saveOidc()
    {
        $p = $this->request->getPost();
        $issuer = rtrim(trim((string) ($p['oidc_issuer'] ?? '')), '/');
        if ($issuer !== '' && ! preg_match('#^https://[^\s/]+#i', $issuer)) {
            $this->toast('The issuer must be an https:// URL (e.g. https://accounts.google.com)', 'warn');

            return redirect()->to(self::TAB);
        }
        $scopes = trim((string) ($p['oidc_scopes'] ?? ''));
        Settings::saveMany([
            'oidc_enabled'      => isset($p['oidc_enabled']) ? '1' : '0',
            'oidc_issuer'       => $issuer,
            'oidc_client_id'    => trim((string) ($p['oidc_client_id'] ?? '')),
            'oidc_client_secret' => $p['oidc_client_secret'] ?? '',
            'oidc_scopes'       => $scopes !== '' ? $scopes : 'openid profile email',
            'oidc_button_label' => mb_substr(trim((string) ($p['oidc_button_label'] ?? '')), 0, 40),
            'oidc_agent_claim'  => trim((string) ($p['oidc_agent_claim'] ?? '')),
            'oidc_agent_value'  => trim((string) ($p['oidc_agent_value'] ?? '')),
            'oidc_admin_claim'  => trim((string) ($p['oidc_admin_claim'] ?? '')),
            'oidc_admin_value'  => trim((string) ($p['oidc_admin_value'] ?? '')),
        ], ['oidc_client_secret']);
        if ($issuer !== '') {
            cache()->delete('oidc_disc_' . md5($issuer));
        }
        Audit::log('settings.oidc', $issuer !== '' ? $issuer : '(cleared)');
        $this->toast('Single sign-on settings saved');

        return redirect()->to(self::TAB);
    }

    /** Fetch the discovery document for the saved issuer and report what it offers. */
    public function testOidc()
    {
        try {
            $doc = Oidc::discovery(null, true);
            $bits = [];
            foreach (['authorization_endpoint', 'token_endpoint', 'userinfo_endpoint', 'jwks_uri'] as $k) {
                $bits[] = $k . ': ' . (! empty($doc[$k]) ? 'yes' : 'missing');
            }
            $algs = implode(', ', (array) ($doc['id_token_signing_alg_values_supported'] ?? []));
            $this->toast('Discovery OK — ' . implode(' · ', $bits) . ($algs ? ' · signs with ' . $algs : ''));
        } catch (\Throwable $e) {
            $this->toast('Discovery failed: ' . $e->getMessage(), 'warn');
        }

        return redirect()->to(self::TAB);
    }

    public function saveLdap()
    {
        $p = $this->request->getPost();
        $enc = (string) ($p['ldap_encryption'] ?? 'none');
        if (! in_array($enc, ['none', 'starttls', 'ldaps'], true)) {
            $enc = 'none';
        }
        $port = (int) ($p['ldap_port'] ?? 0);
        $filter = trim((string) ($p['ldap_user_filter'] ?? ''));
        if ($filter !== '' && ! str_contains($filter, '{login}')) {
            $this->toast('The user filter must contain {login}', 'warn');

            return redirect()->to(self::TAB);
        }
        $attr = static fn (string $k) => preg_replace('/[^A-Za-z0-9-]/', '', (string) ($p[$k] ?? '')) ?? '';
        Settings::saveMany([
            'ldap_enabled'        => isset($p['ldap_enabled']) ? '1' : '0',
            'ldap_host'           => trim((string) ($p['ldap_host'] ?? '')),
            'ldap_port'           => $port > 0 && $port < 65536 ? (string) $port : '',
            'ldap_encryption'     => $enc,
            'ldap_bind_dn'        => trim((string) ($p['ldap_bind_dn'] ?? '')),
            'ldap_bind_password'  => $p['ldap_bind_password'] ?? '',
            'ldap_base_dn'        => trim((string) ($p['ldap_base_dn'] ?? '')),
            'ldap_user_filter'    => $filter,
            'ldap_attr_email'     => $attr('ldap_attr_email'),
            'ldap_attr_name'      => $attr('ldap_attr_name'),
            'ldap_attr_title'     => $attr('ldap_attr_title'),
            'ldap_attr_phone'     => $attr('ldap_attr_phone'),
            'ldap_attr_dept'      => $attr('ldap_attr_dept'),
            'ldap_agent_group_dn' => trim((string) ($p['ldap_agent_group_dn'] ?? '')),
            'ldap_admin_group_dn' => trim((string) ($p['ldap_admin_group_dn'] ?? '')),
            'ldap_create_users'   => isset($p['ldap_create_users']) ? '1' : '0',
        ], ['ldap_bind_password']);
        Audit::log('settings.ldap', trim((string) ($p['ldap_host'] ?? '')) ?: '(cleared)');
        $this->toast(Ldap::available() ? 'Directory settings saved' : 'Directory settings saved — but php-ldap is not installed, so sign-in stays off', Ldap::available() ? 'ok' : 'warn');

        return redirect()->to(self::TAB);
    }

    public function testLdap()
    {
        $r = Ldap::test();
        $this->toast(($r['ok'] ? 'LDAP OK — ' : 'LDAP test failed — ') . $r['message'], $r['ok'] ? 'ok' : 'warn');

        return redirect()->to(self::TAB);
    }
}
