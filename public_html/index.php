<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$router = new App\Core\Router();
require APP_PATH . '/routes.php';
$router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/');
