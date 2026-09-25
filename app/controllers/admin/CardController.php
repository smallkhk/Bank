<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Db;
use App\Services\AccountService;
use App\Services\CardService;
use App\Services\CreditCardService;
use App\Services\Money;
use App\Services\StaffScope;

final class CardController extends Controller
{
    private function load(int $id): array
    {
        $card = CardService::find($id);
        if (!$card) {
            $this->notFound();
        }
        if (!StaffScope::canAccessCustomer((int) $card['customer_id'])) {
            $this->forbidden();
        }
        return $card;
    }

    public function index(): void
    {
        [$scope, $params] = StaffScope::customerFilter('c.customer_id');
        $tab = in_array(input('tab'), ['pending', 'active', 'frozen', 'blocked', 'all'], true) ? input('tab') : 'active';
        $where = $scope;
        if ($tab !== 'all') {
            $where .= ' AND c.status = ?';
            $params[] = $tab;
        }
        if (($q = input('q')) !== '') {
            $where .= ' AND (u.full_name LIKE ? OR c.pan_last4 = ? OR a.account_number LIKE ?)';
            array_push($params, "%$q%", $q, "%$q%");
        }
        $from = 'FROM cards c JOIN card_products p ON p.id = c.product_id JOIN customers cu ON cu.id = c.customer_id
                 JOIN users u ON u.id = cu.user_id LEFT JOIN accounts a ON a.id = c.account_id';
        $total = (int) Db::value("SELECT COUNT(*) $from WHERE $where", $params);
        $p = paginate($total, 30);
        $this->view('admin/cards', [
            'title' => 'Cards', 'tab' => $tab, 'pager' => $p,
            'rows' => Db::all("SELECT c.*, p.name AS product_name, p.card_type, u.full_name AS customer_name, a.account_number
                               $from WHERE $where ORDER BY c.id DESC LIMIT {$p['limit']} OFFSET {$p['offset']}", $params),
            'pendingCount' => (int) Db::value("SELECT COUNT(*) FROM cards c WHERE c.status = 'pending' AND " . StaffScope::customerFilter('c.customer_id')[0], StaffScope::customerFilter('c.customer_id')[1]),
        ]);
    }

    public function show(string $id): void
    {
        $card = $this->load((int) $id);
        $acc = $card['account_id'] ? AccountService::find((int) $card['account_id']) : null;
        $this->view('admin/card', [
            'title' => $card['product_name'] . ' •' . ($card['pan_last4'] ?? 'pending'), 'card' => $card, 'acc' => $acc,
            'txs' => Db::all('SELECT ct.*, u.full_name AS created_name FROM card_transactions ct LEFT JOIN users u ON u.id = ct.created_by WHERE ct.card_id = ? ORDER BY ct.id DESC LIMIT 100', [$card['id']]),
            'statements' => $acc && AccountService::isCredit($acc) ? Db::all('SELECT * FROM credit_statements WHERE account_id = ? ORDER BY period_end DESC LIMIT 12', [$acc['id']]) : [],
            'owed' => $acc && AccountService::isCredit($acc) ? CreditCardService::owed($acc) : null,
            'audit' => Db::all("SELECT a.*, u.full_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id WHERE a.target_type = 'card' AND a.target_id = ? ORDER BY a.id DESC LIMIT 20", [(string) $card['id']]),
        ]);
    }

    public function store(string $customerId): void
    {
        $cid = (int) $customerId;
        if (!StaffScope::canAccessCustomer($cid)) {
            $this->forbidden();
        }
        $cardId = $this->attempt(fn () => CardService::request($cid, (int) input('product_id'), (int) input('account_id') ?: null, (int) Auth::id()), '/admin/customers/' . $cid);
        flash('success', 'Card request created. Another authorized staff member must issue it.');
        redirect('/admin/cards/' . $cardId);
    }

    public function issue(string $id): void
    {
        $card = $this->load((int) $id);
        $this->attempt(fn () => CardService::issue((int) $card['id'], (int) Auth::id()), '/admin/cards/' . $card['id']);
        flash('success', 'Card issued and activated.');
        redirect('/admin/cards/' . $card['id']);
    }

    public function reject(string $id): void
    {
        $card = $this->load((int) $id);
        $reason = $this->requireReason('/admin/cards/' . $card['id']);
        $this->attempt(fn () => CardService::reject((int) $card['id'], (int) Auth::id(), $reason), '/admin/cards/' . $card['id']);
        flash('success', 'Card request rejected.');
        redirect('/admin/cards?tab=pending');
    }

    public function status(string $id): void
    {
        $card = $this->load((int) $id);
        $back = '/admin/cards/' . $card['id'];
        $reason = $this->requireReason($back);
        $this->attempt(fn () => CardService::setStatus($card, input('status'), $reason), $back);
        flash('success', 'Card status updated.');
        redirect($back);
    }

    public function replace(string $id): void
    {
        $card = $this->load((int) $id);
        $reason = $this->requireReason('/admin/cards/' . $card['id']);
        $newId = $this->attempt(fn () => CardService::replace($card, $reason, (int) Auth::id()), '/admin/cards/' . $card['id']);
        flash('success', 'Old card blocked. Replacement created and awaiting issue.');
        redirect('/admin/cards/' . $newId);
    }

    public function controls(string $id): void
    {
        $card = $this->load((int) $id);
        $back = '/admin/cards/' . $card['id'];
        $limit = input('daily_limit') === '' ? null : Money::parse(input('daily_limit'));
        $this->attempt(fn () => CardService::updateControls($card, input('online') === '1', input('atm') === '1', input('international') === '1', $limit), $back);
        flash('success', 'Card controls saved.');
        redirect($back);
    }

    /** Simulated card network: authorise a purchase/ATM withdrawal against this card. */
    public function simulate(string $id): void
    {
        $card = $this->load((int) $id);
        $back = '/admin/cards/' . $card['id'];
        $amount = Money::parse(input('amount'));
        if ($amount === null) {
            flash('error', 'Enter a valid amount.');
            redirect($back);
        }
        $r = $this->attempt(fn () => CardService::authorize((int) $card['id'], $amount, input('merchant'), input('category') ?: null, input('channel'), strtoupper(input('country'))), $back);
        flash($r['status'] === 'approved' ? 'success' : 'error', $r['status'] === 'approved' ? 'Transaction approved and posted.' : 'Transaction declined: ' . $r['reason']);
        redirect($back);
    }

    public function reverse(string $id, string $txId): void
    {
        $card = $this->load((int) $id);
        $back = '/admin/cards/' . $card['id'];
        $reason = $this->requireReason($back);
        if (!Db::value('SELECT 1 FROM card_transactions WHERE id = ? AND card_id = ?', [(int) $txId, $card['id']])) {
            $this->notFound();
        }
        $this->attempt(fn () => CardService::reverse((int) $txId, $reason), $back);
        flash('success', 'Card transaction reversed and refunded.');
        redirect($back);
    }
}
