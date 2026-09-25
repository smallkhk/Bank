<?php
declare(strict_types=1);

use App\Core\Db;
use App\Services\AuditService;
use App\Services\LedgerService;
use App\Services\SettingsService;

/**
 * Shared setup logic for the command-line installer (database/install.php) and the
 * one-time web installer (public_html/install.php). Idempotent: safe to run again to upgrade.
 */
final class Installer
{
    /** Create/upgrade tables and seed reference data. $out receives progress lines. */
    public static function migrate(callable $out): void
    {
        $pdo = Db::pdo();

        $out("Creating tables...\n");
        $sql = file_get_contents(__DIR__ . '/schema.sql');
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            $pdo->exec($stmt);
        }

        $out("Applying migrations...\n");
        $pdo->exec('CREATE TABLE IF NOT EXISTS migrations (name VARCHAR(190) PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
        $files = glob(__DIR__ . '/migrations/*.sql');
        sort($files);
        foreach ($files as $file) {
            $name = basename($file);
            if (Db::value('SELECT 1 FROM migrations WHERE name = ?', [$name])) {
                continue;
            }
            $out("  - $name\n");
            // Strip "--" comments (whole-line and trailing) before splitting on semicolons.
            $m = preg_replace('/--[^\n]*/', '', (string) file_get_contents($file));
            foreach (array_filter(array_map('trim', explode(';', $m))) as $stmt) {
                try {
                    $pdo->exec($stmt);
                } catch (PDOException $e) {
                    // Re-running a partially applied migration: skip "already exists" errors
                    // (1050 table, 1060 column, 1061 key, 1091 can't drop) and continue.
                    if (!in_array((int) ($e->errorInfo[1] ?? 0), [1050, 1060, 1061, 1091], true)) {
                        throw $e;
                    }
                    $out("    (skipped, already applied)\n");
                }
            }
            Db::insert('migrations', ['name' => $name]);
        }

        $out("Installing ledger immutability triggers...\n");
        foreach (['UPDATE', 'DELETE'] as $op) {
            $name = 'ledger_entries_no_' . strtolower($op);
            try {
                $pdo->exec("DROP TRIGGER IF EXISTS $name");
                $pdo->exec("CREATE TRIGGER $name BEFORE $op ON ledger_entries FOR EACH ROW
                            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ledger_entries are immutable'");
            } catch (PDOException $e) {
                $out("  ! Could not create trigger $name (needs TRIGGER privilege): {$e->getMessage()}\n");
                $out("    The application never updates/deletes ledger rows, but DB-level enforcement is recommended.\n");
            }
        }

        $out("Seeding permissions and roles...\n");
        $permissions = [
            'customers.view' => 'View customers', 'customers.view_all' => 'See all customers (not only assigned)',
            'customers.create' => 'Create customers', 'customers.edit' => 'Edit customers', 'customers.lock' => 'Lock/unlock/activate customers',
            'accounts.view' => 'View accounts', 'accounts.create' => 'Open accounts', 'accounts.lock' => 'Lock/unlock accounts',
            'accounts.freeze' => 'Freeze accounts & add restrictions', 'accounts.limit' => 'Change account limits',
            'accounts.assign_manager' => 'Assign account managers',
            'transactions.view' => 'View transactions', 'transactions.create' => 'Create transactions',
            'transactions.approve' => 'Approve pending transfers', 'transactions.reverse' => 'Reverse transactions',
            'funds.add' => 'Request add funds', 'funds.adjust' => 'Request balance adjustments',
            'funds.withdraw' => 'Initiate withdrawals', 'funds.approve' => 'Approve funds, adjustments & withdrawals',
            'staff.view' => 'View staff', 'staff.manage' => 'Create and edit staff',
            'roles.manage' => 'Manage roles & permissions',
            'settings.view' => 'View settings', 'settings.manage' => 'Change settings',
            'audit.view' => 'View audit logs', 'reports.view' => 'View reports',
            'support.view' => 'View support', 'support.manage' => 'Manage support',
            'cards.view' => 'View cards', 'cards.issue' => 'Approve/issue & replace cards', 'cards.freeze' => 'Freeze, unfreeze & block cards',
            'cards.configure' => 'Configure card products & simulate card transactions',
            'crypto.view' => 'View crypto trades & holdings', 'crypto.manage' => 'Manage crypto assets & prices',
            'integrations.manage' => 'Configure external integrations & API keys',
        ];
        $newPermissions = [];
        foreach ($permissions as $slug => $desc) {
            if (!Db::value('SELECT 1 FROM permissions WHERE slug = ?', [$slug])) {
                $newPermissions[] = $slug;
            }
            Db::query('INSERT INTO permissions (slug, description) VALUES (?, ?) ON DUPLICATE KEY UPDATE description = VALUES(description)', [$slug, $desc]);
        }

        $roles = [
            'super_admin' => ['Super Admin', 'Full system access', ['*']],
            'director' => ['Director', 'High-level operational access', [
                'customers.view', 'customers.view_all', 'accounts.view', 'transactions.view', 'transactions.approve',
                'funds.approve', 'reports.view', 'audit.view', 'staff.view', 'accounts.assign_manager', 'accounts.freeze', 'accounts.lock',
                'cards.view', 'cards.issue', 'cards.freeze', 'crypto.view',
            ]],
            'manager' => ['Account Manager', 'Manages assigned customers', [
                'customers.view', 'customers.edit', 'accounts.view', 'accounts.create', 'transactions.view',
                'funds.add', 'funds.withdraw', 'accounts.freeze', 'accounts.limit', 'support.view',
                'cards.view', 'cards.freeze', 'crypto.view',
            ]],
            'assistant' => ['Assistant', 'Limited staff role', ['customers.view', 'accounts.view', 'transactions.view', 'funds.add', 'support.view', 'cards.view']],
            'support' => ['Support Agent', 'Customer support, no financial permissions', [
                'customers.view', 'customers.view_all', 'accounts.view', 'transactions.view', 'support.view', 'support.manage',
                'cards.view', 'cards.freeze',
            ]],
            'customer' => ['Customer', 'Online banking customer', []],
        ];
        foreach ($roles as $slug => [$name, $desc, $perms]) {
            Db::query('INSERT INTO roles (slug, name, description, is_system) VALUES (?, ?, ?, 1) ON DUPLICATE KEY UPDATE name = name', [$slug, $name, $desc]);
            $roleId = (int) Db::value('SELECT id FROM roles WHERE slug = ?', [$slug]);
            $hasAny = (int) Db::value('SELECT COUNT(*) FROM role_permissions WHERE role_id = ?', [$roleId]);
            // Fresh role: grant its defaults. Existing role: only grant permissions introduced by this upgrade,
            // so an administrator's customisations are never overwritten.
            $grant = $perms === ['*'] ? [] : ($hasAny === 0 ? $perms : array_intersect($perms, $newPermissions));
            foreach ($grant as $p) {
                Db::query('INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT ?, id FROM permissions WHERE slug = ?', [$roleId, $p]);
            }
        }

        $out("Seeding account types and settings...\n");
        foreach (['checking' => 'Checking', 'savings' => 'Savings', 'current' => 'Current', 'business' => 'Business', 'investment' => 'Investment', 'wallet' => 'Wallet', 'credit' => 'Credit card'] as $slug => $name) {
            Db::query('INSERT IGNORE INTO account_types (slug, name) VALUES (?, ?)', [$slug, $name]);
        }
        foreach (SettingsService::DEFAULTS as $k => $v) {
            Db::query('INSERT IGNORE INTO settings (`key`, `value`) VALUES (?, ?)', [$k, $v]);
        }
        // Upgrade the crypto risk notice wording only if the administrator never edited it.
        Db::query('UPDATE settings SET `value` = ? WHERE `key` = ? AND `value` = ?', [SettingsService::DEFAULTS['crypto_risk_text'], 'crypto_risk_text',
            'Crypto assets on this platform are SIMULATED. They are internal records, are not real cryptocurrency, cannot be sent to or received from a blockchain wallet, and exist only inside this platform. Prices are set by the bank and can move sharply. You may lose money you use to buy simulated assets.']);

        $out("Seeding notification templates...\n");
        foreach (App\Services\NotificationService::DEFAULTS as $event => [$name, $subject, $body]) {
            $emailOnly = in_array($event, ['password_reset', 'email_verify'], true);
            Db::query('INSERT IGNORE INTO notification_templates (event, name, subject, body, send_email, send_inapp) VALUES (?, ?, ?, ?, 1, ?)',
                [$event, $name, $subject, $body, $emailOnly ? 0 : 1]);
        }
        // Security-sensitive events go out by SMS too, once an SMS provider is configured (only set on first install of the column).
        if (Db::value("SELECT COUNT(*) FROM notification_templates WHERE send_sms = 1") == 0) {
            Db::query("UPDATE notification_templates SET send_sms = 1 WHERE event IN ('login_new_device','password_changed','security_changed','card_declined','withdrawal_completed')");
        }

        $out("Creating internal system (GL) accounts...\n");
        $currency = SettingsService::get('currency', 'USD');
        foreach ([LedgerService::SYS_FUNDING, LedgerService::SYS_SETTLEMENT, LedgerService::SYS_FEES, LedgerService::SYS_ADJUST, LedgerService::SYS_CARDS, LedgerService::SYS_INTEREST, LedgerService::SYS_CRYPTO, LedgerService::SYS_GATEWAY] as $code) {
            Db::query('INSERT IGNORE INTO accounts (account_number, currency, is_system, system_code, nickname) VALUES (?, ?, 1, ?, ?)',
                [$code, $currency, $code, ucwords(strtolower(str_replace(['SYS-', '-'], ['', ' '], $code)))]);
        }
    }

    public static function hasSuperAdmin(): bool
    {
        return (bool) Db::value("SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE r.slug = 'super_admin' LIMIT 1");
    }

    /** @throws InvalidArgumentException with a user-facing message */
    public static function createSuperAdmin(string $username, string $email, string $name, string $password): int
    {
        if (!preg_match('/^[a-zA-Z0-9_.-]{3,60}$/', $username)) {
            throw new InvalidArgumentException('Username must be 3–60 letters, numbers, dots, dashes or underscores.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Enter a valid email address.');
        }
        if (strlen($password) < 12) {
            throw new InvalidArgumentException('The admin password must be at least 12 characters.');
        }
        $superRole = (int) Db::value("SELECT id FROM roles WHERE slug = 'super_admin'");
        $uid = Db::insert('users', [
            'user_type' => 'staff', 'username' => $username, 'email' => strtolower($email), 'full_name' => $name ?: 'System Administrator',
            'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'status' => 'active',
            'email_verified_at' => now(), 'password_changed_at' => now(),
        ]);
        Db::insert('user_roles', ['user_id' => $uid, 'role_id' => $superRole]);
        AuditService::log('install.super_admin_created', 'user', $uid, null, ['username' => $username], null, $uid);
        return $uid;
    }
}
