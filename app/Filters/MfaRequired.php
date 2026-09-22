<?php

namespace App\Filters;

use App\Libraries\Settings;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Enforces the admin's `mfa_required_roles` policy (none | agents | all): a
 * signed-in user in scope who has not enrolled an authenticator is sent to
 * the security page until they do. Sign-in, sign-out, the security page
 * itself, file downloads and the token API are left alone.
 */
class MfaRequired implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $uid = (int) session()->get('user_id');
        if (! $uid) {
            return null;
        }
        $policy = Settings::get('mfa_required_roles', 'none');
        if ($policy !== 'agents' && $policy !== 'all') {
            return null;
        }

        $path = trim($request->getUri()->getPath(), '/');
        foreach (['login', 'logout', 'account/security', 'account/new-password', 'account/password', 'files', 'api', 'auth'] as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return null;
            }
        }

        try {
            $user = db_connect()->table('users')->select('role, totp_enabled_at')->where('id', $uid)->get()->getRowArray();
        } catch (\Throwable $e) {
            return null; // column not migrated yet
        }
        if (! $user || ! empty($user['totp_enabled_at'])) {
            return null;
        }
        $agent = in_array($user['role'], ['Administrator', 'Supervisor', 'Agent'], true);
        if ($policy === 'agents' && ! $agent) {
            return null;
        }
        if ($request->isAJAX() || str_starts_with((string) $request->getHeaderLine('Accept'), 'application/json')) {
            return service('response')->setStatusCode(403)->setJSON(['error' => 'Two-factor authentication is required for your account. Set it up under Account → Security.']);
        }
        session()->setFlashdata('toast', ['msg' => 'Two-factor authentication is required for your role — set it up to continue', 'kind' => 'warn']);

        return redirect()->to('/account/security');
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}
