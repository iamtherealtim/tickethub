<?php

namespace App\Libraries;

/**
 * LDAP / Active Directory sign-in. Service-account bind → search for the user
 * → bind as the user with the submitted password → read profile attributes and
 * memberOf for role mapping. Every entry point is guarded on the php-ldap
 * extension so the rest of the app runs fine without it.
 */
class Ldap
{
    public const DEFAULT_FILTER = '(|(mail={login})(sAMAccountName={login})(uid={login}))';

    public static function available(): bool
    {
        return extension_loaded('ldap');
    }

    public static function enabled(): bool
    {
        return self::available() && Settings::get('ldap_enabled') === '1' && Settings::get('ldap_host') !== '';
    }

    /** Attribute names as configured, with sensible AD defaults. */
    public static function attrs(): array
    {
        $pick = static fn (string $key, string $default) => strtolower(trim(Settings::get($key))) ?: $default;

        return [
            'email' => $pick('ldap_attr_email', 'mail'),
            'name'  => $pick('ldap_attr_name', 'displayname'),
            'title' => $pick('ldap_attr_title', 'title'),
            'phone' => $pick('ldap_attr_phone', 'telephonenumber'),
            'dept'  => $pick('ldap_attr_dept', 'department'),
        ];
    }

    /**
     * Open a connection and bind with the service account.
     *
     * @return resource|\LDAP\Connection
     */
    private static function connect()
    {
        if (! self::available()) {
            throw new \RuntimeException('The php-ldap extension is not installed on this server.');
        }
        $host = trim(Settings::get('ldap_host'));
        $enc  = Settings::get('ldap_encryption', 'none');
        $port = (int) Settings::get('ldap_port') ?: ($enc === 'ldaps' ? 636 : 389);
        if ($host === '') {
            throw new \RuntimeException('No LDAP host configured.');
        }
        $uri = ($enc === 'ldaps' ? 'ldaps://' : 'ldap://') . $host . ':' . $port;
        $conn = @ldap_connect($uri);
        if (! $conn) {
            throw new \RuntimeException('Could not parse the LDAP URI ' . $uri . '.');
        }
        ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
        ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, 5);
        if ($enc === 'starttls' && ! @ldap_start_tls($conn)) {
            throw new \RuntimeException('StartTLS failed: ' . ldap_error($conn));
        }
        $bindDn = trim(Settings::get('ldap_bind_dn'));
        $bindPw = Settings::get('ldap_bind_password');
        $ok = $bindDn === '' ? @ldap_bind($conn) : @ldap_bind($conn, $bindDn, $bindPw);
        if (! $ok) {
            throw new \RuntimeException('Service account bind failed: ' . ldap_error($conn));
        }

        return $conn;
    }

    /** Look up a user's entry by login; null when not found. */
    private static function find($conn, string $login): ?array
    {
        $filter = trim(Settings::get('ldap_user_filter')) ?: self::DEFAULT_FILTER;
        $filter = str_replace('{login}', ldap_escape($login, '', LDAP_ESCAPE_FILTER), $filter);
        $attrs  = array_values(array_unique(array_merge(array_values(self::attrs()), ['memberof', 'dn'])));
        $base   = trim(Settings::get('ldap_base_dn'));
        $result = @ldap_search($conn, $base, $filter, $attrs, 0, 2);
        if (! $result) {
            throw new \RuntimeException('Search failed: ' . ldap_error($conn));
        }
        $entries = ldap_get_entries($conn, $result);
        if (! $entries || (int) $entries['count'] !== 1) {
            return null; // none, or ambiguous
        }
        $e = $entries[0];
        $get = static function (string $attr) use ($e): string {
            $attr = strtolower($attr);

            return isset($e[$attr][0]) ? (string) $e[$attr][0] : '';
        };
        $groups = [];
        if (isset($e['memberof']) && is_array($e['memberof'])) {
            foreach ($e['memberof'] as $k => $g) {
                if ($k !== 'count') {
                    $groups[] = (string) $g;
                }
            }
        }
        $a = self::attrs();

        return [
            'dn'     => (string) $e['dn'],
            'email'  => strtolower($get($a['email'])),
            'name'   => $get($a['name']),
            'title'  => $get($a['title']),
            'phone'  => $get($a['phone']),
            'dept'   => $get($a['dept']),
            'groups' => $groups,
        ];
    }

    /**
     * Authenticate a login/password against the directory. Returns the profile
     * on success, null on bad credentials or unknown user. Throws only for
     * infrastructure problems (host down, service bind failed).
     */
    public static function authenticate(string $login, string $password): ?array
    {
        if ($login === '' || $password === '') {
            return null; // an empty password would be an anonymous bind = "success"
        }
        $conn = self::connect();

        try {
            $user = self::find($conn, $login);
            if (! $user) {
                return null;
            }
            if (! @ldap_bind($conn, $user['dn'], $password)) {
                return null;
            }

            return $user;
        } finally {
            @ldap_unbind($conn);
        }
    }

    /** "Test connection" for the admin tab: binds and runs a one-entry search. */
    public static function test(): array
    {
        if (! self::available()) {
            return ['ok' => false, 'message' => 'The php-ldap extension is not installed, so LDAP sign-in cannot run on this server.'];
        }

        try {
            $conn = self::connect();
            $base = trim(Settings::get('ldap_base_dn'));
            $res  = @ldap_search($conn, $base, '(objectClass=*)', ['dn'], 0, 1);
            if (! $res) {
                $err = ldap_error($conn);
                @ldap_unbind($conn);

                return ['ok' => false, 'message' => 'Bound, but the search under the base DN failed: ' . $err];
            }
            @ldap_unbind($conn);

            return ['ok' => true, 'message' => 'Connected and bound; the base DN is readable.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Role from memberOf. Null when no group DN is configured; otherwise
     * Administrator > Agent > Requester (the caller applies the last-admin rule).
     */
    public static function roleFromGroups(array $memberOf): ?string
    {
        $admin = strtolower(trim(Settings::get('ldap_admin_group_dn')));
        $agent = strtolower(trim(Settings::get('ldap_agent_group_dn')));
        if ($admin === '' && $agent === '') {
            return null;
        }
        $groups = array_map(static fn ($g) => strtolower(trim($g)), $memberOf);
        if ($admin !== '' && in_array($admin, $groups, true)) {
            return 'Administrator';
        }
        if ($agent !== '' && in_array($agent, $groups, true)) {
            return 'Agent';
        }

        return 'Requester';
    }
}
