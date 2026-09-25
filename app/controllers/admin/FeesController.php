<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Db;
use App\Services\FeeService;

final class FeesController extends Controller
{
    public function index(): void
    {
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', input('from')) ? input('from') : gmdate('Y-m-01', strtotime('-5 months'));
        $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', input('to')) ? input('to') : gmdate('Y-m-d');
        $range = [$from . ' 00:00:00', $to . ' 23:59:59'];
        $this->view('admin/fees', [
            'title' => 'Fees', 'from' => $from, 'to' => $to,
            'byMonth' => Db::all("SELECT DATE_FORMAT(created_at, '%Y-%m') AS month, COUNT(*) AS n, SUM(amount) AS total
                                    FROM transactions WHERE type = 'fee' AND status = 'completed' AND created_at BETWEEN ? AND ?
                                   GROUP BY month ORDER BY month DESC", $range),
            'byKind' => Db::all("SELECT CASE WHEN description LIKE 'Monthly account fee%' THEN 'Monthly account fee'
                                             WHEN description LIKE 'Fee:%' THEN 'Custom fee' ELSE description END AS kind,
                                        COUNT(*) AS n, SUM(amount) AS total
                                   FROM transactions WHERE type = 'fee' AND status = 'completed' AND created_at BETWEEN ? AND ?
                                  GROUP BY kind ORDER BY total DESC", $range),
            'income' => (int) Db::value("SELECT balance FROM accounts WHERE system_code = 'SYS-FEES'"),
            'runs' => Db::all("SELECT period, SUM(status='charged') AS charged, SUM(status='skipped') AS skipped,
                                      SUM(CASE WHEN status='charged' THEN amount ELSE 0 END) AS total, MAX(created_at) AS ran_at
                                 FROM fee_runs WHERE fee_code = 'monthly' GROUP BY period ORDER BY period DESC LIMIT 12"),
        ]);
    }

    public function runMonthly(): void
    {
        $period = input('period');
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) || $period > gmdate('Y-m')) {
            flash('error', 'Choose a valid past or current month.');
            redirect('/admin/fees');
        }
        $r = FeeService::runMonthly($period);
        flash('success', sprintf('Monthly fees for %s: %d charged (%s), %d skipped or waived, %d already processed.',
            $period, $r['charged'], money($r['total']), $r['skipped'], $r['already']));
        redirect('/admin/fees');
    }
}
