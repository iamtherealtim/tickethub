<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Signs out sessions that predate the user's last password reset/change.
 *
 * Login stores users.session_epoch in the session; resetting or changing the
 * password bumps the column. A session whose stored epoch no longer matches is
 * destroyed here on its next request, so "sign out everywhere" needs no
 * per-user index of file-backed sessions.
 */
class SessionEpoch implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $session = session();
        $uid     = (int) $session->get('user_id');
        if (! $uid) {
            return null;
        }

        $row = db_connect()->table('users')->select('session_epoch')->where('id', $uid)->get()->getRowArray();
        if (! $row) {
            return null; // BaseController handles missing/deactivated users.
        }
        $current = (int) $row['session_epoch'];

        if (! $session->has('session_epoch')) {
            // Session minted by a path that does not record the epoch (e.g. a
            // remembered-device login): adopt the current one. Remember tokens are
            // themselves cleared on every password change, so this is safe.
            $session->set('session_epoch', $current);

            return null;
        }

        if ((int) $session->get('session_epoch') !== $current) {
            $session->destroy();

            return redirect()->to('/login?signed_out=1')->deleteCookie('th_remember');
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}
