<?php
declare(strict_types=1);

/**
 * CLI installer:  php database/install.php
 * Creates tables, seeds roles/permissions/settings/account types/system accounts,
 * and creates the first Super Admin. Safe to re-run (idempotent seeding) — run it after every upgrade.
 * No terminal? Use the web installer instead: https://your-domain/install.php
 *
 * Non-interactive:  ADMIN_USER=admin ADMIN_EMAIL=a@b.c ADMIN_PASS='...' php database/install.php
 */

if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/Installer.php';

Installer::migrate(static function (string $line): void { echo $line; });

if (!Installer::hasSuperAdmin()) {
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
    $pass = $ask('Password (min 12 chars)', 'ADMIN_PASS', true);
    try {
        Installer::createSuperAdmin($username, $email, getenv('ADMIN_NAME') ?: 'System Administrator', $pass);
    } catch (InvalidArgumentException $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
    echo "Super Admin '$username' created.\n";
}
@touch(STORAGE_PATH . '/installed.lock');

echo "\nDone. Point your domain's document root at public_html/ and sign in at /login.\n";
