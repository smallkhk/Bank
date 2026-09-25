<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Middleware;
use App\Core\View;
use App\Services\BankingException;

abstract class Controller
{
    protected function view(string $view, array $data = [], string $layout = 'app'): void
    {
        View::render($view, $data, $layout);
    }

    protected function forbidden(): never
    {
        Middleware::forbidden();
    }

    protected function notFound(): never
    {
        http_response_code(404);
        View::render('errors/404', ['title' => 'Not found'], Auth::check() ? 'app' : 'public');
        exit;
    }

    /**
     * Run a banking action; on a business-rule failure flash its (safe) message and go back.
     */
    protected function attempt(callable $fn, string $backTo): mixed
    {
        try {
            return $fn();
        } catch (BankingException $e) {
            remember_input();
            flash('error', $e->getMessage());
            redirect($backTo);
        }
    }

    protected function requireReason(string $backTo, string $field = 'reason'): string
    {
        $reason = input($field);
        if ($reason === '') {
            flash('error', 'Please provide a reason.');
            redirect($backTo);
        }
        return mb_substr($reason, 0, 255);
    }

    protected static function validatePassword(string $password): ?string
    {
        $min = (int) setting('password_min_length', '10');
        if (strlen($password) < $min) {
            return "Password must be at least $min characters.";
        }
        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            return 'Password must contain letters and numbers.';
        }
        return null;
    }
}
