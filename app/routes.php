<?php
declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\CardController;
use App\Controllers\CryptoController;
use App\Controllers\CustomerController;
use App\Controllers\SecurityController;
use App\Controllers\SupportController;
use App\Controllers\Admin;

/** @var App\Core\Router $router */

// Public / auth
$router->get('/', [AuthController::class, 'home']);
$router->get('/login', [AuthController::class, 'showLogin'], ['guest']);
$router->post('/login', [AuthController::class, 'login'], ['guest']);
$router->get('/register', [AuthController::class, 'showRegister'], ['guest']);
$router->post('/register', [AuthController::class, 'register'], ['guest']);
$router->post('/logout', [AuthController::class, 'logout']);
$router->get('/login/2fa', [AuthController::class, 'showTwoFactor'], ['guest']);
$router->post('/login/2fa', [AuthController::class, 'twoFactor'], ['guest']);
$router->get('/forgot-password', [AuthController::class, 'showForgot'], ['guest']);
$router->post('/forgot-password', [AuthController::class, 'forgot'], ['guest']);
$router->get('/reset-password/{token}', [AuthController::class, 'showReset'], ['guest']);
$router->post('/reset-password/{token}', [AuthController::class, 'reset'], ['guest']);
$router->get('/verify-email/{token}', [AuthController::class, 'verifyEmail']);
$router->post('/verify-email/resend', [AuthController::class, 'resendVerification'], ['guest']);
$router->get('/terms', [AuthController::class, 'terms']);
$router->get('/privacy', [AuthController::class, 'privacy']);

// Customer portal
$c = ['customer'];
$router->get('/dashboard', [CustomerController::class, 'dashboard'], $c);
$router->get('/accounts', [CustomerController::class, 'accounts'], $c);
$router->get('/accounts/{id}', [CustomerController::class, 'account'], $c);
$router->get('/accounts/{id}/statement', [CustomerController::class, 'statement'], $c);
$router->get('/transactions', [CustomerController::class, 'transactions'], $c);
$router->get('/transactions/{ref}', [CustomerController::class, 'transaction'], $c);
$router->get('/transfer', [CustomerController::class, 'transferForm'], $c);
$router->post('/transfer', [CustomerController::class, 'transfer'], $c);
$router->get('/withdrawals', [CustomerController::class, 'withdrawals'], $c);
$router->post('/withdrawals', [CustomerController::class, 'requestWithdrawal'], $c);
$router->post('/withdrawals/{id}/cancel', [CustomerController::class, 'cancelWithdrawal'], $c);
$router->get('/add-funds', [CustomerController::class, 'addFundsForm'], $c);
$router->post('/add-funds', [CustomerController::class, 'addFunds'], $c);
$router->get('/notifications', [CustomerController::class, 'notifications'], ['auth']);
$router->get('/profile', [CustomerController::class, 'profile'], ['auth']);
$router->post('/profile/password', [CustomerController::class, 'changePassword'], ['auth']);
$router->post('/profile/sessions/revoke', [CustomerController::class, 'revokeSessions'], ['auth']);
$router->get('/profile/2fa', [SecurityController::class, 'twofa'], ['auth']);
$router->post('/profile/2fa/enable', [SecurityController::class, 'enable'], ['auth']);
$router->post('/profile/2fa/disable', [SecurityController::class, 'disable'], ['auth']);
$router->post('/profile/verify-email', [CustomerController::class, 'sendVerification'], ['auth']);

// Cards (customer)
$router->get('/cards', [CardController::class, 'index'], $c);
$router->post('/cards', [CardController::class, 'request'], $c);
$router->get('/cards/{id}', [CardController::class, 'show'], $c);
$router->post('/cards/{id}/freeze', [CardController::class, 'freeze'], $c);
$router->post('/cards/{id}/controls', [CardController::class, 'controls'], $c);
$router->post('/cards/{id}/report', [CardController::class, 'reportLost'], $c);
$router->post('/cards/{id}/reveal', [CardController::class, 'reveal'], $c);
$router->post('/cards/{id}/pay', [CardController::class, 'pay'], $c);

// Crypto (customer, simulated)
$router->get('/crypto', [CryptoController::class, 'index'], $c);
$router->post('/crypto/acknowledge', [CryptoController::class, 'acknowledge'], $c);
$router->get('/crypto/{symbol}', [CryptoController::class, 'show'], $c);
$router->post('/crypto/{symbol}/quote', [CryptoController::class, 'quote'], $c);
$router->post('/crypto/{symbol}', [CryptoController::class, 'trade'], $c);

