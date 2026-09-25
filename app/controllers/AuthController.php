<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Services\AccountService;
use App\Services\AuditService;
use App\Services\NotificationService;
use App\Services\TokenService;

final class AuthController extends Controller
{
    public function home(): void
    {
        if (Auth::check()) {
            redirect(Auth::isStaff() ? '/admin' : '/dashboard');
        }
        redirect('/login');
    }

    public function showLogin(): void
    {
        $this->view('auth/login', ['title' => 'Sign in'], 'public');
    }

    public function login(): void
    {
        $result = Auth::attempt(input('username'), (string) ($_POST['password'] ?? ''));
        if (!$result['ok']) {
            remember_input();
            flash('error', $result['error']);
            if (!empty($result['unverified'])) {
                $_SESSION['show_resend'] = true;
            }
            redirect('/login');
        }
        clear_old();
        if (!empty($result['twofa'])) {
            redirect('/login/2fa');
        }
        $this->afterLogin();
    }

    public function showTwoFactor(): void
    {
        if (empty($_SESSION['2fa_pending'])) {
            redirect('/login');
        }
        $this->view('auth/twofa', ['title' => 'Verification code'], 'public');
    }

    public function twoFactor(): void
    {
        $r = Auth::completeTwoFactor(input('code'));
        if (!$r['ok']) {
            flash('error', $r['error']);
            redirect(!empty($r['restart']) ? '/login' : '/login/2fa');
        }
        $this->afterLogin();
    }

    private function afterLogin(): never
    {
        if (!Auth::isStaff() && setting('maintenance_mode') === '1') {
            Auth::logout();
            flash('error', setting('maintenance_message'));
            redirect('/login');
        }
        $intended = $_SESSION['intended'] ?? null;
        unset($_SESSION['intended']);
        if (is_string($intended) && str_starts_with($intended, '/') && !str_starts_with($intended, '//')) {
            header('Location: ' . $intended);
            exit;
        }
        redirect(Auth::isStaff() ? '/admin' : '/dashboard');
    }

    public function logout(): void
    {
        Auth::logout();
        flash('success', 'You have been signed out.');
        redirect('/login');
    }

    public function showRegister(): void
    {
        if (setting('registration_enabled') !== '1') {
            flash('error', 'Online registration is currently closed. Please contact the bank.');
            redirect('/login');
        }
        $this->view('auth/register', ['title' => 'Open an account'], 'public');
    }

