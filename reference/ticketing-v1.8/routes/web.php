<?php
declare(strict_types=1);

use App\Core\Router;

$router = new Router();

// Dashboard
$router->add('', ['controller' => 'Dashboard', 'action' => 'index']);
$router->add('dashboard', ['controller' => 'Dashboard', 'action' => 'index']);

// Tickets
$router->add('tickets', ['controller' => 'Ticket', 'action' => 'index']);
$router->add('tickets/create', ['controller' => 'Ticket', 'action' => 'create']);
$router->add('tickets/{id}', ['controller' => 'Ticket', 'action' => 'show']);
$router->add('tickets/{id}/reply', ['controller' => 'Ticket', 'action' => 'reply']);

// Users
$router->add('users', ['controller' => 'User', 'action' => 'index']);
$router->add('users/create', ['controller' => 'User', 'action' => 'create']);
$router->add('users/{id}', ['controller' => 'User', 'action' => 'show']);

// Settings
$router->add('settings', ['controller' => 'Settings', 'action' => 'index']);
$router->add('settings/general', ['controller' => 'Settings', 'action' => 'general']);

// Reports
$router->add('reports', ['controller' => 'Report', 'action' => 'index']);
$router->add('reports/tickets', ['controller' => 'Report', 'action' => 'tickets']);

// API
$router->add('api/tickets', ['controller' => 'Api\Ticket', 'action' => 'index']);
$router->add('api/tickets/{id}', ['controller' => 'Api\Ticket', 'action' => 'show']);

return $router;
