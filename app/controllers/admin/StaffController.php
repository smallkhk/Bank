<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Db;
use App\Services\AuditService;

final class StaffController extends Controller
{
    private function assignableRoles(): array
    {
        $roles = Db::all("SELECT * FROM roles WHERE slug <> 'customer' ORDER BY id");
        // Only a Super Admin may create or grant Super Admin.
        if (!in_array('super_admin', Auth::roles(), true)) {
            $roles = array_values(array_filter($roles, fn ($r) => $r['slug'] !== 'super_admin'));
        }
        return $roles;
    }

    public function index(): void
    {
        $staff = Db::all("SELECT u.*, GROUP_CONCAT(r.name ORDER BY r.id SEPARATOR ', ') AS role_names, GROUP_CONCAT(r.id) AS role_ids,
                                 (SELECT COUNT(*) FROM account_managers am WHERE am.manager_id = u.id) AS assigned
                            FROM users u LEFT JOIN user_roles ur ON ur.user_id = u.id LEFT JOIN roles r ON r.id = ur.role_id
                           WHERE u.user_type = 'staff' GROUP BY u.id ORDER BY u.full_name");
        $this->view('admin/staff', ['title' => 'Staff', 'staff' => $staff, 'roles' => $this->assignableRoles()]);
    }

    public function store(): void
    {
        remember_input();
        $name = input('full_name');
        $username = input('username');
        $email = strtolower(input('email'));
        $roleId = (int) input('role_id');
        $password = (string) ($_POST['password'] ?? '');
        $errors = [];
        if (mb_strlen($name) < 3) $errors[] = 'Full name is required.';
        if (!preg_match('/^[a-zA-Z0-9_.-]{3,60}$/', $username)) $errors[] = 'Invalid username.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email.';
        if (strlen($password) < 12) $errors[] = 'Staff passwords must be at least 12 characters.';
        if (!in_array($roleId, array_map('intval', array_column($this->assignableRoles(), 'id')), true)) $errors[] = 'Select a role.';
        if (!$errors && Db::value('SELECT 1 FROM users WHERE username = ? OR email = ?', [$username, $email])) $errors[] = 'Username or email already exists.';
        if ($errors) {
            flash('error', implode(' ', $errors));
            redirect('/admin/staff');
        }
        $uid = Db::transaction(function () use ($name, $username, $email, $password, $roleId) {
            $uid = Db::insert('users', [
                'user_type' => 'staff', 'username' => $username, 'email' => $email, 'full_name' => $name,
                'phone' => input('phone') ?: null, 'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'status' => 'active', 'password_changed_at' => now(),
            ]);
            Db::insert('user_roles', ['user_id' => $uid, 'role_id' => $roleId]);
            AuditService::log('staff.created', 'user', $uid, null, ['username' => $username, 'role_id' => $roleId]);
            return $uid;
        });
        clear_old();
        flash('success', "Staff member $name created.");
        redirect('/admin/staff');
    }

    public function update(string $id): void
    {
        $user = Db::one("SELECT * FROM users WHERE id = ? AND user_type = 'staff'", [(int) $id]);
        if (!$user) {
            $this->notFound();
        }
        if ((int) $user['id'] === Auth::id()) {
            flash('error', 'You cannot change your own role or status.');
            redirect('/admin/staff');
        }
        $targetRoles = Auth::roles((int) $user['id']);
        if (in_array('super_admin', $targetRoles, true) && !in_array('super_admin', Auth::roles(), true)) {
            $this->forbidden();
        }
        $reason = $this->requireReason('/admin/staff');
        $status = input('status');
        if (in_array($status, ['active', 'suspended', 'locked'], true) && $status !== $user['status']) {
            Db::update('users', ['status' => $status, 'status_reason' => $reason], 'id = ?', [$user['id']]);
            if ($status !== 'active') {
                Auth::revokeAllSessions((int) $user['id']);
            }
            AuditService::log('staff.status_changed', 'user', $user['id'], $user['status'], $status, $reason);
        }
        $roleId = (int) input('role_id');
        $allowed = array_map('intval', array_column($this->assignableRoles(), 'id'));
        if ($roleId && in_array($roleId, $allowed, true)) {
            $old = Db::all('SELECT role_id FROM user_roles WHERE user_id = ?', [$user['id']]);
            $oldIds = array_map('intval', array_column($old, 'role_id'));
            if ($oldIds !== [$roleId]) {
                Db::transaction(function () use ($user, $roleId) {
                    Db::query('DELETE FROM user_roles WHERE user_id = ?', [$user['id']]);
                    Db::insert('user_roles', ['user_id' => $user['id'], 'role_id' => $roleId]);
                });
                AuditService::log('staff.role_changed', 'user', $user['id'], $oldIds, [$roleId], $reason);
            }
        }
        flash('success', 'Staff member updated.');
        redirect('/admin/staff');
    }
}
