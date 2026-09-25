<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Route middleware. Names:
 *   auth            — any logged-in user
 *   guest           — only anonymous visitors
 *   customer        — logged-in customer
 *   staff           — logged-in staff member
 *   perm:<slug>     — staff member holding the permission
 */
final class Middleware
{
    public static function run(string $name): void
    {
        [$kind, $arg] = array_pad(explode(':', $name, 2), 2, null);
        switch ($kind) {
            case 'guest':
                if (Auth::check()) {
                    redirect(Auth::isStaff() ? '/admin' : '/dashboard');
                }
                return;
            case 'auth':
                self::requireLogin();
                return;
            case 'customer':
                self::requireLogin();
                if (!Auth::isCustomer()) {
                    self::forbidden();
                }
                return;
            case 'staff':
                self::requireLogin();
                if (!Auth::isStaff()) {
                    self::forbidden();
                }
                self::requireStaff2fa();
                return;
            case 'perm':
                self::requireLogin();
                if (!Auth::isStaff() || !Auth::can((string) $arg)) {
                    self::forbidden();
                }
                self::requireStaff2fa();
                return;
        }
        throw new \LogicException("Unknown middleware $name");
    }

    /** When enforced by settings, staff must enrol in 2FA before using the back office. */
    private static function requireStaff2fa(): void
    {
        if (setting('require_2fa_staff') === '1' && !Auth::user()['twofa_enabled_at']) {
            flash('error', 'Two-factor authentication is required for staff. Please set it up to continue.');
            redirect('/profile/2fa');
        }
    }

    private static function requireLogin(): void
    {
        if (!Auth::check()) {
            $_SESSION['intended'] = $_SERVER['REQUEST_URI'] ?? null;
            flash('error', 'Please sign in to continue.');
            redirect('/login');
        }
    }

    public static function forbidden(): never
    {
        http_response_code(403);
        View::render('errors/message', [
            'title'   => 'Access denied',
            'message' => 'You do not have permission to access this page.',
        ], 'public');
        exit;
    }
}
