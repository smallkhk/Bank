<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Db;
use App\Services\AuditService;

final class RoleController extends Controller
{
    public function index(): void
    {
        $roles = Db::all("SELECT * FROM roles WHERE slug NOT IN ('super_admin','customer') ORDER BY id");
        $grants = [];
        foreach (Db::all('SELECT role_id, permission_id FROM role_permissions') as $g) {
            $grants[$g['role_id']][$g['permission_id']] = true;
        }
        $this->view('admin/roles', [
            'title' => 'Roles & permissions', 'roles' => $roles, 'grants' => $grants,
            'permissions' => Db::all('SELECT * FROM permissions ORDER BY slug'),
        ]);
    }

    public function update(string $id): void
    {
        $role = Db::one("SELECT * FROM roles WHERE id = ? AND slug NOT IN ('super_admin','customer')", [(int) $id]);
        if (!$role) {
            $this->notFound();
        }
        $valid = array_map('intval', array_column(Db::all('SELECT id FROM permissions'), 'id'));
        $selected = array_values(array_intersect($valid, array_map('intval', (array) ($_POST['permissions'] ?? []))));
        $old = array_map('intval', array_column(Db::all('SELECT permission_id FROM role_permissions WHERE role_id = ?', [$role['id']]), 'permission_id'));
        Db::transaction(function () use ($role, $selected) {
            Db::query('DELETE FROM role_permissions WHERE role_id = ?', [$role['id']]);
            foreach ($selected as $pid) {
                Db::insert('role_permissions', ['role_id' => $role['id'], 'permission_id' => $pid]);
            }
        });
        $slugs = fn (array $ids) => $ids ? array_column(Db::all('SELECT slug FROM permissions WHERE id IN (' . implode(',', $ids) . ')'), 'slug') : [];
        AuditService::log('role.permissions_changed', 'role', $role['id'], $slugs($old), $slugs($selected));
        flash('success', $role['name'] . ' permissions saved.');
        redirect('/admin/roles');
    }
}
