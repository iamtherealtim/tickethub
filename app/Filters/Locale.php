<?php

namespace App\Filters;

use App\Libraries\Settings;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * Picks the locale for this request, in order of preference:
 *   1. ?lang=xx on the URL (stored in the session, then stripped with a redirect)
 *   2. the session's saved 'locale'
 *   3. the Settings key app_locale (site default, set on Admin → General)
 *   4. Accept-Language negotiation (already done by IncomingRequest when
 *      Config\App::$negotiateLocale is true), else Config\App::$defaultLocale.
 *
 * Register in app/Config/Filters.php:
 *   $aliases['locale'] = \App\Filters\Locale::class;
 *   $globals['before'][] = 'locale';   // or 'locale' => ['except' => ['api/*']]
 */
class Locale implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $supported = config('App')->supportedLocales;

        // 1. Explicit choice on the URL.
        $chosen = $request->getGet('lang');
        if (is_string($chosen) && in_array($chosen, $supported, true)) {
            session()->set('locale', $chosen);
            self::apply($request, $chosen);

            // Drop the parameter so it does not stick to pagination/filter links.
            if ($request->getMethod() === 'GET' || $request->getMethod() === 'get') {
                $uri = clone $request->getUri();
                $uri->stripQuery('lang');

                return redirect()->to((string) $uri);
            }

            return null;
        }

        // 2. Session.
        $locale = session()->get('locale');

        // 3. Site default from Settings (guarded: the DB may be unavailable on early requests).
        if (! is_string($locale) || ! in_array($locale, $supported, true)) {
            try {
                $locale = Settings::get('app_locale', '');
            } catch (Throwable) {
                $locale = '';
            }
        }

        // 4. Negotiated / default. IncomingRequest already applied Accept-Language.
        if (! is_string($locale) || ! in_array($locale, $supported, true)) {
            $locale = $request->getLocale();
        }

        self::apply($request, $locale);

        return null;
    }

    /** Set the locale on the request and on the (possibly already instantiated) language service. */
    private static function apply(RequestInterface $request, string $locale): void
    {
        $request->setLocale($locale);
        service('language')->setLocale($request->getLocale());
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}
