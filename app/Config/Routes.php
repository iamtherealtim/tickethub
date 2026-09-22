<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'AuthController::index');
$routes->get('login', 'AuthController::login');
$routes->post('login', 'AuthController::attempt');
$routes->get('logout', 'AuthController::logoutConfirm');
$routes->post('logout', 'AuthController::logout');
$routes->get('auth/azure', 'AuthController::azure');
$routes->get('auth/azure/callback', 'AuthController::azureCallback');
$routes->get('forgot', 'AuthController::forgot');
$routes->post('forgot', 'AuthController::sendReset');
$routes->get('reset/(:segment)', 'AuthController::reset/$1');
$routes->post('reset/(:segment)', 'AuthController::doReset/$1');

// Inbound email webhook (secret-authenticated, CSRF-exempt via Filters config).
$routes->post('api/inbound-email', 'Api\InboundEmailController::receive');

// JSON API — bearer token bound to a user, so scoping and audit come for free.
$routes->group('api', ['filter' => 'apiAuth'], static function ($routes) {
    $routes->get('tickets', 'Api\TicketsApiController::index');
    $routes->post('tickets', 'Api\TicketsApiController::create');
    $routes->get('tickets/(:segment)', 'Api\TicketsApiController::show/$1');
    $routes->post('tickets/(:segment)/reply', 'Api\TicketsApiController::reply/$1');
});

// Any signed-in user: file downloads + own account.
$routes->group('', ['filter' => 'portalAuth'], static function ($routes) {
    $routes->get('files/(:segment)', 'FilesController::serve/$1');
    $routes->get('account/new-password', 'AccountController::newPassword');
    $routes->post('account/password', 'AccountController::password');
});

