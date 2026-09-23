<?php

/**
 * Identity module routes: two-factor sign-in, generic OIDC, account security
 * and notification preferences, and the admin Identity tab actions.
 *
 * @var \CodeIgniter\Router\RouteCollection $routes
 */

// Second sign-in step (no session user yet — the pending user lives in the session).
$routes->get('login/2fa', 'AuthController::twoFactor');
$routes->post('login/2fa', 'AuthController::twoFactorVerify');

// Generic OpenID Connect (Google preset, Okta, Keycloak, …).
$routes->get('auth/oidc', 'AuthController::oidc');
$routes->get('auth/oidc/callback', 'AuthController::oidcCallback');

// SAML 2.0. The ACS is CSRF-exempt (Config/Filters.php) — the POST comes from
// the IdP, not one of our own forms. Metadata is public so an IdP can fetch it.
$routes->get('auth/saml', 'AuthController::saml');
$routes->post('auth/saml/acs', 'AuthController::samlAcs');
$routes->get('auth/saml/metadata', 'AuthController::samlMetadata');

// Any signed-in user: own security + notification preferences.
$routes->group('', ['filter' => 'portalAuth'], static function ($routes) {
    $routes->get('account/security', 'AccountController::security');
    $routes->post('account/security/enrol', 'AccountController::securityEnrol');
    $routes->post('account/security/enable', 'AccountController::securityEnable');
    $routes->post('account/security/recovery', 'AccountController::securityRecovery');
    $routes->post('account/security/disable', 'AccountController::securityDisable');
    $routes->get('account/notifications', 'AccountController::notifications');
    $routes->post('account/notifications', 'AccountController::saveNotifications');
});

// Admin → Identity tab (the GET is served by AdminController::index/identity as a plug-in tab).
$routes->group('app', ['filter' => 'adminAuth'], static function ($routes) {
    $routes->post('admin/identity/mfa', 'Admin\IdentityController::saveMfa');
    $routes->post('admin/identity/sso-required', 'Admin\IdentityController::saveSsoPolicy');
    $routes->post('admin/identity/users/(:num)/reset-2fa', 'Admin\IdentityController::resetTwoFactor/$1');
    $routes->post('admin/identity/oidc', 'Admin\IdentityController::saveOidc');
    $routes->post('admin/identity/oidc/test', 'Admin\IdentityController::testOidc');
    $routes->post('admin/identity/ldap', 'Admin\IdentityController::saveLdap');
    $routes->post('admin/identity/ldap/test', 'Admin\IdentityController::testLdap');
    $routes->post('admin/identity/saml', 'Admin\IdentityController::saveSaml');
    $routes->post('admin/identity/saml/fetch-metadata', 'Admin\IdentityController::fetchSamlMetadata');
});
