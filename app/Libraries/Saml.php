<?php

namespace App\Libraries;

/**
 * SAML 2.0 service-provider sign-in (SP-initiated and IdP-initiated).
 *
 * XML signature verification is the one piece of this whole app that is not
 * worth writing by hand — canonicalization and signature-wrapping bugs are
 * how most real-world SAML bypasses happen — so this wraps the vetted
 * `onelogin/php-saml` toolkit (itself built on `robrichards/xmlseclibs`)
 * rather than parsing assertions ourselves. Everything here is a thin,
 * deliberately narrow layer: build the settings array from our Settings
 * store, hand the request to the library, and never trust a value the
 * library has not already verified.
 *
 * Security choices that are NOT configurable, on purpose:
 *  - `strict` is always true (enforces Destination, Conditions, timing).
 *  - `wantAssertionsSigned` is always true — an unsigned assertion is never
 *    trusted, no matter what the IdP sends.
 *  - TicketHub never signs its own AuthnRequest and never encrypts assertions
 *    itself, so there is no SP private key to protect; only the IdP's public
 *    certificate is ever configured here.
 *  - RelayState is never used to choose a redirect target (this app has no
 *    open-redirect surface anywhere and SAML does not get to add one).
 */
class Saml
{
    /** Attributes commonly used to carry a display name, in preference order. */
    private const NAME_ATTRS = [
        'displayName', 'name', 'cn',
        'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/displayname',
        'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/name',
    ];

    /** Whether the php-saml toolkit is installed (`composer install`, no --no-dev). */
    public static function available(): bool
    {
        return class_exists(\OneLogin\Saml2\Auth::class);
    }

    public static function enabled(): bool
    {
        return self::available()
            && Settings::get('saml_enabled') === '1'
            && Settings::get('saml_idp_entity_id') !== ''
            && Settings::get('saml_idp_sso_url') !== ''
            && Settings::get('saml_idp_x509cert') !== '';
    }

    public static function buttonLabel(): string
    {
        return Settings::get('saml_button_label') !== '' ? Settings::get('saml_button_label') : 'Sign in with SSO';
    }

    /** Our EntityID: an explicit override, or the metadata URL (the common default). */
    public static function spEntityId(): string
    {
        $custom = trim(Settings::get('saml_sp_entity_id'));

        return $custom !== '' ? $custom : self::metadataUrl();
    }

    public static function acsUrl(): string
    {
        return site_url('auth/saml/acs');
    }

    public static function metadataUrl(): string
    {
        return site_url('auth/saml/metadata');
    }

    /** The php-saml settings array. Never includes a private key — TicketHub has none. */
    public static function settingsArray(): array
    {
        return [
            'strict'  => true,
            'debug'   => false,
            'baseurl' => rtrim(site_url(), '/'),
            'sp'      => [
                'entityId'                 => self::spEntityId(),
                'assertionConsumerService' => [
                    'url'     => self::acsUrl(),
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
                ],
                'NameIDFormat' => 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress',
            ],
            'idp' => [
                'entityId'             => Settings::get('saml_idp_entity_id'),
                'singleSignOnService'  => [
                    'url'     => Settings::get('saml_idp_sso_url'),
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
                ],
                'x509cert' => Settings::get('saml_idp_x509cert'),
            ],
            'security' => [
                // Never accept an unsigned assertion, whatever the IdP is willing to send.
                'wantAssertionsSigned' => true,
                'wantNameId'           => true,
                'wantXMLValidation'    => true,
                // We hold no SP keypair, so we neither sign requests nor decrypt assertions.
                'authnRequestsSigned'      => false,
                'logoutRequestSigned'      => false,
                'logoutResponseSigned'     => false,
                'wantAssertionsEncrypted'  => false,
                'wantNameIdEncrypted'      => false,
                'requestedAuthnContext'    => false,
                'signMetadata'             => false,
            ],
        ];
    }

    /** @throws \OneLogin\Saml2\Error when the settings array itself is invalid. */
    public static function auth(): \OneLogin\Saml2\Auth
    {
        return new \OneLogin\Saml2\Auth(self::settingsArray());
    }

