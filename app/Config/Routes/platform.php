<?php

/**
 * Platform routes: organizations, outbound webhooks, extended JSON API,
 * OpenAPI docs and data-lifecycle admin actions. Auto-included by Routes.php.
 *
 * @var \CodeIgniter\Router\RouteCollection $routes
 */

// Public: the API description and a Swagger UI page for it (no auth).
$routes->get('api/openapi.json', 'Api\DocsApiController::spec');
$routes->get('api/docs', 'Api\DocsApiController::docs');

$routes->group('api', ['filter' => 'apiAuth'], static function ($routes) {
    $routes->get('me', 'Api\MeApiController::show');

    $routes->patch('tickets/(:segment)', 'Api\TicketsApiController::update/$1');
    $routes->get('tickets/(:segment)/messages', 'Api\TicketsApiController::messages/$1');

    $routes->get('users', 'Api\UsersApiController::index');
    $routes->post('users', 'Api\UsersApiController::create');
    $routes->get('users/(:num)', 'Api\UsersApiController::show/$1');
    $routes->patch('users/(:num)', 'Api\UsersApiController::update/$1');

    $routes->get('organizations', 'Api\OrganizationsApiController::index');
    $routes->post('organizations', 'Api\OrganizationsApiController::create');
    $routes->get('organizations/(:num)', 'Api\OrganizationsApiController::show/$1');
    $routes->patch('organizations/(:num)', 'Api\OrganizationsApiController::update/$1');

    $routes->get('kb/articles', 'Api\ArticlesApiController::index');
    $routes->post('kb/articles', 'Api\ArticlesApiController::create');
    $routes->get('kb/articles/(:num)', 'Api\ArticlesApiController::show/$1');
    $routes->patch('kb/articles/(:num)', 'Api\ArticlesApiController::update/$1');

    $routes->get('assets', 'Api\AssetsApiController::index');
    $routes->post('assets', 'Api\AssetsApiController::create');
    $routes->get('assets/(:num)', 'Api\AssetsApiController::show/$1');
    $routes->patch('assets/(:num)', 'Api\AssetsApiController::update/$1');

    $routes->get('changes', 'Api\ChangesApiController::index');
    $routes->post('changes', 'Api\ChangesApiController::create');
    $routes->get('changes/(:num)', 'Api\ChangesApiController::show/$1');
    $routes->post('changes/(:num)/approve', 'Api\ChangesApiController::approve/$1');
    $routes->post('changes/(:num)/reject', 'Api\ChangesApiController::reject/$1');

    $routes->get('problems', 'Api\ProblemsApiController::index');
    $routes->post('problems', 'Api\ProblemsApiController::create');
    $routes->get('problems/(:num)', 'Api\ProblemsApiController::show/$1');
});

// Admin: organizations, webhooks, per-person data export/anonymize, retention settings.
$routes->group('app', ['filter' => 'adminAuth'], static function ($routes) {
    $routes->post('admin/orgs', 'Admin\OrgsController::create');
    $routes->post('admin/orgs/(:num)', 'Admin\OrgsController::update/$1');
    $routes->post('admin/orgs/(:num)/delete', 'Admin\OrgsController::delete/$1');

    $routes->post('admin/webhooks', 'Admin\WebhooksController::create');
    $routes->post('admin/webhooks/(:num)', 'Admin\WebhooksController::update/$1');
    $routes->post('admin/webhooks/(:num)/delete', 'Admin\WebhooksController::delete/$1');
    $routes->post('admin/webhooks/(:num)/toggle', 'Admin\WebhooksController::toggle/$1');
    $routes->post('admin/webhooks/(:num)/test', 'Admin\WebhooksController::test/$1');
    $routes->post('admin/webhooks/deliveries/(:num)/retry', 'Admin\WebhooksController::retry/$1');

    $routes->get('admin/people/(:num)/export', 'AdminController::exportPerson/$1');
    $routes->post('admin/people/(:num)/anonymize', 'AdminController::anonymizePerson/$1');

    $routes->post('admin/data', 'Admin\DataController::save');
});
