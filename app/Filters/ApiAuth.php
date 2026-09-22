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
 *
 * The session is used purely as an in-request carrier for user_id/role so the
 * controllers work unchanged; it is destroyed again in after() and no session
 * cookie ever leaves the server, so an API token can never turn into a browser
 * login.
 */
class ApiAuth implements FilterInterface
{
    /** Requests per minute, per token. */
    private const RATE_LIMIT = 120;

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
        if (! empty($row['expires_at']) && strtotime($row['expires_at']) < time()) {
            return $this->deny('Token has expired', 401);
        }

        $user = $db->table('users')->where('id', $row['user_id'])->where('active', 1)->get()->getRowArray();
        if (! $user) {
            return $this->deny('The account behind this token is inactive', 403);
        }

        // Token bucket per token id: 120/min, refilling continuously.
        $throttler = service('throttler');
        if ($throttler->check('api_token_' . $row['id'], self::RATE_LIMIT, MINUTE) === false) {
            return $this->deny('Rate limit exceeded: ' . self::RATE_LIMIT . ' requests per minute per token', 429)
                ->setHeader('Retry-After', (string) max(1, (int) ceil($throttler->getTokenTime())));
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
        // The session only ever lived for this request.
        $session = session();
        if ($session->get('api_token')) {
            $session->destroy();
        }
        $this->stripSessionCookie($response);
    }

    /**
     * PHP queues the session Set-Cookie itself at session_start(), and the
     * framework may queue another via its cookie store on regenerate. Remove both.
     */
    private function stripSessionCookie(ResponseInterface $response): void
    {
        $name = config('Session')->cookieName;

        if (! headers_sent()) {
            header_remove('Set-Cookie');
        }
        if ($response->hasCookie($name)) {
            // The store is immutable and the property has no setter; rebind a
            // closure so the response drops just that cookie.
            (function () use ($name) {
                $this->cookieStore = $this->cookieStore->remove($name);
            })->call($response);
        }
    }

    private function deny(string $message, int $code)
    {
        $response = service('response')
            ->setStatusCode($code)
            ->setJSON(['error' => $message]);
        $this->stripSessionCookie($response);

        return $response;
    }
}
