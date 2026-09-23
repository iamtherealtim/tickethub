<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Content-Security-Policy and HSTS on every response.
 *
 * The CSP is a pragmatic one: the UI is server-rendered with inline scripts
 * and inline style attributes, so 'unsafe-inline' stays for scripts and
 * styles. It still restricts where script and style code may be loaded FROM
 * (this site, Google Fonts and cdnjs), forbids plugins, pins <base>, limits form posts to this site and
 * stops the app being framed elsewhere — all things a stored-HTML bug would
 * otherwise get for free. Output escaping and the HTML sanitizer remain the
 * primary XSS defence; this is the second line.
 *
 * HSTS: CodeIgniter's force_https() only sends Strict-Transport-Security on
 * the http→https redirect, which browsers ignore (HSTS over plain HTTP is
 * discarded), so production never actually got it. Sent here on HTTPS
 * responses in production.
 */
class SecurityHeaders implements FilterInterface
{
    private const CSP = "default-src 'self'; "
        . "script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; "
        . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com; "
        . "font-src 'self' https://fonts.gstatic.com data:; "
        . "img-src 'self' data: blob: https:; "
        . "connect-src 'self'; "
        . "object-src 'none'; "
        . "base-uri 'self'; "
        . "form-action 'self'; "
        . "frame-ancestors 'self'";

    public function before(RequestInterface $request, $arguments = null)
    {
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        if (! $response->hasHeader('Content-Security-Policy')) {
            $response->setHeader('Content-Security-Policy', self::CSP);
        }
        if (ENVIRONMENT === 'production' && $request instanceof \CodeIgniter\HTTP\IncomingRequest && $request->isSecure()) {
            $response->setHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
        $response->setHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');

        return $response;
    }
}
