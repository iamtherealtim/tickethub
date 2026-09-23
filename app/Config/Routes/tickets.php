<?php

/**
 * Ticket module routes: canned responses, Markdown editor endpoints, ticket
 * templates, recurring schedules and CSAT-by-email. Included by Routes.php
 * with $routes in scope.
 */

/** @var \CodeIgniter\Router\RouteCollection $routes */
$routes->group('app', ['filter' => 'agentAuth'], static function ($routes) {
    // Canned responses for the reply box (global + my group + mine).
    $routes->get('canned.json', 'TicketsController::cannedJson');
    $routes->post('canned', 'TicketsController::cannedCreate');
    $routes->post('canned/(:num)/delete', 'TicketsController::cannedDelete/$1');

    // Markdown editor: live preview, pasted/dropped images.
    $routes->post('tickets/preview', 'TicketsController::preview');
    $routes->post('tickets/(:segment)/inline-image', 'TicketsController::inlineImage/$1');

    // "Save as template" from a ticket page (Supervisor+).
    $routes->post('tickets/(:segment)/save-template', 'TicketsController::saveAsTemplate/$1');
});

// Admin: canned responses (global/group) and ticket templates + recurring.
$routes->group('app', ['filter' => 'adminAuth'], static function ($routes) {
    $routes->post('admin/canned', 'Admin\CannedController::create');
    $routes->post('admin/canned/(:num)', 'Admin\CannedController::update/$1');
    $routes->post('admin/canned/(:num)/delete', 'Admin\CannedController::delete/$1');

    $routes->post('admin/ticket-templates', 'Admin\TemplatesController::create');
    $routes->post('admin/ticket-templates/(:num)', 'Admin\TemplatesController::update/$1');
    $routes->post('admin/ticket-templates/(:num)/delete', 'Admin\TemplatesController::delete/$1');
    $routes->post('admin/recurring', 'Admin\TemplatesController::createRecurring');
    $routes->post('admin/recurring/(:num)', 'Admin\TemplatesController::updateRecurring/$1');
    $routes->post('admin/recurring/(:num)/toggle', 'Admin\TemplatesController::toggleRecurring/$1');
    $routes->post('admin/recurring/(:num)/delete', 'Admin\TemplatesController::deleteRecurring/$1');
});

// Portal: the same editor endpoints for the requester's reply box.
$routes->group('portal', ['filter' => 'portalAuth'], static function ($routes) {
    $routes->post('preview', 'PortalController::preview');
    $routes->post('tickets/(:segment)/inline-image', 'PortalController::inlineImage/$1');
});

// CSAT from the resolution email: token-authenticated, no sign-in needed.
$routes->get('portal/rate/(:segment)/(:num)', 'PortalController::rateByToken/$1/$2');
$routes->post('portal/rate/(:segment)/submit', 'PortalController::rateSubmit/$1');
$routes->post('portal/rate/(:segment)', 'PortalController::rateComment/$1');