// Support & chat (customer)
$router->get('/support', [SupportController::class, 'index'], $c);
$router->post('/support', [SupportController::class, 'store'], $c);
$router->get('/support/{id}', [SupportController::class, 'show'], $c);
$router->post('/support/{id}/reply', [SupportController::class, 'reply'], $c);
$router->post('/support/{id}/close', [SupportController::class, 'close'], $c);
$router->get('/chat', [SupportController::class, 'chat'], $c);
$router->get('/chat/messages', [SupportController::class, 'chatMessages'], $c);
$router->post('/chat', [SupportController::class, 'chatSend'], $c);
$router->get('/attachments/{id}', [SupportController::class, 'attachment'], ['auth']);

// Staff back office
$router->get('/admin', [Admin\DashboardController::class, 'index'], ['staff']);

$router->get('/admin/customers', [Admin\CustomerController::class, 'index'], ['perm:customers.view']);
$router->get('/admin/customers/new', [Admin\CustomerController::class, 'create'], ['perm:customers.create']);
$router->post('/admin/customers', [Admin\CustomerController::class, 'store'], ['perm:customers.create']);
$router->get('/admin/customers/{id}', [Admin\CustomerController::class, 'show'], ['perm:customers.view']);
$router->post('/admin/customers/{id}/status', [Admin\CustomerController::class, 'updateStatus'], ['perm:customers.lock']);
$router->post('/admin/customers/{id}/managers', [Admin\CustomerController::class, 'assignManager'], ['perm:accounts.assign_manager']);
$router->post('/admin/customers/{id}/managers/{mid}/remove', [Admin\CustomerController::class, 'removeManager'], ['perm:accounts.assign_manager']);
$router->post('/admin/customers/{id}/accounts', [Admin\CustomerController::class, 'openAccount'], ['perm:accounts.create']);
$router->post('/admin/customers/{id}/tickets', [Admin\SupportController::class, 'store'], ['perm:support.view']);
$router->post('/admin/customers/{id}/reset-2fa', [Admin\CustomerController::class, 'resetTwoFactor'], ['perm:customers.lock']);

$router->get('/admin/accounts', [Admin\AccountController::class, 'index'], ['perm:accounts.view']);
$router->get('/admin/accounts/{id}', [Admin\AccountController::class, 'show'], ['perm:accounts.view']);
$router->get('/admin/accounts/{id}/statement', [Admin\AccountController::class, 'statement'], ['perm:accounts.view']);
$router->post('/admin/accounts/{id}/status', [Admin\AccountController::class, 'updateStatus'], ['perm:accounts.lock']);
$router->post('/admin/accounts/{id}/restrictions', [Admin\AccountController::class, 'addRestriction'], ['perm:accounts.freeze']);
$router->post('/admin/restrictions/{id}/lift', [Admin\AccountController::class, 'liftRestriction'], ['perm:accounts.freeze']);
$router->post('/admin/accounts/{id}/limits', [Admin\AccountController::class, 'updateLimits'], ['perm:accounts.limit']);
$router->post('/admin/accounts/{id}/funds', [Admin\FundsController::class, 'store'], ['staff']);
$router->post('/admin/accounts/{id}/withdrawals', [Admin\WithdrawalController::class, 'store'], ['perm:funds.withdraw']);

$router->get('/admin/funds', [Admin\FundsController::class, 'index'], ['staff']);
$router->post('/admin/funds/{id}/approve', [Admin\FundsController::class, 'approve'], ['perm:funds.approve']);
$router->post('/admin/funds/{id}/reject', [Admin\FundsController::class, 'reject'], ['perm:funds.approve']);

$router->get('/admin/withdrawals', [Admin\WithdrawalController::class, 'index'], ['staff']);
$router->post('/admin/withdrawals/{id}/approve', [Admin\WithdrawalController::class, 'approve'], ['perm:funds.approve']);
$router->post('/admin/withdrawals/{id}/reject', [Admin\WithdrawalController::class, 'reject'], ['perm:funds.approve']);

$router->get('/admin/transactions', [Admin\TransactionController::class, 'index'], ['perm:transactions.view']);
$router->get('/admin/transactions/{id}', [Admin\TransactionController::class, 'show'], ['perm:transactions.view']);
$router->post('/admin/transactions/{id}/approve', [Admin\TransactionController::class, 'approve'], ['perm:transactions.approve']);
$router->post('/admin/transactions/{id}/reject', [Admin\TransactionController::class, 'reject'], ['perm:transactions.approve']);

$router->get('/admin/audit', [Admin\AuditController::class, 'index'], ['perm:audit.view']);

$router->get('/admin/staff', [Admin\StaffController::class, 'index'], ['perm:staff.view']);
$router->post('/admin/staff', [Admin\StaffController::class, 'store'], ['perm:staff.manage']);
$router->post('/admin/staff/{id}', [Admin\StaffController::class, 'update'], ['perm:staff.manage']);

