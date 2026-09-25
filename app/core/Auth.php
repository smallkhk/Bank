<?php
declare(strict_types=1);

namespace App\Core;

use App\Services\AuditService;

/**
 * Session authentication backed by the user_sessions table, so sessions can be
 * listed and revoked ("log out of all devices").
 */
final class Auth
{
    private static ?array $user = null;
    private static ?array $permissions = null;
    private static bool $resolved = false;

    public static function user(): ?array
    {
        if (self::$resolved) {
            return self::$user;
        }
        self::$resolved = true;

        $uid = $_SESSION['uid'] ?? null;
        $token = $_SESSION['sess_token'] ?? null;
        if (!$uid || !$token) {
            return null;
        }
        $session = Db::one(
            'SELECT id, last_seen_at FROM user_sessions WHERE user_id = ? AND token_hash = ? AND revoked_at IS NULL',
            [$uid, hash('sha256', $token)]
        );
        $idleLimit = (int) setting('session_idle_minutes', '30') * 60;
        if (!$session || strtotime($session['last_seen_at'] . ' UTC') < time() - $idleLimit) {
            if ($session) {
                Db::update('user_sessions', ['revoked_at' => now()], 'id = ?', [$session['id']]);
            }
            self::clearSession();
            return null;
        }
        $user = Db::one('SELECT * FROM users WHERE id = ?', [$uid]);
        if (!$user || $user['status'] !== 'active') {
            self::clearSession();
            return null;
        }
        // Throttle last_seen writes to once a minute.
        if (strtotime($session['last_seen_at'] . ' UTC') < time() - 60) {
            Db::update('user_sessions', ['last_seen_at' => now()], 'id = ?', [$session['id']]);
        }
        $_SESSION['session_row_id'] = (int) $session['id'];
        return self::$user = $user;
    }

    public static function id(): ?int
    {
        $u = self::user();
        return $u ? (int) $u['id'] : null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function isStaff(): bool
    {
        return (self::user()['user_type'] ?? null) === 'staff';
    }

    public static function isCustomer(): bool
    {
        return (self::user()['user_type'] ?? null) === 'customer';
    }

    public static function customerId(): ?int
    {
        if (!self::isCustomer()) {
            return null;
        }
        $id = Db::value('SELECT id FROM customers WHERE user_id = ?', [self::id()]);
        return $id === null ? null : (int) $id;
    }

    public static function roles(?int $userId = null): array
    {
        return array_column(Db::all(
            'SELECT r.slug FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ?',
            [$userId ?? self::id()]
        ), 'slug');
    }

    public static function permissions(): array
    {
        if (self::$permissions !== null) {
            return self::$permissions;
        }
        if (!self::isStaff()) {
            return self::$permissions = [];
        }
        if (in_array('super_admin', self::roles(), true)) {
            return self::$permissions = ['*'];
        }
        return self::$permissions = array_column(Db::all(
            'SELECT DISTINCT p.slug FROM user_roles ur
               JOIN role_permissions rp ON rp.role_id = ur.role_id
               JOIN permissions p ON p.id = rp.permission_id
              WHERE ur.user_id = ?',
            [self::id()]
        ), 'slug');
    }

    public static function can(string $permission): bool
    {
        $perms = self::permissions();
        return in_array('*', $perms, true) || in_array($permission, $perms, true);
    }

    /**
     * @return array{ok:bool, error?:string}
     */
    public static function attempt(string $identifier, string $password): array
    {
        $ip = client_ip();
        $identifier = trim($identifier);
        $maxAttempts = (int) setting('login_max_attempts', '5');
        $window = (int) setting('login_lockout_minutes', '15');

        $recentFailsById = (int) Db::value(
            'SELECT COUNT(*) FROM login_attempts WHERE identifier = ? AND success = 0 AND created_at > (UTC_TIMESTAMP() - INTERVAL ? MINUTE)',
            [strtolower($identifier), $window]
        );
        $recentFailsByIp = (int) Db::value(
            'SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND success = 0 AND created_at > (UTC_TIMESTAMP() - INTERVAL ? MINUTE)',
            [$ip, $window]
        );
        if ($recentFailsById >= $maxAttempts || $recentFailsByIp >= $maxAttempts * 4) {
            AuditService::log('auth.login_throttled', 'user', null, null, ['identifier' => $identifier]);
            return ['ok' => false, 'error' => "Too many failed attempts. Please wait {$window} minutes and try again."];
        }

        $user = Db::one('SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1', [$identifier, $identifier]);
        // Always run a hash verification to keep timing uniform.
        $hash = $user['password_hash'] ?? '$2y$12$fSu.sD5D1eauV0mTQzYrFeL8/re1d7FZGD0ROiQOeEOXzpVtDlWuS';
        $valid = password_verify($password, $hash) && $user !== null;

        Db::insert('login_attempts', ['identifier' => strtolower($identifier), 'ip_address' => $ip, 'success' => $valid ? 1 : 0]);

        if (!$valid) {
            AuditService::log('auth.login_failed', 'user', $user['id'] ?? null, null, ['identifier' => $identifier], null, $user['id'] ?? null);
            return ['ok' => false, 'error' => 'Invalid username or password.'];
        }

        if ($user['status'] !== 'active') {
            AuditService::log('auth.login_blocked', 'user', $user['id'], null, ['status' => $user['status']], null, (int) $user['id']);
            $msg = match ($user['status']) {
                'pending' => 'Your profile is awaiting activation by the bank.',
                'locked'  => 'Your access has been locked. Please contact support.',
                default   => 'Your access is currently unavailable. Please contact support.',
            };
            return ['ok' => false, 'error' => $msg];
        }

        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            Db::update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], 'id = ?', [$user['id']]);
        }

        self::login((int) $user['id']);
        return ['ok' => true];
    }

    public static function login(int $userId): void
    {
        session_regenerate_id(true);
        $token = bin2hex(random_bytes(32));
        $_SESSION = ['uid' => $userId, 'sess_token' => $token];
        Db::insert('user_sessions', [
            'user_id'    => $userId,
            'token_hash' => hash('sha256', $token),
            'ip_address' => client_ip(),
            'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
        Db::update('users', ['last_login_at' => now(), 'last_login_ip' => client_ip()], 'id = ?', [$userId]);
        self::$resolved = false;
        self::$permissions = null;
        AuditService::log('auth.login', 'user', $userId, null, null, null, $userId);
    }

    public static function logout(): void
    {
        if ($id = self::id()) {
            if (!empty($_SESSION['sess_token'])) {
                Db::update('user_sessions', ['revoked_at' => now()], 'token_hash = ?', [hash('sha256', $_SESSION['sess_token'])]);
            }
            AuditService::log('auth.logout', 'user', $id);
        }
        self::clearSession();
    }

    public static function revokeOtherSessions(int $userId): int
    {
        return Db::update(
            'user_sessions',
            ['revoked_at' => now()],
            'user_id = ? AND revoked_at IS NULL AND token_hash <> ?',
            [$userId, hash('sha256', (string) ($_SESSION['sess_token'] ?? ''))]
        );
    }

    public static function revokeAllSessions(int $userId): int
    {
        return Db::update('user_sessions', ['revoked_at' => now()], 'user_id = ? AND revoked_at IS NULL', [$userId]);
    }

    private static function clearSession(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
        self::$user = null;
        self::$permissions = null;
        self::$resolved = true;
    }
}
