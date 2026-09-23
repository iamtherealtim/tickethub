<?php

namespace App\Filters;

/**
 * The signed-in user's role and active flag, read fresh from the database.
 *
 * The role filters used to trust the role stored in the session. Filters run
 * before the controller, so a user who had just been demoted or deactivated
 * still got one more request with their old privileges (the controller only
 * noticed afterwards). Reading the row here closes that window for every
 * path that changes a role or deactivates someone — admin forms, the API,
 * SSO role mapping — without each of them having to remember to do it.
 */
final class SessionUser
{
    /** @return array{id:int, role:string}|null null when nobody valid is signed in (session cleared). */
    public static function resolve(): ?array
    {
        $session = session();
        $uid     = (int) $session->get('user_id');
        if ($uid <= 0) {
            return null;
        }
        $row = db_connect()->table('users')->select('id, role, active')->where('id', $uid)->get()->getRowArray();
        if (! $row || ! (int) $row['active']) {
            $session->destroy();

            return null;
        }
        if ($session->get('role') !== $row['role']) {
            $session->set('role', $row['role']);
        }

        return ['id' => (int) $row['id'], 'role' => (string) $row['role']];
    }
}
