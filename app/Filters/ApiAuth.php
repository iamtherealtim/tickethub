<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Bearer-token auth for the JSON API.
 *
 * A token maps to a user, so an API call carries that person's role and group
 * scope rather than being an anonymous superuser. The token is only ever stored
 * hashed; the lookup is by hash, so a leaked database yields nothing usable.
 */
class ApiAuth implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $header = (string) $request->getHeaderLine('Authorization');
        if (! preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
            return $this->deny('Missing bearer token', 401);
        }

        $db  = db_connect();
        $row = $db->table('api_tokens')
            ->where('token_hash', hash('sha256', $m[1]))
            ->where('revoked_at', null)
            ->get()->getRowArray();

        if (! $row) {
            return $this->deny('Invalid or revoked token', 401);
        }

        $user = $db->table('users')->where('id', $row['user_id'])->where('active', 1)->get()->getRowArray();
        if (! $user) {
            return $this->deny('The account behind this token is inactive', 403);
        }

        // Touch last-used so stale tokens are identifiable in the admin list.
        $db->table('api_tokens')->where('id', $row['id'])->update(['last_used_at' => date('Y-m-d H:i:s')]);

        // Downstream controllers read the session the same way a browser request
        // would, so scoping and audit attribution need no special-casing.
        session()->set([
            'user_id'   => (int) $user['id'],
            'role'      => $user['role'],
            'api_token' => (int) $row['id'],
        ]);
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }

    private function deny(string $message, int $code)
    {
        return service('response')
            ->setStatusCode($code)
            ->setJSON(['error' => $message]);
    }
}
