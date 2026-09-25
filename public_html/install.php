<?php
declare(strict_types=1);

/**
 * One-time web installer for hosting without a terminal.
 * Open https://your-domain/install.php, fill in the form, done.
 * After a successful install it locks itself (storage/installed.lock) and refuses to run again.
 * You can also delete this file afterwards.
 */

// Locate the application: either one level up (recommended layout) or in a "bank" folder beside public_html.
$root = null;
foreach ([__DIR__ . '/..', __DIR__ . '/../bank'] as $candidate) {
    if (is_file($candidate . '/app/bootstrap.php')) {
        $root = realpath($candidate);
        break;
    }
}

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'");

$h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$page = static function (string $title, string $body) use ($h): never {
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">'
        . '<title>' . $h($title) . '</title><link rel="stylesheet" href="assets/css/fonts.css"><link rel="stylesheet" href="assets/css/app.css">'
        . '<style>.wrap{max-width:760px;margin:0 auto;padding:2rem 1rem 3rem}.req{display:grid;gap:.4rem;margin:0 0 1rem;padding:0;list-style:none}.req li{display:flex;justify-content:space-between;gap:1rem;padding:.45rem .7rem;border-radius:9px;background:var(--surface-2)}.log{white-space:pre-wrap;background:var(--surface-2);border:1px solid var(--line);border-radius:10px;padding:.8rem;font:.8rem/1.5 var(--mono);max-height:260px;overflow:auto}</style>'
        . '</head><body class="public"><main class="wrap">' . $body . '</main></body></html>';
    exit;
};

if ($root === null) {
    $page('Installer', '<section class="card"><h1>Files not found</h1><p>The installer cannot find the <code>app</code> folder. Upload the whole package so that <code>app/</code>, <code>config/</code>, <code>database/</code> and <code>storage/</code> sit next to (or in a <code>bank</code> folder beside) this <code>public_html</code> folder.</p></section>');
}
$lock = $root . '/storage/installed.lock';
$configFile = $root . '/config/config.php';
if (is_file($lock)) {
    http_response_code(403);
    $page('Already installed', '<section class="card"><h1>Already installed</h1><p>This site is already set up, so the installer is locked. You can delete <code>public_html/install.php</code>.</p><p><a class="btn btn-primary" href="login">Go to sign in</a></p></section>');
}

