<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Db;
use App\Services\LedgerService;
use App\Services\StaffScope;

final class DashboardController extends Controller
{
    public function index(): void
    {
        [$scope, $sp] = StaffScope::customerFilter('c.id');
        $stats = [
            'customers'        => (int) Db::value("SELECT COUNT(*) FROM customers c WHERE $scope", $sp),
            'active_customers' => (int) Db::value("SELECT COUNT(*) FROM customers c JOIN users u ON u.id = c.user_id WHERE u.status = 'active' AND $scope", $sp),
            'pending_customers'=> (int) Db::value("SELECT COUNT(*) FROM customers c JOIN users u ON u.id = c.user_id WHERE u.status = 'pending' AND $scope", $sp),
            'accounts'         => (int) Db::value("SELECT COUNT(*) FROM accounts a JOIN customers c ON c.id = a.customer_id WHERE $scope", $sp),
            'locked_accounts'  => (int) Db::value("SELECT COUNT(*) FROM accounts a JOIN customers c ON c.id = a.customer_id WHERE a.status IN ('locked','frozen','suspended') AND $scope", $sp),
            'deposits_total'   => (int) Db::value("SELECT COALESCE(SUM(a.balance),0) FROM accounts a JOIN customers c ON c.id = a.customer_id WHERE a.credit_limit = 0 AND $scope", $sp),
            'volume_30d'       => (int) Db::value("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE status = 'completed' AND type <> 'fee' AND created_at > UTC_TIMESTAMP() - INTERVAL 30 DAY"),
            'pending_withdrawals' => (int) Db::value("SELECT COUNT(*) FROM withdrawal_requests WHERE status = 'pending'"),
            'pending_funds'    => (int) Db::value("SELECT COUNT(*) FROM deposit_requests WHERE status = 'pending'"),
            'pending_transfers'=> (int) Db::value("SELECT COUNT(*) FROM transactions WHERE status = 'pending' AND type = 'transfer'"),
            'active_cards'     => (int) Db::value("SELECT COUNT(*) FROM cards c WHERE c.status = 'active' AND " . str_replace('c.id', 'c.customer_id', $scope), $sp),
            'pending_cards'    => (int) Db::value("SELECT COUNT(*) FROM cards WHERE status = 'pending'"),
            'failed_logins_24h'=> (int) Db::value("SELECT COUNT(*) FROM login_attempts WHERE success = 0 AND created_at > UTC_TIMESTAMP() - INTERVAL 1 DAY"),
        ];
        $daily = Db::all(
            "SELECT DATE(created_at) AS d, type, SUM(amount) AS total FROM transactions
              WHERE status = 'completed' AND type IN ('deposit','withdrawal','transfer') AND created_at > UTC_TIMESTAMP() - INTERVAL 14 DAY
              GROUP BY DATE(created_at), type ORDER BY d"
        );
        $series = [];
        for ($i = 13; $i >= 0; $i--) {
            $series[gmdate('Y-m-d', strtotime("-$i days"))] = ['deposit' => 0, 'withdrawal' => 0, 'transfer' => 0];
        }
        foreach ($daily as $r) {
            if (isset($series[$r['d']])) {
                $series[$r['d']][$r['type']] = (int) $r['total'];
            }
        }
        $this->view('admin/dashboard', [
            'title'  => 'Overview',
            'stats'  => $stats,
            'series' => $series,
            'recent' => Db::all('SELECT a.*, u.full_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT 12'),
            'alerts' => Db::all("SELECT a.*, u.full_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id
                                  WHERE a.action IN ('auth.login_throttled','risk.velocity_block','auth.login_blocked')
                                    AND a.created_at > UTC_TIMESTAMP() - INTERVAL 7 DAY ORDER BY a.id DESC LIMIT 8"),
            'breaks' => can('audit.view') ? LedgerService::reconciliationBreaks() : [],
        ]);
    }
}
