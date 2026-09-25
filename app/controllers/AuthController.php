<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Services\AccountService;
use App\Services\AuditService;
use App\Services\NotificationService;

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
            redirect('/login');
        }
        clear_old();
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
        Db::transaction(function () use ($d, $password, $autoActivate) {
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
            NotificationService::notify($uid, 'Welcome to ' . bank_name(), 'Your online banking profile has been created.');
        });
        clear_old();
        flash('success', $autoActivate
            ? 'Your profile has been created. You can now sign in.'
            : 'Thank you for registering. Your profile will be reviewed by the bank and you will be able to sign in once it is activated.');
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
