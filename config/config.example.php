<?php
// Copy this file to config/config.php and fill in real values.
// config/ lives OUTSIDE public_html so it is never web-accessible.
return [
    'app' => [
        'env'       => 'production',       // production | local
        'debug'     => false,              // never true in production
        'url'       => 'https://bank.example.com',
        'base_path' => '',                 // e.g. '/portal' if installed in a sub-folder
        'key'       => 'CHANGE-ME-to-a-random-64-char-string',
        'timezone'  => 'UTC',
    ],
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'cpaneluser_bank',
        'user' => 'cpaneluser_bank',
        'pass' => '',
    ],
    'mail' => [
        'enabled'    => false,             // integration point — disabled by default
        'from_email' => 'no-reply@example.com',
        'from_name'  => null,              // defaults to bank name
    ],
    'session' => [
        'name'   => 'bank_session',
        'secure' => true,                  // requires HTTPS
    ],
];
