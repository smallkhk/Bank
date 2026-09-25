<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Db;
use App\Services\AccountService;
use App\Services\AuditService;
use App\Services\NotificationService;
use App\Services\StaffScope;

final class CustomerController extends Controller
{
    private const STATUSES = ['pending', 'active', 'suspended', 'locked', 'closed'];

    private function load(int $id): array
    {
        $c = Db::one('SELECT c.*, u.username, u.email, u.phone, u.full_name, u.status, u.status_reason, u.last_login_at, u.last_login_ip, u.created_at AS registered_at
                        FROM customers c JOIN users u ON u.id = c.user_id WHERE c.id = ?', [$id]);
        if (!$c) {
            $this->notFound();
        }
        if (!StaffScope::canAccessCustomer($id)) {
            $this->forbidden();
        }
        return $c;
    }

    public function index(): void
    {
        [$scope, $params] = StaffScope::customerFilter('c.id');
        $where = $scope;
        if (($q = input('q')) !== '') {
            $where .= ' AND (u.full_name LIKE ? OR u.email LIKE ? OR u.username LIKE ? OR c.customer_number LIKE ?
                          OR c.id IN (SELECT customer_id FROM accounts WHERE account_number LIKE ?))';
            array_push($params, "%$q%", "%$q%", "%$q%", "%$q%", "%$q%");
        }
        if (in_array($s = input('status'), self::STATUSES, true)) {
            $where .= ' AND u.status = ?';
            $params[] = $s;
        }
        $total = (int) Db::value("SELECT COUNT(*) FROM customers c JOIN users u ON u.id = c.user_id WHERE $where", $params);
        $p = paginate($total);
        $rows = Db::all(
            "SELECT c.id, c.customer_number, c.country, c.kyc_status, u.full_name, u.email, u.status, u.created_at,
                    (SELECT COUNT(*) FROM accounts a WHERE a.customer_id = c.id) AS account_count,
                    (SELECT COALESCE(SUM(balance),0) FROM accounts a WHERE a.customer_id = c.id) AS total_balance
               FROM customers c JOIN users u ON u.id = c.user_id WHERE $where ORDER BY c.id DESC LIMIT {$p['limit']} OFFSET {$p['offset']}",
            $params
        );
        $this->view('admin/customers', ['title' => 'Customers', 'rows' => $rows, 'pager' => $p, 'statuses' => self::STATUSES]);
    }

