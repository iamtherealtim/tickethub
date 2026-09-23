<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\Audit;
use App\Libraries\Ldap;
use App\Libraries\Oidc;
use App\Libraries\Saml;
use App\Libraries\Settings;

/** Admin → Identity tab: MFA policy, generic OIDC, LDAP/AD, SAML. Routes in Config/Routes/identity.php. */
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

    /**
     * `sso_required_roles` (none | agents | all): blocks a plain local
     * password for accounts in scope — they must use one of the configured
     * SSO methods, or LDAP where that applies. Administrators are always
     * exempt, whatever the policy says, so a broken IdP can never lock
     * everyone out of their own instance; see AuthController::localLoginBlocked().
     */
    public function saveSsoPolicy()
    {
        $policy = (string) $this->request->getPost('sso_required_roles');
        if (! in_array($policy, ['none', 'agents', 'all'], true)) {
            $policy = 'none';
        }
        Settings::set('sso_required_roles', $policy);
        Audit::log('settings.sso_required_policy', $policy);
        $this->toast('Single sign-on policy saved');

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
            'oidc_allowed_domains' => strtolower(trim((string) ($p['oidc_allowed_domains'] ?? ''))),
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

    public function saveSaml()
    {
        if (! Saml::available()) {
            $this->toast('SAML settings saved — but the onelogin/php-saml package is not installed, so sign-in stays off. Run composer install.', 'warn');
        }
        $p = $this->request->getPost();
        Settings::saveMany([
            'saml_enabled'       => isset($p['saml_enabled']) ? '1' : '0',
            'saml_idp_entity_id' => trim((string) ($p['saml_idp_entity_id'] ?? '')),
            'saml_idp_sso_url'   => trim((string) ($p['saml_idp_sso_url'] ?? '')),
            'saml_idp_x509cert'  => trim((string) ($p['saml_idp_x509cert'] ?? '')),
            'saml_sp_entity_id'  => trim((string) ($p['saml_sp_entity_id'] ?? '')),
            'saml_button_label'  => mb_substr(trim((string) ($p['saml_button_label'] ?? '')), 0, 40),
            'saml_attr_email'    => trim((string) ($p['saml_attr_email'] ?? '')),
            'saml_attr_name'     => trim((string) ($p['saml_attr_name'] ?? '')),
            'saml_agent_attr'    => trim((string) ($p['saml_agent_attr'] ?? '')),
            'saml_agent_value'   => trim((string) ($p['saml_agent_value'] ?? '')),
            'saml_admin_attr'    => trim((string) ($p['saml_admin_attr'] ?? '')),
            'saml_admin_value'   => trim((string) ($p['saml_admin_value'] ?? '')),
            'saml_allowed_domains'      => strtolower(trim((string) ($p['saml_allowed_domains'] ?? ''))),
            'saml_allow_idp_initiated'  => isset($p['saml_allow_idp_initiated']) ? '1' : '0',
        ]);
        Audit::log('settings.saml', trim((string) ($p['saml_idp_entity_id'] ?? '')) ?: '(cleared)');
        if (Saml::available()) {
            $this->toast('SAML settings saved');
        }

        return redirect()->to(self::TAB);
    }

    /** Import entity ID, SSO URL and signing cert from the IdP's own metadata document. */
    public function fetchSamlMetadata()
    {
        $url = trim((string) $this->request->getPost('saml_metadata_url'));
        if ($url === '') {
            $this->toast('Paste the identity provider\'s metadata URL first', 'warn');

            return redirect()->to(self::TAB);
        }

        try {
            $idp = Saml::fetchIdpMetadata($url);
            Settings::saveMany([
                'saml_idp_entity_id' => (string) $idp['entityId'],
                'saml_idp_sso_url'   => (string) ($idp['singleSignOnService']['url'] ?? ''),
                'saml_idp_x509cert'  => (string) $idp['x509cert'],
            ]);
            Audit::log('settings.saml_metadata_fetched', $url);
            $this->toast('Imported entity ID, SSO URL and signing certificate from the metadata document');
        } catch (\Throwable $e) {
            $this->toast('Could not read that metadata document: ' . $e->getMessage(), 'warn');
        }

        return redirect()->to(self::TAB);
    }
}
