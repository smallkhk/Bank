<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Db;
use App\Services\Money;
use App\Services\StaffScope;
use App\Services\WithdrawalService;

final class WithdrawalController extends Controller
{
    public function index(): void
    {
        [$scope, $params] = StaffScope::customerFilter('a.customer_id');
        $statuses = ['pending', 'completed', 'rejected', 'cancelled'];
        $status = in_array(input('status'), $statuses, true) ? input('status') : 'pending';
        $rows = Db::all(
            "SELECT w.*, a.account_number, a.balance, a.held_amount, u.full_name AS customer_name, r.full_name AS requested_name, v.full_name AS reviewed_name
               FROM withdrawal_requests w
               JOIN accounts a ON a.id = w.account_id
               LEFT JOIN customers c ON c.id = a.customer_id LEFT JOIN users u ON u.id = c.user_id
               LEFT JOIN users r ON r.id = w.requested_by LEFT JOIN users v ON v.id = w.reviewed_by
              WHERE w.status = ? AND $scope ORDER BY w.id DESC LIMIT 200",
            [$status, ...$params]
        );
        $this->view('admin/withdrawals', ['title' => 'Withdrawals', 'rows' => $rows, 'status' => $status, 'statuses' => $statuses]);
    }

    public function store(string $accountId): void
    {
        $acc = AccountController::loadScoped((int) $accountId);
        $back = '/admin/accounts/' . $acc['id'];
        $amount = Money::parse(input('amount'));
        if ($amount === null || $amount <= 0) {
            flash('error', 'Enter a valid amount.');
            redirect($back);
        }
        $reason = $this->requireReason($back);
        $this->attempt(fn () => WithdrawalService::request((int) $acc['id'], $amount, 'Staff-initiated', $reason, (int) Auth::id()), $back);
        flash('success', 'Withdrawal request created; it must be approved by another authorized staff member.');
        redirect($back);
    }

    private function scoped(int $id): void
    {
        $req = Db::one('SELECT account_id FROM withdrawal_requests WHERE id = ?', [$id]);
        if (!$req) {
            $this->notFound();
        }
        AccountController::loadScoped((int) $req['account_id']);
    }

    public function approve(string $id): void
    {
        $this->scoped((int) $id);
        $this->attempt(fn () => WithdrawalService::approve((int) $id, (int) Auth::id(), input('note') ?: null), '/admin/withdrawals');
        flash('success', 'Withdrawal approved and posted to the ledger.');
        redirect('/admin/withdrawals');
    }

    public function reject(string $id): void
    {
        $this->scoped((int) $id);
        $note = $this->requireReason('/admin/withdrawals', 'note');
        $this->attempt(fn () => WithdrawalService::reject((int) $id, (int) Auth::id(), $note), '/admin/withdrawals');
        flash('success', 'Withdrawal rejected; held funds released.');
        redirect('/admin/withdrawals');
    }
}
