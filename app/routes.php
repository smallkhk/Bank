<?php
declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\CustomerController;
use App\Controllers\Admin;

/** @var App\Core\Router $router */

// Public / auth
$router->get('/', [AuthController::class, 'home']);
$router->get('/login', [AuthController::class, 'showLogin'], ['guest']);
$router->post('/login', [AuthController::class, 'login'], ['guest']);
$router->get('/register', [AuthController::class, 'showRegister'], ['guest']);
$router->post('/register', [AuthController::class, 'register'], ['guest']);
$router->post('/logout', [AuthController::class, 'logout']);
$router->get('/terms', [AuthController::class, 'terms']);
$router->get('/privacy', [AuthController::class, 'privacy']);

// Customer portal
$c = ['customer'];
$router->get('/dashboard', [CustomerController::class, 'dashboard'], $c);
$router->get('/accounts', [CustomerController::class, 'accounts'], $c);
$router->get('/accounts/{id}', [CustomerController::class, 'account'], $c);
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

$router->get('/admin/accounts', [Admin\AccountController::class, 'index'], ['perm:accounts.view']);
$router->get('/admin/accounts/{id}', [Admin\AccountController::class, 'show'], ['perm:accounts.view']);
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
$router->get('/branding/{kind}', [Admin\SettingsController::class, 'brandingFile']);
