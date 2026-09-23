<?php

namespace App\Libraries;

/**
 * Generic OpenID Connect relying party (authorization code + PKCE S256).
 *
 * Works against any provider that publishes a discovery document — Google,
 * Okta, Keycloak, Auth0, Entra (though Entra has its own tuned flow). The
 * id_token signature is verified against the provider's JWKS (RS256/384/512
 * via openssl) before any claim is trusted, and the userinfo endpoint is
 * consulted afterwards to fill in claims the id_token left out.
 */
class Oidc
{
    private const CACHE_TTL = 3600;

    public static function enabled(): bool
    {
        return Settings::get('oidc_enabled') === '1'
            && Settings::get('oidc_issuer') !== ''
            && Settings::get('oidc_client_id') !== '';
    }

    public static function buttonLabel(): string
    {
        return Settings::get('oidc_button_label') !== '' ? Settings::get('oidc_button_label') : 'Sign in with SSO';
    }

    public static function scopes(): string
    {
        $s = trim(Settings::get('oidc_scopes'));
        if ($s === '') {
            $s = 'openid profile email';
        }
        if (! preg_match('/\bopenid\b/', $s)) {
            $s = 'openid ' . $s;
        }

        return $s;
    }

    private static function client()
    {
        return service('curlrequest', ['timeout' => 10, 'http_errors' => false]);
    }

    /** Discovery document for the configured issuer, cached for an hour. */
    public static function discovery(?string $issuer = null, bool $fresh = false): array
    {
        $issuer = rtrim($issuer ?? Settings::get('oidc_issuer'), '/');
        if ($issuer === '' || ! preg_match('#^https://#i', $issuer)) {
            throw new \RuntimeException('OIDC issuer must be an https:// URL.');
        }
        $key = 'oidc_disc_' . md5($issuer);
        if (! $fresh && ($doc = cache($key)) && is_array($doc)) {
            return $doc;
        }
        $url = str_ends_with($issuer, '/.well-known/openid-configuration') ? $issuer : $issuer . '/.well-known/openid-configuration';
        helper('tickethub');
        if ($err = th_outbound_url_error($url)) {
            throw new \RuntimeException('OIDC issuer refused: ' . $err);
        }
        $res = self::client()->get($url);
        $doc = json_decode((string) $res->getBody(), true);
        if ($res->getStatusCode() !== 200 || ! is_array($doc) || empty($doc['authorization_endpoint']) || empty($doc['token_endpoint'])) {
            throw new \RuntimeException('Could not read the OpenID discovery document at ' . $url . ' (HTTP ' . $res->getStatusCode() . ').');
        }
        // Per spec the document's issuer must match the URL it was fetched from.
        if (rtrim((string) ($doc['issuer'] ?? ''), '/') !== $issuer) {
            throw new \RuntimeException('Discovery document issuer (' . ($doc['issuer'] ?? '?') . ') does not match the configured issuer.');
        }
        // The server calls these itself (and sends the client secret to one of
        // them), so a discovery document must not point them inside the network.
        foreach (['token_endpoint', 'jwks_uri', 'userinfo_endpoint'] as $k) {
            if (! empty($doc[$k]) && ($err = th_outbound_url_error((string) $doc[$k]))) {
                throw new \RuntimeException('Discovery document ' . $k . ' refused: ' . $err);
            }
            if (! empty($doc[$k]) && ! preg_match('#^https://#i', (string) $doc[$k])) {
                throw new \RuntimeException('Discovery document ' . $k . ' must be https.');
            }
        }
        cache()->save($key, $doc, self::CACHE_TTL);

        return $doc;
    }

    /** JSON Web Key Set, cached for an hour; $fresh forces a refetch (key rotation). */
    public static function jwks(array $doc, bool $fresh = false): array
    {
        $uri = (string) ($doc['jwks_uri'] ?? '');
        if ($uri === '') {
            throw new \RuntimeException('The provider publishes no jwks_uri, so id_tokens cannot be verified.');
        }
        $key = 'oidc_jwks_' . md5($uri);
        if (! $fresh && ($set = cache($key)) && is_array($set)) {
            return $set;
        }
        $res = self::client()->get($uri);
        $set = json_decode((string) $res->getBody(), true);
        if ($res->getStatusCode() !== 200 || ! is_array($set) || ! isset($set['keys'])) {
            throw new \RuntimeException('Could not fetch the provider JWKS.');
        }
        cache()->save($key, $set, self::CACHE_TTL);

        return $set;
    }

    /* ---------- authorization request ---------- */