// A site installed earlier (e.g. with the command-line installer) has no lock file yet:
// if its database already has an administrator, lock now instead of offering to reinstall.
if (is_file($configFile)) {
    try {
        $cfg = require $configFile;
        $db = new PDO(sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $cfg['db']['host'], $cfg['db']['name']), $cfg['db']['user'], $cfg['db']['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
        $has = $db->query("SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE r.slug = 'super_admin' LIMIT 1")->fetchColumn();
        if ($has) {
            @file_put_contents($lock, 'Locked ' . gmdate('c') . " (existing installation detected)\n");
            http_response_code(403);
            $page('Already installed', '<section class="card"><h1>Already installed</h1><p>This site is already set up, so the installer is locked. You can delete <code>public_html/install.php</code>.</p></section>');
        }
    } catch (Throwable) {
        // Config present but database not ready: allow the installer to (re)write it.
    }
}

// ── Requirements ──
$checks = [
    'PHP 8.2 or newer (you have ' . PHP_VERSION . ')' => version_compare(PHP_VERSION, '8.2.0', '>='),
    'PDO MySQL extension' => extension_loaded('pdo_mysql'),
    'OpenSSL extension (encryption)' => extension_loaded('openssl'),
    'mbstring extension' => extension_loaded('mbstring'),
    'cURL extension (integrations)' => extension_loaded('curl'),
    'fileinfo extension (uploads)' => extension_loaded('fileinfo'),
    'config/ folder is writable' => is_writable($root . '/config'),
    'storage/ folder is writable' => is_writable($root . '/storage'),
];
$ok = !in_array(false, $checks, true);
$reqHtml = '<ul class="req">';
foreach ($checks as $label => $pass) {
    $reqHtml .= '<li><span>' . $h($label) . '</span>' . ($pass ? '<span class="badge badge-success">OK</span>' : '<span class="badge badge-danger">Missing</span>') . '</li>';
}
$reqHtml .= '</ul>';

session_name('bank_installer');
session_start();
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$defaults = [
    'url' => ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $base,
    'db_host' => 'localhost', 'db_name' => '', 'db_user' => '', 'bank_name' => '', 'currency' => 'USD', 'currency_symbol' => '$',
    'timezone' => 'UTC', 'admin_user' => 'admin', 'admin_email' => '', 'admin_name' => '',
];
$v = array_merge($defaults, array_intersect_key(array_map(fn ($x) => is_string($x) ? trim($x) : '', $_POST), $defaults));
$error = null;
$log = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $ok) {
    if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $error = 'Your session expired. Please submit the form again.';
    } elseif (!filter_var($v['url'], FILTER_VALIDATE_URL)) {
        $error = 'Enter the full website address, e.g. https://bank.example.com';
    } elseif (!preg_match('/^[A-Z]{3}$/', strtoupper($v['currency']))) {
        $error = 'Currency must be a 3-letter code such as USD, NGN or EUR.';
    } elseif (!in_array($v['timezone'], timezone_identifiers_list(), true)) {
        $error = 'Choose a valid time zone, e.g. Africa/Lagos or America/New_York.';
    } elseif (($_POST['admin_pass'] ?? '') !== ($_POST['admin_pass2'] ?? '')) {
        $error = 'The two admin passwords do not match.';
    } else {
        // 1. Test the database login before writing anything.
        try {
            $pdo = new PDO(sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $v['db_host'], $v['db_name']), $v['db_user'], (string) ($_POST['db_pass'] ?? ''),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 8]);
            $pdo = null;
        } catch (PDOException $e) {
            $error = 'Could not connect to the database: ' . (str_contains($e->getMessage(), 'Access denied') ? 'the username or password is wrong, or the user is not added to the database (cPanel → MySQL Databases → Add User To Database).' : (str_contains($e->getMessage(), 'Unknown database') ? 'the database name does not exist.' : 'check the host name (usually "localhost").'));
        }
        // 2. Write config/config.php with a freshly generated encryption key.
        if (!$error) {
            $export = static fn ($x) => var_export($x, true);
            $config = "<?php\n// Written by the web installer on " . gmdate('Y-m-d H:i') . " UTC. Keep this file private.\n"
                . "// BACK UP 'key': it encrypts card data and API keys; if it changes they cannot be read.\nreturn [\n"
                . "    'app' => [\n        'env' => 'production',\n        'debug' => false,\n        'url' => " . $export(rtrim($v['url'], '/')) . ",\n"
                . "        'base_path' => " . $export((string) parse_url($v['url'], PHP_URL_PATH) === '/' ? '' : rtrim((string) parse_url($v['url'], PHP_URL_PATH), '/')) . ",\n"
                . "        'key' => " . $export(bin2hex(random_bytes(32))) . ",\n        'timezone' => " . $export($v['timezone']) . ",\n    ],\n"
                . "    'db' => [\n        'host' => " . $export($v['db_host']) . ",\n        'port' => 3306,\n        'name' => " . $export($v['db_name']) . ",\n"
                . "        'user' => " . $export($v['db_user']) . ",\n        'pass' => " . $export((string) ($_POST['db_pass'] ?? '')) . ",\n    ],\n"
                . "    'mail' => [\n        'enabled' => false,\n        'driver' => 'mail',\n        'from_email' => " . $export('no-reply@' . preg_replace('/^www\./', '', (string) parse_url($v['url'], PHP_URL_HOST))) . ",\n        'from_name' => null,\n    ],\n"
                . "    'session' => [\n        'name' => 'bank_session',\n        'secure' => " . (str_starts_with($v['url'], 'https://') ? 'true' : 'false') . ",\n    ],\n];\n";
            if (is_file($configFile) && !is_file($configFile . '.bak')) {
                @copy($configFile, $configFile . '.bak');
            }
            if (file_put_contents($configFile, $config, LOCK_EX) === false) {
                $error = 'Could not write config/config.php. Make sure the config folder is writable (permissions 755).';
            } else {
                @chmod($configFile, 0640);
            }
        }
        // 3. Create tables, seed data and the first administrator.
        if (!$error) {
            session_write_close(); // the application starts its own session
            try {
                require $root . '/app/bootstrap.php';
                require $root . '/database/Installer.php';
                Installer::migrate(static function (string $line) use (&$log): void { $log .= $line; });
                App\Services\SettingsService::set('currency', strtoupper($v['currency']));
                App\Services\SettingsService::set('currency_symbol', mb_substr($v['currency_symbol'], 0, 4) ?: '$');
                if ($v['bank_name'] !== '') {
                    App\Services\SettingsService::set('bank_name', mb_substr($v['bank_name'], 0, 100));
                    App\Services\SettingsService::set('bank_short_name', mb_substr($v['bank_name'], 0, 20));
                }
                App\Core\Db::query('UPDATE accounts SET currency = ? WHERE is_system = 1 AND NOT EXISTS (SELECT 1 FROM ledger_entries)', [strtoupper($v['currency'])]);
                if (!Installer::hasSuperAdmin()) {
                    Installer::createSuperAdmin($v['admin_user'], $v['admin_email'], $v['admin_name'] ?: 'System Administrator', (string) ($_POST['admin_pass'] ?? ''));
                }
                file_put_contents($lock, 'Installed ' . gmdate('c') . "\n");
                $page('Installed', '<section class="card"><div class="eyebrow">All done</div><h1>' . $h($v['bank_name'] ?: 'Your bank') . ' is ready</h1>'
                    . '<p>The database is set up and your administrator account has been created. The installer is now locked.</p>'
                    . '<div class="alert alert-info"><span><strong>Next:</strong> delete <code>public_html/install.php</code> in File Manager, then set up the cron jobs listed in the README (Cron Jobs in cPanel).</span></div>'
                    . '<details class="mt"><summary class="btn btn-ghost btn-sm">Show setup log</summary><div class="log mt">' . $h($log) . '</div></details>'
                    . '<p class="mt"><a class="btn btn-primary" href="login">Sign in as ' . $h($v['admin_user']) . '</a></p></section>');
            } catch (InvalidArgumentException $e) {
                $error = $e->getMessage();
                @unlink($configFile); // let the form be submitted again cleanly
                if (is_file($configFile . '.bak')) {
                    @rename($configFile . '.bak', $configFile);
                }
                session_start();
            } catch (Throwable $e) {
                $error = 'Setup stopped: ' . $e->getMessage();
                session_start();
            }
        }
    }
}

