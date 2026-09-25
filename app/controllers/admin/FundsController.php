<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Db;
use App\Services\FundingService;
use App\Services\Money;
use App\Services\StaffScope;

final class FundsController extends Controller
{
    public function index(): void
    {
        [$scope, $params] = StaffScope::customerFilter('a.customer_id');
        $status = in_array(input('status'), ['pending', 'approved', 'rejected'], true) ? input('status') : 'pending';
        $rows = Db::all(
            "SELECT d.*, a.account_number, u.full_name AS customer_name, r.full_name AS requested_name, v.full_name AS reviewed_name
               FROM deposit_requests d
               JOIN accounts a ON a.id = d.account_id
               LEFT JOIN customers c ON c.id = a.customer_id LEFT JOIN users u ON u.id = c.user_id
               LEFT JOIN users r ON r.id = d.requested_by LEFT JOIN users v ON v.id = d.reviewed_by
              WHERE d.status = ? AND $scope ORDER BY d.id DESC LIMIT 200",
            [$status, ...$params]
        );
        $this->view('admin/funds', ['title' => 'Add funds & adjustments', 'rows' => $rows, 'status' => $status]);
    }

    public function store(string $accountId): void
    {
        $acc = AccountController::loadScoped((int) $accountId);
        $back = '/admin/accounts/' . $acc['id'];
        $kind = input('kind');
        if ($kind === 'deposit' && !can('funds.add')) {
            $this->forbidden();
        }
        if ($kind !== 'deposit' && !can('funds.adjust')) {
            $this->forbidden();
        }
        $amount = Money::parse(input('amount'));
        if ($amount === null || $amount <= 0) {
            flash('error', 'Enter a valid amount.');
            redirect($back);
        }
        $reason = $this->requireReason($back);
        $r = $this->attempt(fn () => FundingService::request((int) $acc['id'], $amount, $kind, $reason, input('external_reference') ?: null, (int) Auth::id()), $back);
        flash('success', $r['status'] === 'approved'
            ? 'Funds posted to the account.'
            : 'Request created and awaiting approval by another authorized staff member.');
        redirect($back);
    }

    private function scopedRequest(int $id): array
    {
        $req = Db::one('SELECT * FROM deposit_requests WHERE id = ?', [$id]);
        if (!$req) {
            $this->notFound();
        }
        AccountController::loadScoped((int) $req['account_id']);
        return $req;
    }

    public function approve(string $id): void
    {
        $this->scopedRequest((int) $id);
        $this->attempt(fn () => FundingService::approve((int) $id, (int) Auth::id(), input('note') ?: null), '/admin/funds');
        flash('success', 'Approved and posted to the ledger.');
        redirect('/admin/funds');
    }

    public function reject(string $id): void
    {
        $this->scopedRequest((int) $id);
        $note = $this->requireReason('/admin/funds', 'note');
        $this->attempt(fn () => FundingService::reject((int) $id, (int) Auth::id(), $note), '/admin/funds');
        flash('success', 'Request rejected.');
        redirect('/admin/funds');
    }
}
