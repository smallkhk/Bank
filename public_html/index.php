<?php
declare(strict_types=1);

// The app lives one level above public_html (recommended), or in a "bank" folder beside it.
$appRoot = is_file(__DIR__ . '/../app/bootstrap.php') ? __DIR__ . '/..' : __DIR__ . '/../bank';
if (!is_file($appRoot . '/config/config.php') && is_file(__DIR__ . '/install.php')) {
    header('Location: install.php');
    exit;
}
require $appRoot . '/app/bootstrap.php';

$router = new App\Core\Router();
require APP_PATH . '/routes.php';
$router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/');
