<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Db;
use App\Services\Money;
use App\Services\StaffScope;
use App\Services\TransferService;

final class TransactionController extends Controller
{
    public const TYPES = ['deposit', 'withdrawal', 'transfer', 'fee', 'refund', 'adjustment', 'card', 'investment', 'crypto'];
    public const STATUSES = ['pending', 'completed', 'failed', 'reversed', 'cancelled'];

    public function index(): void
    {
        $where = '1=1';
        $params = [];
        if (!StaffScope::seesAll()) {
            [$s1, $p1] = StaffScope::customerFilter('fa.customer_id');
            [$s2, $p2] = StaffScope::customerFilter('ta.customer_id');
            $where = "($s1 OR $s2)";
            $params = [...$p1, ...$p2];
        }
        if (($q = input('q')) !== '') {
            $where .= ' AND (t.reference LIKE ? OR t.description LIKE ? OR fa.account_number LIKE ? OR ta.account_number LIKE ?)';
            array_push($params, "%$q%", "%$q%", "%$q%", "%$q%");
        }
        if (in_array($v = input('type'), self::TYPES, true)) { $where .= ' AND t.type = ?'; $params[] = $v; }
        if (in_array($v = input('status'), self::STATUSES, true)) { $where .= ' AND t.status = ?'; $params[] = $v; }
        if (($v = input('from')) !== '') { $where .= ' AND t.created_at >= ?'; $params[] = $v . ' 00:00:00'; }
        if (($v = input('to')) !== '') { $where .= ' AND t.created_at <= ?'; $params[] = $v . ' 23:59:59'; }
        if (($v = Money::parse(input('min'))) !== null) { $where .= ' AND t.amount >= ?'; $params[] = $v; }
        if (($v = Money::parse(input('max'))) !== null) { $where .= ' AND t.amount <= ?'; $params[] = $v; }

        $from = 'FROM transactions t LEFT JOIN accounts fa ON fa.id = t.from_account_id LEFT JOIN accounts ta ON ta.id = t.to_account_id';
        $total = (int) Db::value("SELECT COUNT(*) $from WHERE $where", $params);
        $p = paginate($total, 50);
        $rows = Db::all("SELECT t.*, fa.account_number AS from_number, ta.account_number AS to_number $from WHERE $where ORDER BY t.id DESC LIMIT {$p['limit']} OFFSET {$p['offset']}", $params);

        if (input('export') === 'csv') {
            $this->csv(Db::all("SELECT t.*, fa.account_number AS from_number, ta.account_number AS to_number $from WHERE $where ORDER BY t.id DESC LIMIT 10000", $params));
        }
        $this->view('admin/transactions', ['title' => 'Transactions', 'rows' => $rows, 'pager' => $p, 'types' => self::TYPES, 'statuses' => self::STATUSES]);
    }

    private function csv(array $rows): never
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="transactions-' . gmdate('Ymd-His') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Reference', 'Type', 'Status', 'Amount', 'Fee', 'Currency', 'From', 'To', 'Description', 'Created (UTC)'], ",", "\"", "");
        foreach ($rows as $r) {
            $cells = [$r['reference'], $r['type'], $r['status'], Money::toDecimal((int) $r['amount']), Money::toDecimal((int) $r['fee_amount']),
                $r['currency'], $r['from_number'], $r['to_number'], $r['description'], $r['created_at']];
            // Neutralise spreadsheet formula injection.
            fputcsv($out, array_map(fn ($c) => is_string($c) && preg_match('/^[=+\-@]/', $c) ? "'" . $c : $c, $cells), ",", "\"", "");
        }
        exit;
    }

    private function load(int $id): array
    {
        $tx = Db::one(
            'SELECT t.*, fa.account_number AS from_number, fa.customer_id AS from_customer, ta.account_number AS to_number, ta.customer_id AS to_customer,
                    iu.full_name AS initiated_name, au.full_name AS approved_name
               FROM transactions t
               LEFT JOIN accounts fa ON fa.id = t.from_account_id LEFT JOIN accounts ta ON ta.id = t.to_account_id
               LEFT JOIN users iu ON iu.id = t.initiated_by LEFT JOIN users au ON au.id = t.approved_by
              WHERE t.id = ?', [$id]);
        if (!$tx) {
            $this->notFound();
        }
        $ok = StaffScope::seesAll()
            || ($tx['from_customer'] && StaffScope::canAccessCustomer((int) $tx['from_customer']))
            || ($tx['to_customer'] && StaffScope::canAccessCustomer((int) $tx['to_customer']));
        if (!$ok) {
            $this->forbidden();
        }
        return $tx;
    }

    public function show(string $id): void
    {
        $tx = $this->load((int) $id);
        $this->view('admin/transaction', [
            'title'   => 'Transaction ' . $tx['reference'],
            'tx'      => $tx,
            'entries' => Db::all('SELECT l.*, a.account_number FROM ledger_entries l JOIN accounts a ON a.id = l.account_id WHERE l.transaction_id = ? ORDER BY l.id', [$tx['id']]),
            'related' => Db::all('SELECT * FROM transactions WHERE parent_id = ? OR id = ?', [$tx['id'], (int) $tx['parent_id']]),
            'approvals' => Db::all("SELECT ta.*, u.full_name FROM transaction_approvals ta JOIN users u ON u.id = ta.decided_by WHERE subject_type = 'transaction' AND subject_id = ?", [$tx['id']]),
        ]);
    }

    public function approve(string $id): void
    {
        $tx = $this->load((int) $id);
        $back = '/admin/transactions/' . $tx['id'];
        $this->attempt(fn () => TransferService::approve((int) $tx['id'], (int) Auth::id(), input('note') ?: null), $back);
        flash('success', 'Transfer approved and posted.');
        redirect($back);
    }

    public function reject(string $id): void
    {
        $tx = $this->load((int) $id);
        $back = '/admin/transactions/' . $tx['id'];
        $note = $this->requireReason($back, 'note');
        $this->attempt(fn () => TransferService::reject((int) $tx['id'], (int) Auth::id(), $note), $back);
        flash('success', 'Transfer rejected; held funds released.');
        redirect($back);
    }
}