    /** Build the redirect URL and park state/nonce/verifier in the session. */
    public static function authorizationUrl(string $redirectUri): string
    {
        $doc      = self::discovery();
        $state    = bin2hex(random_bytes(16));
        $nonce    = bin2hex(random_bytes(16));
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        session()->set([
            'oidc_state'    => $state,
            'oidc_nonce'    => $nonce,
            'oidc_verifier' => $verifier,
        ]);
        $params = [
            'client_id'             => Settings::get('oidc_client_id'),
            'response_type'         => 'code',
            'redirect_uri'          => $redirectUri,
            'scope'                 => self::scopes(),
            'state'                 => $state,
            'nonce'                 => $nonce,
            'code_challenge'        => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ];

        return $doc['authorization_endpoint'] . (str_contains($doc['authorization_endpoint'], '?') ? '&' : '?') . http_build_query($params);
    }

    /* ---------- callback ---------- */

    /**
     * Exchange the code, verify the id_token and return the merged claims.
     * Throws RuntimeException with a user-safe message on any failure.
     */
    public static function handleCallback(string $code, string $state, string $redirectUri): array
    {
        $session  = session();
        $saved    = (string) $session->get('oidc_state');
        $nonce    = (string) $session->get('oidc_nonce');
        $verifier = (string) $session->get('oidc_verifier');
        $session->remove(['oidc_state', 'oidc_nonce', 'oidc_verifier']);

        if ($code === '' || $saved === '' || ! hash_equals($saved, $state)) {
            throw new \RuntimeException('invalid state. Please try again.');
        }
        $doc = self::discovery();

        $form = [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $redirectUri,
            'client_id'     => Settings::get('oidc_client_id'),
            'code_verifier' => $verifier,
        ];
        $opts = ['form_params' => $form];
        $secret = Settings::get('oidc_client_secret');
        if ($secret !== '') {
            // client_secret_basic is the default auth method; most providers also
            // accept client_secret_post, and sending both keeps us compatible.
            $opts['headers'] = ['Authorization' => 'Basic ' . base64_encode(rawurlencode(Settings::get('oidc_client_id')) . ':' . rawurlencode($secret))];
            $opts['form_params']['client_secret'] = $secret;
        }
        $res   = self::client()->post($doc['token_endpoint'], $opts);
        $token = json_decode((string) $res->getBody(), true) ?: [];
        if (empty($token['id_token'])) {
            log_message('error', 'OIDC token error: {err}', ['err' => (string) ($token['error'] ?? ('http ' . $res->getStatusCode()))]);

            throw new \RuntimeException('the provider did not issue an identity token.');
        }

        $claims = self::verifyIdToken((string) $token['id_token'], $doc);
        if ($nonce !== '' && ! hash_equals($nonce, (string) ($claims['nonce'] ?? ''))) {
            throw new \RuntimeException('identity token nonce mismatch.');
        }

        // Userinfo fills in anything the id_token omitted (Google puts email in
        // both; Okta/Keycloak often only in userinfo for thin tokens).
        if (! empty($token['access_token']) && ! empty($doc['userinfo_endpoint'])) {
            try {
                $ui   = self::client()->get($doc['userinfo_endpoint'], ['headers' => ['Authorization' => 'Bearer ' . $token['access_token']]]);
                $info = json_decode((string) $ui->getBody(), true);
                if ($ui->getStatusCode() === 200 && is_array($info) && (string) ($info['sub'] ?? $claims['sub']) === (string) $claims['sub']) {
                    $claims = $claims + $info; // verified id_token claims win on conflict
                }
            } catch (\Throwable $e) {
                log_message('warning', 'OIDC userinfo fetch failed: {msg}', ['msg' => $e->getMessage()]);
            }
        }

        return $claims;
    }

    /* ---------- JWT verification ---------- */

    private static function b64url(string $s): string|false
    {
        return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    }