    public function register(): void
    {
        if (setting('registration_enabled') !== '1') {
            redirect('/login');
        }
        remember_input();
        $d = [
            'full_name' => input('full_name'),
            'username'  => input('username'),
            'email'     => strtolower(input('email')),
            'phone'     => input('phone'),
            'dob'       => input('date_of_birth'),
            'address'   => input('address'),
            'city'      => input('city'),
            'country'   => input('country'),
        ];
        $password = (string) ($_POST['password'] ?? '');
        $errors = [];
        if (mb_strlen($d['full_name']) < 3) $errors[] = 'Enter your full name.';
        if (!preg_match('/^[a-zA-Z0-9_.-]{3,60}$/', $d['username'])) $errors[] = 'Username must be 3–60 letters, numbers, dots, dashes or underscores.';
        if (!filter_var($d['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email address.';
        if ($d['dob'] !== '') {
            $dob = \DateTimeImmutable::createFromFormat('!Y-m-d', $d['dob']);
            if (!$dob || $dob > new \DateTimeImmutable('-18 years')) $errors[] = 'You must be at least 18 years old.';
        } else {
            $errors[] = 'Enter your date of birth.';
        }
        if ($d['country'] === '') $errors[] = 'Select your country.';
        if ($err = self::validatePassword($password)) $errors[] = $err;
        if ($password !== ($_POST['password_confirmation'] ?? '')) $errors[] = 'Passwords do not match.';
        if (empty($_POST['terms'])) $errors[] = 'You must accept the terms and conditions.';
        if (!$errors && Db::value('SELECT 1 FROM users WHERE username = ? OR email = ?', [$d['username'], $d['email']])) {
            $errors[] = 'That username or email is already registered.';
        }
        if ($errors) {
            flash('error', implode(' ', $errors));
            redirect('/register');
        }

        $autoActivate = setting('registration_auto_activate') === '1';
        $uid = Db::transaction(function () use ($d, $password, $autoActivate) {
            $uid = Db::insert('users', [
                'user_type' => 'customer', 'username' => $d['username'], 'email' => $d['email'],
                'phone' => $d['phone'] ?: null, 'full_name' => $d['full_name'],
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'status' => $autoActivate ? 'active' : 'pending', 'password_changed_at' => now(),
            ]);
            Db::query("INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE slug = 'customer'", [$uid]);
            $cid = Db::insert('customers', [
                'user_id' => $uid, 'customer_number' => 'C' . gmdate('y') . str_pad((string) $uid, 7, '0', STR_PAD_LEFT),
                'date_of_birth' => $d['dob'], 'address' => $d['address'] ?: null, 'city' => $d['city'] ?: null,
                'country' => $d['country'], 'terms_accepted_at' => now(),
            ]);
            AuditService::log('customer.registered', 'customer', $cid, null, ['username' => $d['username'], 'email' => $d['email']], null, $uid);
            if ($autoActivate) {
                AccountService::open($cid, (string) setting('default_account_type', 'checking'));
            }
            NotificationService::event($uid, 'welcome');
            return $uid;
        });
        self::sendVerification((int) $uid);
        clear_old();
        flash('success', $autoActivate
            ? 'Your profile has been created. You can now sign in.'
            : 'Thank you for registering. Your profile will be reviewed by the bank and you will be able to sign in once it is activated.');
        redirect('/login');
    }

    public static function sendVerification(int $uid): void
    {
        $token = TokenService::issue($uid, 'email_verify', 48 * 60);
        NotificationService::event($uid, 'email_verify', ['link' => rtrim((string) config('app.url'), '/') . url('verify-email/' . $token)]);
    }

    public function verifyEmail(string $token): void
    {
        $uid = TokenService::consume($token, 'email_verify');
        if ($uid === null) {
            flash('error', 'This verification link is invalid or has expired.');
            redirect('/login');
        }
        Db::update('users', ['email_verified_at' => now()], 'id = ? AND email_verified_at IS NULL', [$uid]);
        AuditService::log('security.email_verified', 'user', $uid, null, null, null, $uid);
        flash('success', 'Thank you — your email address is verified.');
        redirect(Auth::check() ? '/profile' : '/login');
    }

    public function resendVerification(): void
    {
        $uid = (int) ($_SESSION['verify_uid'] ?? 0);
        unset($_SESSION['verify_uid'], $_SESSION['show_resend']);
        if ($uid && !Db::value('SELECT email_verified_at FROM users WHERE id = ?', [$uid])) {
            self::sendVerification($uid);
        }
        flash('success', 'If your email still needs verifying, we have sent a new link.');
        redirect('/login');
    }

    public function showForgot(): void
    {
        $this->view('auth/forgot', ['title' => 'Reset password'], 'public');
    }

    public function forgot(): void
    {
        $email = strtolower(input('email'));
        $ip = client_ip();
        $recent = (int) Db::value("SELECT COUNT(*) FROM login_attempts WHERE (identifier = ? OR ip_address = ?) AND identifier LIKE 'reset:%'
                                     AND created_at > UTC_TIMESTAMP() - INTERVAL 1 HOUR", ['reset:' . $email, $ip]);
        Db::insert('login_attempts', ['identifier' => 'reset:' . $email, 'ip_address' => $ip, 'success' => 0]);
        if ($recent < 5 && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $user = Db::one("SELECT id FROM users WHERE email = ? AND status IN ('active','pending')", [$email]);
            if ($user) {
                $token = TokenService::issue((int) $user['id'], 'password_reset', 60);
                NotificationService::event((int) $user['id'], 'password_reset', [
                    'link' => rtrim((string) config('app.url'), '/') . url('reset-password/' . $token),
                ]);
                AuditService::log('security.password_reset_requested', 'user', $user['id'], null, null, null, (int) $user['id']);
            }
        }
        // Same response whether or not the account exists (no user enumeration).
        flash('success', 'If an account exists for that email, a password reset link has been sent. It is valid for 60 minutes.');
        redirect('/login');
    }

    public function showReset(string $token): void
    {
        if (TokenService::peek($token, 'password_reset') === null) {
            flash('error', 'This reset link is invalid or has expired. Please request a new one.');
            redirect('/forgot-password');
        }
        $this->view('auth/reset', ['title' => 'Choose a new password', 'token' => $token], 'public');
    }

    public function reset(string $token): void
    {
        $password = (string) ($_POST['password'] ?? '');
        if ($err = self::validatePassword($password)) {
            flash('error', $err);
            redirect('/reset-password/' . $token);
        }
        if ($password !== ($_POST['password_confirmation'] ?? '')) {
            flash('error', 'Passwords do not match.');
            redirect('/reset-password/' . $token);
        }
        $uid = TokenService::consume($token, 'password_reset');
        if ($uid === null) {
            flash('error', 'This reset link is invalid or has expired.');
            redirect('/forgot-password');
        }
        Db::update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT), 'password_changed_at' => now()], 'id = ?', [$uid]);
        // Receiving the reset email proves ownership of the address.
        Db::update('users', ['email_verified_at' => now()], 'id = ? AND email_verified_at IS NULL', [$uid]);
        Auth::revokeAllSessions($uid);
        AuditService::log('security.password_reset', 'user', $uid, null, null, null, $uid);
        NotificationService::event($uid, 'password_changed');
        flash('success', 'Your password has been reset. Please sign in.');
        redirect('/login');
    }

    public function terms(): void
    {
        $this->view('auth/page', ['title' => 'Terms and conditions', 'body' => setting('terms_text')], 'public');
    }

    public function privacy(): void
    {
        $this->view('auth/page', ['title' => 'Privacy policy', 'body' => setting('privacy_text')], 'public');
    }
}