    public function show(string $id): void
    {
        $c = $this->load((int) $id);
        $this->view('admin/customer', [
            'title'    => $c['full_name'],
            'c'        => $c,
            'accounts' => Db::all('SELECT a.*, t.name AS type_name FROM accounts a LEFT JOIN account_types t ON t.id = a.account_type_id WHERE a.customer_id = ? ORDER BY a.id', [$c['id']]),
            'managers' => Db::all('SELECT am.id, u.id AS user_id, u.full_name, u.email, am.assigned_at FROM account_managers am JOIN users u ON u.id = am.manager_id WHERE am.customer_id = ?', [$c['id']]),
            'staff'    => Db::all("SELECT DISTINCT u.id, u.full_name FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
                                    WHERE u.user_type = 'staff' AND u.status = 'active' AND r.slug IN ('manager','assistant','director','support') ORDER BY u.full_name"),
            'types'    => Db::all('SELECT slug, name FROM account_types WHERE is_active = 1 ORDER BY id'),
            'audit'    => Db::all("SELECT a.*, u.full_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id
                                    WHERE (a.target_type = 'customer' AND a.target_id = ?) OR (a.target_type = 'user' AND a.target_id = ?)
                                    ORDER BY a.id DESC LIMIT 20", [(string) $c['id'], (string) $c['user_id']]),
            'statuses' => self::STATUSES,
        ]);
    }

    public function create(): void
    {
        $this->view('admin/customer_new', ['title' => 'New customer', 'types' => Db::all('SELECT slug, name FROM account_types WHERE is_active = 1')]);
    }

    public function store(): void
    {
        remember_input();
        $name = input('full_name');
        $username = input('username');
        $email = strtolower(input('email'));
        $errors = [];
        if (mb_strlen($name) < 3) $errors[] = 'Full name is required.';
        if (!preg_match('/^[a-zA-Z0-9_.-]{3,60}$/', $username)) $errors[] = 'Invalid username.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email.';
        $password = (string) ($_POST['password'] ?? '');
        if ($err = self::validatePassword($password)) $errors[] = $err;
        if (!$errors && Db::value('SELECT 1 FROM users WHERE username = ? OR email = ?', [$username, $email])) $errors[] = 'Username or email already exists.';
        if ($errors) {
            flash('error', implode(' ', $errors));
            redirect('/admin/customers/new');
        }
        $cid = Db::transaction(function () use ($name, $username, $email, $password) {
            $uid = Db::insert('users', [
                'user_type' => 'customer', 'username' => $username, 'email' => $email, 'phone' => input('phone') ?: null,
                'full_name' => $name, 'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'status' => 'active',
                'password_changed_at' => now(),
            ]);
            Db::query("INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE slug = 'customer'", [$uid]);
            $cid = Db::insert('customers', [
                'user_id' => $uid, 'customer_number' => 'C' . gmdate('y') . str_pad((string) $uid, 7, '0', STR_PAD_LEFT),
                'date_of_birth' => input('date_of_birth') ?: null, 'address' => input('address') ?: null,
                'country' => input('country') ?: null,
            ]);
            if (($type = input('account_type')) !== '') {
                AccountService::open($cid, $type);
            }
            if (!StaffScope::seesAll()) {
                Db::insert('account_managers', ['customer_id' => $cid, 'manager_id' => Auth::id(), 'assigned_by' => Auth::id()]);
            }
            AuditService::log('customer.created', 'customer', $cid, null, ['username' => $username, 'email' => $email]);
            return $cid;
        });
        clear_old();
        flash('success', 'Customer created.');
        redirect('/admin/customers/' . $cid);
    }

    public function updateStatus(string $id): void
    {
        $c = $this->load((int) $id);
        $status = input('status');
        $back = '/admin/customers/' . $c['id'];
        if (!in_array($status, self::STATUSES, true)) {
            flash('error', 'Invalid status.');
            redirect($back);
        }
        $reason = $this->requireReason($back);
        Db::update('users', ['status' => $status, 'status_reason' => $reason], 'id = ?', [$c['user_id']]);
        if ($status !== 'active') {
            Auth::revokeAllSessions((int) $c['user_id']);
        }
        if ($status === 'active' && !Db::value('SELECT 1 FROM accounts WHERE customer_id = ?', [$c['id']])) {
            AccountService::open((int) $c['id'], (string) setting('default_account_type', 'checking'));
        }
        AuditService::log('customer.status_changed', 'customer', $c['id'], $c['status'], $status, $reason);
        if ($status === 'active') {
            NotificationService::notify((int) $c['user_id'], 'Profile activated', 'Your online banking profile is now active.');
        }
        flash('success', 'Customer status updated to ' . $status . '.');
        redirect($back);
    }

    public function assignManager(string $id): void
    {
        $c = $this->load((int) $id);
        $managerId = (int) input('manager_id');
        $m = Db::one("SELECT id, full_name FROM users WHERE id = ? AND user_type = 'staff' AND status = 'active'", [$managerId]);
        if (!$m) {
            flash('error', 'Select a valid staff member.');
            redirect('/admin/customers/' . $c['id']);
        }
        Db::query('INSERT IGNORE INTO account_managers (customer_id, manager_id, assigned_by) VALUES (?, ?, ?)', [$c['id'], $managerId, Auth::id()]);
        AuditService::log('customer.manager_assigned', 'customer', $c['id'], null, ['manager_id' => $managerId, 'manager' => $m['full_name']]);
        flash('success', $m['full_name'] . ' assigned as account manager.');
        redirect('/admin/customers/' . $c['id']);
    }

    public function removeManager(string $id, string $mid): void
    {
        $c = $this->load((int) $id);
        $row = Db::one('SELECT * FROM account_managers WHERE id = ? AND customer_id = ?', [(int) $mid, $c['id']]);
        if ($row) {
            Db::query('DELETE FROM account_managers WHERE id = ?', [$row['id']]);
            AuditService::log('customer.manager_removed', 'customer', $c['id'], ['manager_id' => $row['manager_id']], null);
        }
        flash('success', 'Manager removed.');
        redirect('/admin/customers/' . $c['id']);
    }

    public function openAccount(string $id): void
    {
        $c = $this->load((int) $id);
        $back = '/admin/customers/' . $c['id'];
        $accId = $this->attempt(fn () => AccountService::open((int) $c['id'], input('account_type'), null, input('nickname') ?: null), $back);
        NotificationService::notify((int) $c['user_id'], 'New account opened', 'A new account has been opened for you.', '/accounts/' . $accId);
        flash('success', 'Account opened.');
        redirect('/admin/accounts/' . $accId);
    }
}
