<?php
declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
define('APP_PATH', ROOT_PATH . '/app');
define('STORAGE_PATH', ROOT_PATH . '/storage');

// PSR-4-ish autoloader: App\Services\Foo → app/services/Foo.php
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $parts = explode('\\', substr($class, 4));
    $file = array_pop($parts);
    $dir = strtolower(implode('/', $parts));
    $path = APP_PATH . '/' . ($dir !== '' ? $dir . '/' : '') . $file . '.php';
    if (is_file($path)) {
        require $path;
    }
});

require APP_PATH . '/helpers.php';

$configFile = ROOT_PATH . '/config/config.php';
if (!is_file($configFile)) {
    http_response_code(503);
    exit('Application is not configured. Copy config/config.example.php to config/config.php.');
}
$GLOBALS['__config'] = require $configFile;

date_default_timezone_set('UTC');
App\Core\ErrorHandler::register();

if (PHP_SAPI !== 'cli') {
    $secure = (bool) config('session.secure', true);
    session_name((string) config('session.name', 'bank_session'));
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_start();

    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; frame-ancestors 'none'; form-action 'self'");
    if ($secure) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}