$routes->group('app', ['filter' => 'agentAuth'], static function ($routes) {
    $routes->get('dashboard', 'DashboardController::index');

    $routes->get('tickets', 'TicketsController::index');
    $routes->get('tickets/export', 'TicketsController::export');
    $routes->post('tickets', 'TicketsController::create');
    $routes->post('tickets/bulk', 'TicketsController::bulk');
    $routes->post('tickets/views', 'TicketsController::saveView');
    $routes->post('tickets/views/(:num)/delete', 'TicketsController::deleteView/$1');
    $routes->get('tickets/(:segment)', 'TicketsController::show/$1');
    $routes->post('tickets/(:segment)/message', 'TicketsController::message/$1');
    $routes->post('tickets/(:segment)/update', 'TicketsController::update/$1');
    $routes->post('tickets/(:segment)/resolve', 'TicketsController::resolve/$1');
    $routes->post('tickets/(:segment)/reopen', 'TicketsController::reopen/$1');
    $routes->post('tickets/(:segment)/escalate', 'TicketsController::escalate/$1');
    $routes->post('tickets/(:segment)/delete', 'TicketsController::delete/$1');
    $routes->get('tickets/(:segment)/merge-search', 'TicketsController::mergeSearch/$1');
    $routes->post('tickets/(:segment)/merge', 'TicketsController::merge/$1');
    $routes->post('tickets/(:segment)/approve', 'TicketsController::approveRequest/$1');
    $routes->post('tickets/(:segment)/reject', 'TicketsController::rejectRequest/$1');
    $routes->post('tickets/(:segment)/claim', 'TicketsController::claim/$1');
    $routes->post('tickets/(:segment)/tasks', 'TicketsController::addTask/$1');
    $routes->post('tickets/(:segment)/tasks/(:num)/toggle', 'TicketsController::toggleTask/$1/$2');
    $routes->post('tickets/(:segment)/links', 'TicketsController::linkTicket/$1');
    $routes->post('tickets/(:segment)/links/(:num)/remove', 'TicketsController::unlinkTicket/$1/$2');
    $routes->post('tickets/(:segment)/time', 'TicketsController::logTime/$1');
    $routes->post('tickets/(:segment)/time/(:num)/delete', 'TicketsController::deleteTime/$1/$2');
    $routes->post('tickets/(:segment)/watchers', 'TicketsController::addWatcher/$1');
    $routes->post('tickets/(:segment)/watchers/(:num)/remove', 'TicketsController::removeWatcher/$1/$2');
    $routes->post('tickets/(:segment)/tags', 'TicketsController::addTag/$1');
    $routes->post('tickets/(:segment)/tags/remove', 'TicketsController::removeTag/$1');
    $routes->post('tickets/(:segment)/tasks/(:num)/delete', 'TicketsController::deleteTask/$1/$2');
    $routes->post('tickets/(:segment)/fields', 'TicketsController::fields/$1');
    $routes->post('tickets/(:segment)/assets', 'TicketsController::linkAsset/$1');

    $routes->get('problems', 'ProblemsController::index');
    $routes->post('problems', 'ProblemsController::create');
    $routes->get('problems/(:num)', 'ProblemsController::show/$1');
    $routes->post('problems/(:num)', 'ProblemsController::update/$1');
    $routes->post('problems/(:num)/delete', 'ProblemsController::delete/$1');
    $routes->get('problems/(:num)/link-search', 'ProblemsController::linkSearch/$1');
    $routes->post('problems/(:num)/link', 'ProblemsController::link/$1');
    $routes->post('problems/(:num)/unlink/(:num)', 'ProblemsController::unlink/$1/$2');

    $routes->get('changes', 'ChangesController::index');
    $routes->get('changes/(:num)', 'ChangesController::show/$1');
    $routes->post('changes/(:num)/link', 'ChangesController::link/$1');
    $routes->post('changes/(:num)/unlink/(:num)', 'ChangesController::unlink/$1/$2');
    $routes->post('changes', 'ChangesController::create');
    $routes->post('changes/(:num)/approve', 'ChangesController::approve/$1');
    $routes->post('changes/(:num)/reject', 'ChangesController::reject/$1');
    $routes->post('changes/(:num)/state', 'ChangesController::state/$1');
    $routes->post('changes/(:num)', 'ChangesController::update/$1');
    $routes->post('changes/(:num)/delete', 'ChangesController::delete/$1');

    $routes->get('assets', 'AssetsController::index');
    $routes->get('assets/import', 'AssetsController::importForm');
    $routes->post('assets/import', 'AssetsController::import');
    $routes->post('assets', 'AssetsController::create');
    $routes->get('assets/(:num)', 'AssetsController::show/$1');
    $routes->get('assets/(:num)/modal', 'AssetsController::modal/$1');
    $routes->get('assets/(:num)/edit', 'AssetsController::editModal/$1');
    $routes->get('assets/(:num)/assign-search', 'AssetsController::assignSearch/$1');
    $routes->post('assets/(:num)/assign', 'AssetsController::assign/$1');
    $routes->post('assets/(:num)', 'AssetsController::update/$1');
    $routes->post('assets/(:num)/delete', 'AssetsController::delete/$1');
    $routes->post('assets/(:num)/ticket', 'AssetsController::raiseTicket/$1');

    $routes->get('catalog', 'CatalogController::index');
    $routes->post('catalog', 'CatalogController::create');
    $routes->post('catalog/(:num)/request', 'CatalogController::request/$1');
    $routes->post('catalog/(:num)/update', 'CatalogController::update/$1');
    $routes->post('catalog/(:num)/delete', 'CatalogController::delete/$1');

    $routes->get('kb', 'KnowledgeController::index');
    $routes->get('kb/suggest', 'KnowledgeController::suggest');
    $routes->post('kb', 'KnowledgeController::create');
    $routes->get('kb/(:num)', 'KnowledgeController::article/$1');
    $routes->post('kb/(:num)/vote', 'KnowledgeController::vote/$1');
    $routes->post('kb/(:num)/update', 'KnowledgeController::update/$1');
    $routes->post('kb/(:num)/delete', 'KnowledgeController::delete/$1');

    $routes->get('reports', 'ReportsController::index');
    $routes->get('reports/export', 'ReportsController::export');

    $routes->post('announcements', 'AnnouncementsController::create');
    $routes->post('announcements/(:num)', 'AnnouncementsController::update/$1');
    $routes->post('announcements/(:num)/delete', 'AnnouncementsController::delete/$1');

    $routes->get('search', 'SearchController::palette');
    $routes->get('users/(:num)/history', 'SearchController::requesterHistory/$1');
    $routes->get('notifications', 'SearchController::notifications');
    $routes->post('notifications/read', 'SearchController::readAllNotifications');
    $routes->post('notifications/(:num)/read', 'SearchController::readNotification/$1');
});

