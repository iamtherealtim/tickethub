<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Accounts created by an administrator (or carried over from the demo seed) hold a
 * one-time password. Until it is replaced, the only pages reachable are the
 * change-password form and logout.
 */
class MustChangePassword implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $uid = session()->get('user_id');
        if (! $uid) {
            return null;
        }
        $user = db_connect()->table('users')->select('must_change_password')->where('id', $uid)->get()->getRowArray();
        if (! $user || ! (int) $user['must_change_password']) {
            return null;
        }

        $path = trim($request->getUri()->getPath(), '/');
        $allowed = ['account/new-password', 'account/password', 'logout'];
        if (in_array($path, $allowed, true)) {
            return null;
        }

        return redirect()->to('/account/new-password');
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}