    /** The URL to send the browser to at the IdP. Never redirects itself. */
    /**
     * Start an SP-initiated sign-in. Returns the IdP URL and a random nonce
     * that travels as RelayState and maps, server-side, to the AuthnRequest
     * ID we just issued (see processAcs). The caller also drops the nonce in
     * a cookie so the response can be tied to the browser that started it.
     *
     * @return array{url: string, nonce: string}
     */
    public static function beginLogin(): array
    {
        $nonce = bin2hex(random_bytes(16));
        $auth  = self::auth();
        $url   = $auth->login($nonce, [], false, false, true);
        cache()->save('saml_rs_' . $nonce, (string) $auth->getLastRequestID(), 900);

        return ['url' => $url, 'nonce' => $nonce];
    }

    /** Back-compat for callers that only want the URL. */
    public static function loginUrl(): string
    {
        return self::beginLogin()['url'];
    }

    /**
     * This SP's metadata XML, for an IdP to import directly. Works even before
     * the IdP side is configured — `$spValidationOnly = true` skips the IdP
     * checks the toolkit would otherwise run on construction. That flag only
     * changes what the constructor validates; it has no bearing on how a
     * response is later verified, so it carries no security cost here.
     */
    public static function metadataXml(): string
    {
        return (new \OneLogin\Saml2\Auth(self::settingsArray(), true))->getSettings()->getSPMetadata();
    }

    /**
     * Validate the IdP's metadata certificate over TLS. The toolkit's own
     * default is `validatePeer = false`; that is not a default we want for
     * a call that ends with "and trust this certificate for every login
     * from now on", so it is pinned to true here regardless of caller.
     *
     * @return array{entityId?: string, sso?: array, cert?: string}
     */
    public static function fetchIdpMetadata(string $url): array
    {
        if (! preg_match('#^https://#i', trim($url))) {
            throw new \RuntimeException('the metadata URL must be https://.');
        }
        helper('tickethub');
        if ($err = th_outbound_url_error($url)) {
            throw new \RuntimeException('the metadata URL was refused: ' . $err);
        }
        $info = \OneLogin\Saml2\IdPMetadataParser::parseRemoteXML(
            $url,
            null,
            null,
            \OneLogin\Saml2\Constants::BINDING_HTTP_REDIRECT,
            \OneLogin\Saml2\Constants::BINDING_HTTP_REDIRECT,
            true // validatePeer — see note above
        );
        $idp = $info['idp'] ?? [];
        if (empty($idp['entityId']) || empty($idp['singleSignOnService']['url']) || empty($idp['x509cert'])) {
            throw new \RuntimeException('the metadata document is missing an entity ID, SSO URL or signing certificate.');
        }

        return $idp;
    }