$router->get('/admin/roles', [Admin\RoleController::class, 'index'], ['perm:roles.manage']);
$router->post('/admin/roles/{id}', [Admin\RoleController::class, 'update'], ['perm:roles.manage']);

$router->get('/admin/settings', [Admin\SettingsController::class, 'index'], ['perm:settings.view']);
$router->post('/admin/settings', [Admin\SettingsController::class, 'update'], ['perm:settings.manage']);
$router->post('/admin/settings/test-email', [Admin\SettingsController::class, 'testEmail'], ['perm:settings.manage']);
$router->get('/branding/{kind}', [Admin\SettingsController::class, 'brandingFile']);

$router->get('/admin/support', [Admin\SupportController::class, 'index'], ['perm:support.view']);
$router->get('/admin/support/{id}', [Admin\SupportController::class, 'show'], ['perm:support.view']);
$router->post('/admin/support/{id}', [Admin\SupportController::class, 'update'], ['perm:support.view']);
$router->post('/admin/support/{id}/reply', [Admin\SupportController::class, 'reply'], ['perm:support.view']);
$router->get('/admin/chats', [Admin\SupportController::class, 'chats'], ['perm:support.view']);
$router->get('/admin/chats/{id}', [Admin\SupportController::class, 'chat'], ['perm:support.view']);
$router->get('/admin/chats/{id}/messages', [Admin\SupportController::class, 'chatMessages'], ['perm:support.view']);
$router->post('/admin/chats/{id}', [Admin\SupportController::class, 'chatSend'], ['perm:support.view']);
$router->post('/admin/chats/{id}/update', [Admin\SupportController::class, 'chatUpdate'], ['perm:support.view']);

$router->get('/admin/fees', [Admin\FeesController::class, 'index'], ['perm:reports.view']);
$router->post('/admin/fees/run', [Admin\FeesController::class, 'runMonthly'], ['perm:settings.manage']);
$router->get('/admin/account-types', [Admin\AccountTypeController::class, 'index'], ['perm:settings.view']);
$router->post('/admin/account-types/{id}', [Admin\AccountTypeController::class, 'update'], ['perm:settings.manage']);
$router->get('/admin/templates', [Admin\TemplateController::class, 'index'], ['perm:settings.view']);
$router->post('/admin/templates/{id}', [Admin\TemplateController::class, 'update'], ['perm:settings.manage']);
$router->post('/admin/templates/{id}/reset', [Admin\TemplateController::class, 'reset'], ['perm:settings.manage']);

$router->get('/admin/cards', [Admin\CardController::class, 'index'], ['perm:cards.view']);
$router->get('/admin/cards/{id}', [Admin\CardController::class, 'show'], ['perm:cards.view']);
$router->post('/admin/customers/{id}/cards', [Admin\CardController::class, 'store'], ['perm:cards.view']);
$router->post('/admin/cards/{id}/issue', [Admin\CardController::class, 'issue'], ['perm:cards.issue']);
$router->post('/admin/cards/{id}/reject', [Admin\CardController::class, 'reject'], ['perm:cards.issue']);
$router->post('/admin/cards/{id}/replace', [Admin\CardController::class, 'replace'], ['perm:cards.issue']);
$router->post('/admin/cards/{id}/status', [Admin\CardController::class, 'status'], ['perm:cards.freeze']);
$router->post('/admin/cards/{id}/controls', [Admin\CardController::class, 'controls'], ['perm:cards.freeze']);
$router->post('/admin/cards/{id}/simulate', [Admin\CardController::class, 'simulate'], ['perm:cards.configure']);
$router->post('/admin/cards/{id}/transactions/{tx}/reverse', [Admin\CardController::class, 'reverse'], ['perm:cards.configure']);
$router->get('/admin/card-products', [Admin\CardProductController::class, 'index'], ['perm:cards.configure']);
$router->post('/admin/card-products', [Admin\CardProductController::class, 'save'], ['perm:cards.configure']);

$router->get('/admin/crypto', [Admin\CryptoController::class, 'index'], ['perm:crypto.view']);
$router->get('/admin/crypto/trades', [Admin\CryptoController::class, 'trades'], ['perm:crypto.view']);
$router->post('/admin/crypto', [Admin\CryptoController::class, 'save'], ['perm:crypto.manage']);
$router->post('/admin/crypto/simulate', [Admin\CryptoController::class, 'simulate'], ['perm:crypto.manage']);
$router->post('/admin/crypto/{id}/price', [Admin\CryptoController::class, 'price'], ['perm:crypto.manage']);
