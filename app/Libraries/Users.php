<?php

namespace App\Libraries;

/**
 * Email lookups that are safe against the column collation.
 *
 * users.email uses an accent- and case-insensitive collation, so a plain
 * WHERE email = ? treats "maya@tickethüb.co" and "maya@tickethub.co" as the
 * same address. That is fine — useful, even — for refusing to create a
 * look-alike account, but it must never decide *which* account a sign-in,
 * a password reset or an inbound email belongs to: an attacker who owns the
 * look-alike domain would otherwise be handed the real account.
 *
 * byEmail() only returns a row whose stored address matches the input
 * exactly (case-insensitively, byte for byte otherwise). emailTaken() keeps
 * the loose collation on purpose, for "would this collide?" checks.
 */
class Users
{
    public static function normalize(string $email): string
    {
        return mb_strtolower(trim($email), 'UTF-8');
    }

    /** The account whose email is exactly this address, or null. */
    public static function byEmail(string $email, bool $activeOnly = false): ?array
    {
        $want = self::normalize($email);
        if ($want === '') {
            return null;
        }
        $q = db_connect()->table('users')->where('email', $want);
        if ($activeOnly) {
            $q->where('active', 1);
        }
        foreach ($q->get()->getResultArray() as $row) {
            if (self::normalize((string) $row['email']) === $want) {
                return $row;
            }
        }

        return null;
    }

    /**
     * The `sso_required_roles` policy (none | agents | all): true when this
     * account must not get in on a plain local password — at the login form
     * or by a remember-me cookie minted from one. Administrators are always
     * exempt, so a broken identity provider can never lock every admin out.
     */
    public static function localPasswordBlocked(array $user): bool
    {
        if (($user['role'] ?? '') === 'Administrator') {
            return false;
        }
        $policy = Settings::get('sso_required_roles', 'none');
        if ($policy === 'all') {
            return true;
        }

        return $policy === 'agents' && in_array($user['role'] ?? '', ['Supervisor', 'Agent'], true);
    }

    /** True when any account collides with this address under the column collation (look-alikes included). */
    public static function emailTaken(string $email): bool
    {
        return db_connect()->table('users')->where('email', self::normalize($email))->countAllResults() > 0;
    }
}
