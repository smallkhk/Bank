<?php
declare(strict_types=1);

namespace App\Core;

use Throwable;

/** Logs technical errors privately and never shows raw PHP/DB errors to users. */
final class ErrorHandler
{
    public static function register(): void
    {
        error_reporting(E_ALL);
        ini_set('display_errors', config('app.debug') ? '1' : '0');
        ini_set('log_errors', '1');
        ini_set('error_log', STORAGE_PATH . '/logs/php-error.log');

        set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
            if (!(error_reporting() & $no)) {
                return false;
            }
            if (in_array($no, [E_DEPRECATED, E_USER_DEPRECATED], true)) {
                return false; // log via PHP's error_log, never break a page
            }
            throw new \ErrorException($str, 0, $no, $file, $line);
        });
        set_exception_handler([self::class, 'handle']);
    }

    public static function log(Throwable $e): string
    {
        $id = strtoupper(bin2hex(random_bytes(4)));
        $line = sprintf(
            "[%s] #%s %s: %s in %s:%d\n%s\nURI: %s\n\n",
            gmdate('Y-m-d H:i:s'), $id, $e::class, $e->getMessage(), $e->getFile(), $e->getLine(),
            $e->getTraceAsString(), $_SERVER['REQUEST_URI'] ?? 'cli'
        );
        @file_put_contents(STORAGE_PATH . '/logs/app-' . gmdate('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
        return $id;
    }

    public static function handle(Throwable $e): void
    {
        $id = self::log($e);
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, $e . PHP_EOL);
            exit(1);
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code(500);
        try {
            View::render('errors/message', [
                'title'   => 'Something went wrong',
                'message' => 'Something went wrong. Please try again or contact support.',
                'errorId' => $id,
                'debug'   => config('app.debug') ? (string) $e : null,
            ], 'public');
        } catch (Throwable) {
            echo 'Something went wrong. Please try again or contact support. (Ref ' . $id . ')';
        }
    }
}