    public static function verifyIdToken(string $jwt, array $doc): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new \RuntimeException('malformed identity token.');
        }
        [$h, $p, $s] = $parts;
        $header = json_decode((string) self::b64url($h), true) ?: [];
        $claims = json_decode((string) self::b64url($p), true) ?: [];
        $sig    = self::b64url($s);
        $alg    = (string) ($header['alg'] ?? '');
        $algos  = ['RS256' => OPENSSL_ALGO_SHA256, 'RS384' => OPENSSL_ALGO_SHA384, 'RS512' => OPENSSL_ALGO_SHA512];
        if (! isset($algos[$alg]) || $sig === false || ! $claims) {
            throw new \RuntimeException('unsupported identity token algorithm (' . ($alg ?: 'none') . ').');
        }

        $verified = false;
        foreach ([false, true] as $fresh) {
            $set = self::jwks($doc, $fresh);
            foreach ($set['keys'] as $jwk) {
                if (($jwk['kty'] ?? '') !== 'RSA' || empty($jwk['n']) || empty($jwk['e'])) {
                    continue;
                }
                if (! empty($header['kid']) && ! empty($jwk['kid']) && $jwk['kid'] !== $header['kid']) {
                    continue;
                }
                $pem = self::rsaPem((string) $jwk['n'], (string) $jwk['e']);
                if ($pem !== null && openssl_verify($h . '.' . $p, $sig, $pem, $algos[$alg]) === 1) {
                    $verified = true;

                    break 2;
                }
            }
            // Not found in the cached set: rotate once, then give up.
        }
        if (! $verified) {
            throw new \RuntimeException('identity token signature could not be verified.');
        }

        $issuer = rtrim(Settings::get('oidc_issuer'), '/');
        $iss    = rtrim((string) ($claims['iss'] ?? ''), '/');
        $aud    = $claims['aud'] ?? '';
        $audOk  = is_array($aud) ? in_array(Settings::get('oidc_client_id'), array_map('strval', $aud), true) : hash_equals(Settings::get('oidc_client_id'), (string) $aud);
        if ($iss !== $issuer || ! $audOk) {
            throw new \RuntimeException('identity token issuer/audience mismatch.');
        }
        if ((int) ($claims['exp'] ?? 0) < time() - 60) {
            throw new \RuntimeException('identity token has expired.');
        }
        if (isset($claims['nbf']) && (int) $claims['nbf'] > time() + 60) {
            throw new \RuntimeException('identity token is not yet valid.');
        }
        if (empty($claims['sub'])) {
            throw new \RuntimeException('identity token carries no subject.');
        }

        return $claims;
    }

    /** Build a PEM public key from JWK modulus/exponent (DER SubjectPublicKeyInfo). */
    public static function rsaPem(string $n, string $e): ?string
    {
        $nb = self::b64url($n);
        $eb = self::b64url($e);
        if ($nb === false || $eb === false || $nb === '' || $eb === '') {
            return null;
        }
        $int = static function (string $bytes): string {
            $bytes = ltrim($bytes, "\0");
            if ($bytes === '' || (ord($bytes[0]) & 0x80)) {
                $bytes = "\0" . $bytes; // keep it positive
            }

            return "\x02" . self::derLen(strlen($bytes)) . $bytes;
        };
        $rsaKey = "\x30" . self::derLen(strlen($int($nb) . $int($eb))) . $int($nb) . $int($eb);
        $bitStr = "\x03" . self::derLen(strlen($rsaKey) + 1) . "\0" . $rsaKey;
        $algId  = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00"; // rsaEncryption, NULL
        $spki   = "\x30" . self::derLen(strlen($algId . $bitStr)) . $algId . $bitStr;

        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private static function derLen(int $len): string
    {
        if ($len < 0x80) {
            return chr($len);
        }
        $bytes = ltrim(pack('N', $len), "\0");

        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    /* ---------- role mapping ---------- */

    /**
     * Map claims onto a role from the admin's claim/value pairs. Null when no
     * mapping is configured (the caller keeps whatever role the user has).
     */
    public static function roleFromClaims(array $claims): ?string
    {
        $adminClaim = trim(Settings::get('oidc_admin_claim'));
        $adminValue = trim(Settings::get('oidc_admin_value'));
        $agentClaim = trim(Settings::get('oidc_agent_claim'));
        $agentValue = trim(Settings::get('oidc_agent_value'));
        $adminOn = $adminClaim !== '' && $adminValue !== '';
        $agentOn = $agentClaim !== '' && $agentValue !== '';
        if (! $adminOn && ! $agentOn) {
            return null;
        }
        if ($adminOn && self::claimHas($claims, $adminClaim, $adminValue)) {
            return 'Administrator';
        }
        if ($agentOn && self::claimHas($claims, $agentClaim, $agentValue)) {
            return 'Agent';
        }

        return 'Requester';
    }

    /** True when the claim equals the value, or (for list claims) contains it. Case-insensitive. */
    public static function claimHas(array $claims, string $claim, string $value): bool
    {
        if (! array_key_exists($claim, $claims)) {
            return false;
        }
        $v = $claims[$claim];
        $needle = strtolower($value);
        if (is_array($v)) {
            foreach ($v as $item) {
                if (is_scalar($item) && strtolower((string) $item) === $needle) {
                    return true;
                }
            }

            return false;
        }
        if (is_bool($v)) {
            return ($v ? 'true' : 'false') === $needle;
        }
        if (is_scalar($v)) {
            $str = strtolower((string) $v);

            return $str === $needle || in_array($needle, array_map('trim', explode(',', $str)), true) || in_array($needle, explode(' ', $str), true);
        }

        return false;
    }
}
