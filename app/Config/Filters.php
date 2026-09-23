<?php

namespace Config;

use CodeIgniter\Config\Filters as BaseFilters;
use CodeIgniter\Filters\Cors;
use CodeIgniter\Filters\CSRF;
use CodeIgniter\Filters\DebugToolbar;
use CodeIgniter\Filters\ForceHTTPS;
use CodeIgniter\Filters\Honeypot;
use CodeIgniter\Filters\InvalidChars;
use CodeIgniter\Filters\PageCache;
use CodeIgniter\Filters\PerformanceMetrics;
use CodeIgniter\Filters\SecureHeaders;

class Filters extends BaseFilters
{
    /**
     * Configures aliases for Filter classes to
     * make reading things nicer and simpler.
     *
     * @var array<string, class-string|list<class-string>>
     *
     * [filter_name => classname]
     * or [filter_name => [classname1, classname2, ...]]
     */
    public array $aliases = [
        'agentAuth'     => \App\Filters\AgentAuth::class,
        'portalAuth'    => \App\Filters\PortalAuth::class,
        'adminAuth'     => \App\Filters\AdminAuth::class,
        'apiAuth'       => \App\Filters\ApiAuth::class,
        'mustChangePw'  => \App\Filters\MustChangePassword::class,
        'sessionEpoch'  => \App\Filters\SessionEpoch::class,
        'locale'        => \App\Filters\Locale::class,
        'mfaRequired'   => \App\Filters\MfaRequired::class,
        'appHeaders'    => \App\Filters\SecurityHeaders::class,
        'csrf'          => CSRF::class,
        'toolbar'       => DebugToolbar::class,
        'honeypot'      => Honeypot::class,
        'invalidchars'  => InvalidChars::class,
        'secureheaders' => SecureHeaders::class,
        'cors'          => Cors::class,
        'forcehttps'    => ForceHTTPS::class,
        'pagecache'     => PageCache::class,
        'performance'   => PerformanceMetrics::class,
    ];

    /**
     * List of special required filters.
     *
     * The filters listed here are special. They are applied before and after
     * other kinds of filters, and always applied even if a route does not exist.
     *
     * Filters set by default provide framework functionality. If removed,
     * those functions will no longer work.
     *
     * @see https://codeigniter.com/user_guide/incoming/filters.html#provided-filters
     *
     * @var array{before: list<string>, after: list<string>}
     */
    public array $required = [
        'before' => [
            'forcehttps', // Force Global Secure Requests
            'pagecache',  // Web Page Caching
        ],
        'after' => [
            'pagecache',   // Web Page Caching
            'performance', // Performance Metrics
            'toolbar',     // Debug Toolbar
        ],
    ];

    /**
     * List of filter aliases that are always
     * applied before and after every request.
     *
     * @var array{
     *     before: array<string, array{except: list<string>|string}>|list<string>,
     *     after: array<string, array{except: list<string>|string}>|list<string>
     * }
     */
    public array $globals = [
        'before' => [
            // 'honeypot',
            'locale' => ['except' => ['api/*']],
            // auth/saml/acs: the POST is from the IdP, not a form of ours — it
            // carries no CSRF token and cannot be made to (the signed SAML
            // response itself is what proves the request is genuine).
            'csrf' => ['except' => ['api/*', 'auth/saml/acs']],
            // A password change/reset bumps users.session_epoch; sessions minted
            // before that are signed out on their next request.
            'sessionEpoch' => ['except' => ['api/*']],
            'mustChangePw' => ['except' => ['login', 'logout', 'forgot', 'reset/*', 'auth/*', 'api/*', 'assets/*']],
            // Admin policy mfa_required_roles: users in scope must enrol an authenticator first.
            'mfaRequired' => ['except' => ['login', 'login/*', 'logout', 'forgot', 'reset/*', 'auth/*', 'api/*', 'assets/*', 'files/*', 'account/security', 'account/security/*']],
            // 'invalidchars',
        ],
        'after' => [
            // 'honeypot',
            'secureheaders',
            // CSP, HSTS (production + HTTPS), Permissions-Policy — see App\Filters\SecurityHeaders.
            'appHeaders',
        ],
    ];

    /**
     * List of filter aliases that works on a
     * particular HTTP method (GET, POST, etc.).
     *
     * Example:
     * 'POST' => ['foo', 'bar']
     *
     * If you use this, you should disable auto-routing because auto-routing
     * permits any HTTP method to access a controller. Accessing the controller
     * with a method you don't expect could bypass the filter.
     *
     * @var array<string, list<string>>
     */
    public array $methods = [];

    /**
     * List of filter aliases that should run on any
     * before or after URI patterns.
     *
     * Example:
     * 'isLoggedIn' => ['before' => ['account/*', 'profiles/*']]
     *
     * @var array<string, array<string, list<string>>>
     */
    public array $filters = [];
}
