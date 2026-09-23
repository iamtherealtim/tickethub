<?php

/**
 * Admin → Identity: two-factor policy, generic OpenID Connect, LDAP / AD, SAML.
 * Body: identity.php. Actions: App\Controllers\Admin\IdentityController.
 */
return [
    'label' => 'Identity & 2FA',
    'icon'  => 'lock',
    'order' => 115,
    'data'  => static function ($request, $me): array {
        $db = db_connect();

        try {
            $mfaUsers = $db->table('users')->select('id, name, email, role, totp_enabled_at, auth_provider')
                ->where('totp_enabled_at IS NOT NULL', null, false)->orderBy('name')->get()->getResultArray();
        } catch (\Throwable $e) {
            $mfaUsers = []; // migration not applied yet
        }

        return [
            'mfaUsers'        => $mfaUsers,
            'ldapAvailable'   => \App\Libraries\Ldap::available(),
            'oidcCallback'    => site_url('auth/oidc/callback'),
            'samlAvailable'   => \App\Libraries\Saml::available(),
            'samlAcsUrl'      => site_url('auth/saml/acs'),
            'samlMetadataUrl' => site_url('auth/saml/metadata'),
            'samlEntityId'    => \App\Libraries\Saml::available() ? \App\Libraries\Saml::spEntityId() : site_url('auth/saml/metadata'),
        ];
    },
];