    /**
     * Process the POST from the IdP at the ACS endpoint.
     *
     * @return array{nameId: string, email: string, name: string, attributes: array}
     *
     * @throws \RuntimeException with a message safe to show the user.
     */
    public static function processAcs(?string $cookieNonce = null, bool $secure = false): array
    {
        if (empty($_POST['SAMLResponse'])) {
            throw new \RuntimeException('no SAML response was posted — start from the sign-in page.');
        }

        // Which sign-in is this the answer to? The RelayState nonce is single
        // use and maps to the AuthnRequest ID we issued; passing that ID makes
        // the toolkit enforce InResponseTo, so a response we never asked for
        // (or one issued to someone else's sign-in) is refused.
        $relay     = (string) ($_POST['RelayState'] ?? '');
        $requestId = null;
        if (preg_match('/^[a-f0-9]{32}$/', $relay)) {
            $requestId = cache('saml_rs_' . $relay) ?: null;
            cache()->delete('saml_rs_' . $relay);
        }
        if ($requestId !== null) {
            // On HTTPS the flow is also bound to the browser that started it,
            // which stops someone posting their own valid response into a
            // victim's browser to sign them in as the attacker.
            if ($secure && ! hash_equals($relay, (string) $cookieNonce)) {
                throw new \RuntimeException('this sign-in was started in a different browser. Start again from the sign-in page.');
            }
        } elseif (Settings::get('saml_allow_idp_initiated') !== '1') {
            throw new \RuntimeException('sign-in must start from this site. Use the SSO button on the sign-in page.');
        }

        $auth = self::auth();

        try {
            $auth->processResponse($requestId);
        } catch (\Throwable $e) {
            log_message('error', 'SAML response could not be processed: {msg}', ['msg' => $e->getMessage()]);

            throw new \RuntimeException('the response could not be read.');
        }

        $errors = $auth->getErrors();
        if ($errors) {
            $reason = $auth->getLastErrorReason();
            log_message('error', 'SAML response rejected: {errors}{reason}', [
                'errors' => implode(', ', $errors),
                'reason' => $reason ? ' — ' . $reason : '',
            ]);

            throw new \RuntimeException('the identity provider\'s response could not be verified.');
        }
        if (! $auth->isAuthenticated()) {
            throw new \RuntimeException('the identity provider did not confirm sign-in.');
        }
        // Replay cache: each signed assertion signs someone in once. Kept until
        // the assertion itself would have expired anyway.
        $assertionId = (string) $auth->getLastAssertionId();
        if ($assertionId !== '') {
            $key = 'saml_aid_' . hash('sha256', $assertionId);
            if (cache($key)) {
                log_message('warning', 'SAML assertion {id} replayed; refused.', ['id' => $assertionId]);

                throw new \RuntimeException('that sign-in response was already used. Start again from the sign-in page.');
            }
            $until = (int) ($auth->getLastAssertionNotOnOrAfter() ?: time() + 300);
            cache()->save($key, 1, max(60, $until - time() + 60));
        }

        $nameId = (string) $auth->getNameId();
        $attrs  = $auth->getAttributes();

        $email = self::pickAttribute($attrs, trim(Settings::get('saml_attr_email')));
        if ($email === '' && filter_var($nameId, FILTER_VALIDATE_EMAIL)) {
            $email = $nameId;
        }
        if ($email === '') {
            // Last resort: anything that looks like an email attribute, by name.
            foreach ($attrs as $k => $v) {
                $first = (string) ($v[0] ?? '');
                if (stripos($k, 'email') !== false && filter_var($first, FILTER_VALIDATE_EMAIL)) {
                    $email = $first;

                    break;
                }
            }
        }
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException(
                'the response carried no usable email address — set the "Email attribute" to match what your '
                . 'identity provider sends, or use the emailAddress NameID format.'
            );
        }

        $name = self::pickAttribute($attrs, trim(Settings::get('saml_attr_name')));
        if ($name === '') {
            foreach (self::NAME_ATTRS as $k) {
                if (! empty($attrs[$k][0])) {
                    $name = (string) $attrs[$k][0];

                    break;
                }
            }
        }

        return ['nameId' => $nameId, 'email' => strtolower($email), 'name' => $name !== '' ? $name : $email, 'attributes' => $attrs];
    }

    private static function pickAttribute(array $attrs, string $name): string
    {
        return $name !== '' && ! empty($attrs[$name][0]) ? (string) $attrs[$name][0] : '';
    }

    /* ---------- role mapping ---------- */

    /** Same contract as Oidc::roleFromClaims() — null when no mapping is configured. */
    public static function roleFromAttributes(array $attrs): ?string
    {
        $adminAttr  = trim(Settings::get('saml_admin_attr'));
        $adminValue = trim(Settings::get('saml_admin_value'));
        $agentAttr  = trim(Settings::get('saml_agent_attr'));
        $agentValue = trim(Settings::get('saml_agent_value'));
        $adminOn = $adminAttr !== '' && $adminValue !== '';
        $agentOn = $agentAttr !== '' && $agentValue !== '';
        if (! $adminOn && ! $agentOn) {
            return null;
        }
        if ($adminOn && self::attributeHas($attrs, $adminAttr, $adminValue)) {
            return 'Administrator';
        }
        if ($agentOn && self::attributeHas($attrs, $agentAttr, $agentValue)) {
            return 'Agent';
        }

        return 'Requester';
    }

    /** True when a (possibly multi-valued) SAML attribute contains the value, case-insensitively. */
    public static function attributeHas(array $attrs, string $name, string $value): bool
    {
        if (! array_key_exists($name, $attrs)) {
            return false;
        }
        $needle = strtolower($value);
        foreach ((array) $attrs[$name] as $v) {
            if (is_scalar($v) && strtolower((string) $v) === $needle) {
                return true;
            }
        }

        return false;
    }
}
