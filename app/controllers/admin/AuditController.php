<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Db;

final class AuditController extends Controller
{
    public function index(): void
    {
        $where = '1=1';
        $params = [];
        if (($v = input('action')) !== '') { $where .= ' AND a.action LIKE ?'; $params[] = $v . '%'; }
        if (($v = input('user')) !== '') { $where .= ' AND (u.username LIKE ? OR u.full_name LIKE ?)'; array_push($params, "%$v%", "%$v%"); }
        if (($v = input('target_type')) !== '') { $where .= ' AND a.target_type = ?'; $params[] = $v; }
        if (($v = input('target_id')) !== '') { $where .= ' AND a.target_id = ?'; $params[] = $v; }
        if (($v = input('ip')) !== '') { $where .= ' AND a.ip_address = ?'; $params[] = $v; }
        if (($v = input('from')) !== '') { $where .= ' AND a.created_at >= ?'; $params[] = $v . ' 00:00:00'; }
        if (($v = input('to')) !== '') { $where .= ' AND a.created_at <= ?'; $params[] = $v . ' 23:59:59'; }

        $total = (int) Db::value("SELECT COUNT(*) FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id WHERE $where", $params);
        $p = paginate($total, 50);
        $rows = Db::all("SELECT a.*, u.username, u.full_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id WHERE $where ORDER BY a.id DESC LIMIT {$p['limit']} OFFSET {$p['offset']}", $params);
        $this->view('admin/audit', [
            'title' => 'Audit log', 'rows' => $rows, 'pager' => $p,
            'actions' => array_column(Db::all('SELECT DISTINCT action FROM audit_logs ORDER BY action'), 'action'),
        ]);
    }
}