$f = static fn (string $name, string $label, string $type = 'text', string $help = '', bool $req = true) =>
    '<label>' . $h($label) . ' <input type="' . $type . '" id="' . $name . '" name="' . $name . '"' . ($type === 'password' ? ' autocomplete="new-password"' : ' value="' . $h($v[$name] ?? '') . '"') . ($req ? ' required' : '') . '>'
    . ($help ? '<small class="muted">' . $h($help) . '</small>' : '') . '</label>';

$body = '<div class="eyebrow">Setup</div><h1>Install your online bank</h1><p class="muted">This takes about a minute. You need the database you created in cPanel → MySQL Databases.</p>';
if ($error) {
    $body .= '<div class="alert alert-error"><span>' . $h($error) . '</span></div>';
}
$body .= '<section class="card"><h2>1. Server check</h2>' . $reqHtml . ($ok ? '' : '<p class="neg">Fix the missing items (cPanel → Select PHP Version / MultiPHP Manager) and reload this page.</p>') . '</section>';
if ($ok) {
    $body .= '<form method="post" class="form" autocomplete="off"><input type="hidden" name="csrf" value="' . $h($_SESSION['csrf']) . '">'
        . '<section class="card"><h2>2. Website</h2><div class="form grid-2">'
        . $f('url', 'Website address', 'url', 'Use https:// once SSL is active (recommended)')
        . $f('bank_name', 'Bank name', 'text', 'You can change it and upload a logo later', false)
        . $f('currency', 'Currency code', 'text', 'e.g. USD, NGN, GHS, EUR, GBP')
        . $f('currency_symbol', 'Currency symbol', 'text', 'e.g. $, ₦, ₵, €, £')
        . $f('timezone', 'Time zone', 'text', 'e.g. Africa/Lagos, Europe/London, America/New_York')
        . '</div></section>'
        . '<section class="card"><h2>3. Database</h2><div class="form grid-2">'
        . $f('db_host', 'Database host', 'text', 'Almost always "localhost" on cPanel')
        . $f('db_name', 'Database name', 'text', 'Includes your cPanel prefix, e.g. myuser_bank')
        . $f('db_user', 'Database user', 'text', 'Also prefixed, e.g. myuser_bankuser')
        . $f('db_pass', 'Database password', 'password', '', false)
        . '</div></section>'
        . '<section class="card"><h2>4. Your administrator account</h2><div class="form grid-2">'
        . $f('admin_name', 'Full name', 'text', '', false)
        . $f('admin_user', 'Username')
        . $f('admin_email', 'Email', 'email')
        . '<span></span>'
        . $f('admin_pass', 'Password', 'password', 'At least 12 characters')
        . $f('admin_pass2', 'Repeat password', 'password')
        . '</div></section>'
        . '<div><button class="btn btn-primary">Install now</button></div></form>';
}
$page('Install', $body);