// Admin area — Administrators only.
$routes->group('app', ['filter' => 'adminAuth'], static function ($routes) {
    $routes->get('admin', 'AdminController::index');
    $routes->get('admin/(:segment)', 'AdminController::index/$1');
    $routes->post('admin/agents', 'AdminController::addAgent');
    $routes->post('admin/agents/(:num)', 'AdminController::updateAgent/$1');
    $routes->post('admin/people', 'AdminController::addPerson');
    $routes->post('admin/people/(:num)', 'AdminController::updatePerson/$1');
    $routes->post('admin/groups', 'AdminController::addGroup');
    $routes->post('admin/groups/(:num)', 'AdminController::updateGroup/$1');
    $routes->post('admin/groups/(:num)/delete', 'AdminController::deleteGroup/$1');
    $routes->post('admin/slas', 'AdminController::addSla');
    $routes->post('admin/slas/(:num)', 'AdminController::updateSla/$1');
    $routes->post('admin/slas/(:num)/delete', 'AdminController::deleteSla/$1');
    $routes->post('admin/rules', 'AdminController::addRule');
    $routes->post('admin/rules/(:num)', 'AdminController::updateRule/$1');
    $routes->post('admin/rules/(:num)/delete', 'AdminController::deleteRule/$1');
    $routes->post('admin/rules/run', 'AdminController::runRules');
    $routes->post('admin/fields', 'AdminController::addField');
    $routes->post('admin/fields/(:num)', 'AdminController::updateField/$1');
    $routes->post('admin/fields/(:num)/delete', 'AdminController::deleteField/$1');
    $routes->post('admin/routing', 'AdminController::addRoute');
    $routes->post('admin/routing/default', 'AdminController::saveDefaultGroup');
    $routes->post('admin/routing/(:num)', 'AdminController::updateRoute/$1');
    $routes->post('admin/routing/(:num)/delete', 'AdminController::deleteRoute/$1');
    $routes->post('admin/hours', 'AdminController::addHours');
    $routes->post('admin/hours/(:num)', 'AdminController::updateHours/$1');
    $routes->post('admin/templates/(:num)', 'AdminController::updateTemplate/$1');
    $routes->post('admin/mail', 'AdminController::saveMail');
    $routes->post('admin/mail/test', 'AdminController::testMail');
    $routes->post('admin/inbound', 'AdminController::saveInbound');
    $routes->post('admin/graph', 'AdminController::saveGraph');
    $routes->post('admin/graph/fetch', 'AdminController::fetchGraph');
    $routes->post('admin/tokens', 'AdminController::createToken');
    $routes->post('admin/tokens/(:num)/revoke', 'AdminController::revokeToken/$1');
    $routes->post('admin/pdq', 'AdminController::savePdq');
    $routes->post('admin/pdq/test', 'AdminController::testPdq');
    $routes->post('admin/pdq/sync', 'AdminController::syncPdq');
    $routes->post('admin/sso', 'AdminController::saveSso');
    $routes->post('admin/timezone', 'AdminController::saveTimezone');
    $routes->post('admin/locale', 'AdminController::saveLocale');
    $routes->post('admin/toggle/(:segment)/(:num)', 'AdminController::toggle/$1/$2');
});

$routes->group('portal', ['filter' => 'portalAuth'], static function ($routes) {
    $routes->get('/', 'PortalController::home');
    $routes->get('catalog', 'PortalController::catalog');
    $routes->post('catalog/(:num)/request', 'PortalController::requestItem/$1');
    $routes->get('kb', 'PortalController::kb');
    $routes->get('kb/suggest', 'PortalController::kbSuggest');
    $routes->get('kb/(:num)', 'PortalController::article/$1');
    $routes->post('kb/(:num)/vote', 'PortalController::vote/$1');
    $routes->get('new', 'PortalController::newTicket');
    $routes->post('new', 'PortalController::submitTicket');
    $routes->get('tickets', 'PortalController::myTickets');
    $routes->get('tickets/(:segment)', 'PortalController::ticket/$1');
    $routes->post('tickets/(:segment)/reply', 'PortalController::reply/$1');
    $routes->post('tickets/(:segment)/rate', 'PortalController::rate/$1');
    $routes->get('search', 'PortalController::search');
});

// Feature route files — each module registers its own routes in app/Config/Routes/<module>.php
// with $routes in scope, so modules can be added without editing this file.
foreach (glob(APPPATH . 'Config/Routes/*.php') ?: [] as $routeFile) {
    require $routeFile;
}
