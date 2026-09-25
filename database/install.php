<?php
declare(strict_types=1);

/**
 * CLI installer:  php database/install.php
 * Creates tables, seeds roles/permissions/settings/account types/system accounts,
 * and creates the first Super Admin. Safe to re-run (idempotent seeding).
 *
 * Non-interactive:  ADMIN_USER=admin ADMIN_EMAIL=a@b.c ADMIN_PASS='...' php database/install.php
 */

if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}
require __DIR__ . '/../app/bootstrap.php';

use App\Core\Db;
use App\Services\AuditService;
use App\Services\LedgerService;
use App\Services\SettingsService;

$pdo = Db::pdo();

echo "Creating tables...\n";
$sql = file_get_contents(__DIR__ . '/schema.sql');
$sql = preg_replace('/^\s*--.*$/m', '', $sql);
foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
    $pdo->exec($stmt);
}

echo "Installing ledger immutability triggers...\n";
foreach (['UPDATE', 'DELETE'] as $op) {
    $name = 'ledger_entries_no_' . strtolower($op);
    try {
        $pdo->exec("DROP TRIGGER IF EXISTS $name");
        $pdo->exec("CREATE TRIGGER $name BEFORE $op ON ledger_entries FOR EACH ROW
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ledger_entries are immutable'");
    } catch (PDOException $e) {
        echo "  ! Could not create trigger $name (needs TRIGGER privilege): {$e->getMessage()}\n";
        echo "    The application never updates/deletes ledger rows, but DB-level enforcement is recommended.\n";
    }
}

echo "Seeding permissions and roles...\n";
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
];
foreach ($permissions as $slug => $desc) {
    Db::query('INSERT INTO permissions (slug, description) VALUES (?, ?) ON DUPLICATE KEY UPDATE description = VALUES(description)', [$slug, $desc]);
}

$roles = [
    'super_admin' => ['Super Admin', 'Full system access', ['*']],
    'director' => ['Director', 'High-level operational access', [
        'customers.view', 'customers.view_all', 'accounts.view', 'transactions.view', 'transactions.approve',
        'funds.approve', 'reports.view', 'audit.view', 'staff.view', 'accounts.assign_manager', 'accounts.freeze', 'accounts.lock',
    ]],
    'manager' => ['Account Manager', 'Manages assigned customers', [
        'customers.view', 'customers.edit', 'accounts.view', 'accounts.create', 'transactions.view',
        'funds.add', 'funds.withdraw', 'accounts.freeze', 'accounts.limit', 'support.view',
    ]],
    'assistant' => ['Assistant', 'Limited staff role', ['customers.view', 'accounts.view', 'transactions.view', 'funds.add', 'support.view']],
    'support' => ['Support Agent', 'Customer support, no financial permissions', [
        'customers.view', 'customers.view_all', 'accounts.view', 'transactions.view', 'support.view', 'support.manage',
    ]],
    'customer' => ['Customer', 'Online banking customer', []],
];
foreach ($roles as $slug => [$name, $desc, $perms]) {
    Db::query('INSERT INTO roles (slug, name, description, is_system) VALUES (?, ?, ?, 1) ON DUPLICATE KEY UPDATE name = name', [$slug, $name, $desc]);
    $roleId = (int) Db::value('SELECT id FROM roles WHERE slug = ?', [$slug]);
    $hasAny = (int) Db::value('SELECT COUNT(*) FROM role_permissions WHERE role_id = ?', [$roleId]);
    if ($hasAny === 0 && $perms !== ['*']) {
        foreach ($perms as $p) {
            Db::query('INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT ?, id FROM permissions WHERE slug = ?', [$roleId, $p]);
        }
    }
}

echo "Seeding account types and settings...\n";
foreach (['checking' => 'Checking', 'savings' => 'Savings', 'current' => 'Current', 'business' => 'Business', 'investment' => 'Investment', 'wallet' => 'Wallet'] as $slug => $name) {
    Db::query('INSERT IGNORE INTO account_types (slug, name) VALUES (?, ?)', [$slug, $name]);
}
foreach (SettingsService::DEFAULTS as $k => $v) {
    Db::query('INSERT IGNORE INTO settings (`key`, `value`) VALUES (?, ?)', [$k, $v]);
}

echo "Creating internal system (GL) accounts...\n";
$currency = SettingsService::get('currency', 'USD');
foreach ([LedgerService::SYS_FUNDING, LedgerService::SYS_SETTLEMENT, LedgerService::SYS_FEES, LedgerService::SYS_ADJUST] as $code) {
    Db::query('INSERT IGNORE INTO accounts (account_number, currency, is_system, system_code, nickname) VALUES (?, ?, 1, ?, ?)',
        [$code, $currency, $code, ucwords(strtolower(str_replace(['SYS-', '-'], ['', ' '], $code)))]);
}

$superRole = (int) Db::value("SELECT id FROM roles WHERE slug = 'super_admin'");
if (!Db::value('SELECT 1 FROM user_roles WHERE role_id = ?', [$superRole])) {
    echo "\nCreate the first Super Admin\n";
    $ask = static function (string $label, string $env, bool $hidden = false): string {
        if (($v = getenv($env)) !== false && $v !== '') {
            return $v;
        }
        if ($hidden && DIRECTORY_SEPARATOR === '/') {
            echo "$label: ";
            system('stty -echo');
            $v = trim((string) fgets(STDIN));
            system('stty echo');
            echo "\n";
            return $v;
        }
        return trim((string) readline("$label: "));
    };
    $username = $ask('Username', 'ADMIN_USER');
    $email = $ask('Email', 'ADMIN_EMAIL');
    $name = getenv('ADMIN_NAME') ?: 'System Administrator';
    $pass = $ask('Password (min 12 chars)', 'ADMIN_PASS', true);
    if (strlen($pass) < 12 || !filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^[a-zA-Z0-9_.-]{3,60}$/', $username)) {
        fwrite(STDERR, "Invalid input: username 3-60 chars [a-z0-9_.-], valid email, password >= 12 chars.\n");
        exit(1);
    }
    $uid = Db::insert('users', [
        'user_type' => 'staff', 'username' => $username, 'email' => $email, 'full_name' => $name,
        'password_hash' => password_hash($pass, PASSWORD_DEFAULT), 'status' => 'active',
        'email_verified_at' => now(), 'password_changed_at' => now(),
    ]);
    Db::insert('user_roles', ['user_id' => $uid, 'role_id' => $superRole]);
    AuditService::log('install.super_admin_created', 'user', $uid, null, ['username' => $username], null, $uid);
    echo "Super Admin '$username' created.\n";
}

echo "\nDone. Point your domain's document root at public_html/ and sign in at /login.\n";
