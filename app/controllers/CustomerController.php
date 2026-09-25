<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Services\AccountService;
use App\Services\AuditService;
use App\Services\FundingService;
use App\Services\Money;
use App\Services\StatementService;
use App\Services\TransferService;
use App\Services\WithdrawalService;

final class CustomerController extends Controller
{
    private function customerId(): int
    {
        if (setting('maintenance_mode') === '1') {
            Auth::logout();
            $this->view('errors/message', ['title' => 'Maintenance', 'message' => setting('maintenance_message')], 'public');
            exit;
        }
        $id = Auth::customerId();
        if (!$id) {
            $this->forbidden();
        }
        return $id;
    }

    private function myAccounts(): array
    {
        return Db::all(
            'SELECT a.*, t.name AS type_name FROM accounts a LEFT JOIN account_types t ON t.id = a.account_type_id
              WHERE a.customer_id = ? AND a.status <> ? ORDER BY a.id',
            [$this->customerId(), 'closed']
        );
    }

    /** Load an account and verify it belongs to the logged-in customer. */
    private function ownAccount(int $id): array
    {
        $acc = AccountService::find($id);
        if (!$acc || (int) $acc['customer_id'] !== $this->customerId()) {
            $this->notFound();
        }
        return $acc;
    }

    private function accountIds(): array
    {
        return array_map('intval', array_column($this->myAccounts(), 'id')) ?: [0];
    }

    /** Transactions touching any of the given accounts, with the signed amount from the customer's view. */
    private function history(array $accountIds, int $limit, int $offset = 0, array $filters = []): array
    {
        $in = implode(',', array_fill(0, count($accountIds), '?'));
        [$where, $params] = $this->historyWhere($filters);
        return Db::all(
            "SELECT l.id AS entry_id, l.entry_type, l.amount, l.balance_after, l.created_at, l.account_id,
                    t.reference, t.type, t.status, t.description, a.account_number
               FROM ledger_entries l
               JOIN transactions t ON t.id = l.transaction_id
               JOIN accounts a ON a.id = l.account_id
              WHERE l.account_id IN ($in) $where
              ORDER BY l.id DESC LIMIT $limit OFFSET $offset",
            [...$accountIds, ...$params]
        );
    }

    private function historyWhere(array $f): array
    {
        $where = '';
        $params = [];
        if (!empty($f['from'])) { $where .= ' AND l.created_at >= ?'; $params[] = $f['from'] . ' 00:00:00'; }
        if (!empty($f['to']))   { $where .= ' AND l.created_at <= ?'; $params[] = $f['to'] . ' 23:59:59'; }
        if (!empty($f['type'])) { $where .= ' AND t.type = ?'; $params[] = $f['type']; }
        if (!empty($f['q']))    { $where .= ' AND (t.reference LIKE ? OR t.description LIKE ?)'; $params[] = '%' . $f['q'] . '%'; $params[] = '%' . $f['q'] . '%'; }
        return [$where, $params];
    }

    private function dateFilters(): array
    {
        $f = [];
        foreach (['from', 'to'] as $k) {
            $v = input($k);
            if ($v !== '' && \DateTimeImmutable::createFromFormat('!Y-m-d', $v)) {
                $f[$k] = $v;
            }
        }
        $type = input('type');
        if (in_array($type, ['deposit', 'withdrawal', 'transfer', 'fee', 'refund', 'adjustment'], true)) {
            $f['type'] = $type;
        }
        if (($q = input('q')) !== '') {
            $f['q'] = mb_substr($q, 0, 50);
        }
        return $f;
    }

    public function dashboard(): void
    {
        $accounts = $this->myAccounts();
        $ids = array_map('intval', array_column($accounts, 'id')) ?: [0];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $this->view('customer/dashboard', [
            'title'    => 'Dashboard',
            'accounts' => $accounts,
            'total'    => array_sum(array_column($accounts, 'balance')),
            'available'=> array_sum(array_map(fn ($a) => AccountService::available($a), $accounts)),
            'recent'   => $this->history($ids, 8),
            'pendingWithdrawals' => (int) Db::value("SELECT COUNT(*) FROM withdrawal_requests WHERE account_id IN ($in) AND status = 'pending'", $ids),
        ]);
    }

    public function accounts(): void
    {
        $this->view('customer/accounts', ['title' => 'Accounts', 'accounts' => $this->myAccounts()]);
    }

    public function account(string $id): void
    {
        $acc = $this->ownAccount((int) $id);
        $filters = $this->dateFilters();
        [$where, $params] = $this->historyWhere($filters);
        $total = (int) Db::value("SELECT COUNT(*) FROM ledger_entries l JOIN transactions t ON t.id = l.transaction_id WHERE l.account_id = ? $where", [$acc['id'], ...$params]);
        $p = paginate($total, 25);

        $statement = null;
        if (!empty($filters['from']) || !empty($filters['to'])) {
            $statement = StatementService::build((int) $acc['id'], $filters['from'] ?? '2000-01-01', $filters['to'] ?? gmdate('Y-m-d'), 1);
        }

        $this->view('customer/account', [
            'title'   => $acc['type_name'] . ' account',
            'account' => $acc,
            'limits'  => AccountService::limits($acc),
            'restrictions' => AccountService::activeRestrictions((int) $acc['id']),
            'entries' => $this->history([(int) $acc['id']], $p['limit'], $p['offset'], $filters),
            'pager'   => $p,
            'filters' => $filters,
            'statement' => $statement,
        ]);
    }

    /** Download a PDF statement for a date range (defaults to the current month). */
    public function statement(string $id): void
    {
        $acc = $this->ownAccount((int) $id);
        self::sendStatement($acc, input('from'), input('to'));
    }

    public static function sendStatement(array $acc, string $from, string $to): never
    {
        $valid = fn (string $d) => $d !== '' && \DateTimeImmutable::createFromFormat('!Y-m-d', $d) !== false;
        $from = $valid($from) ? $from : gmdate('Y-m-01');
        $to = $valid($to) ? $to : gmdate('Y-m-d');
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        $pdf = StatementService::pdf($acc, StatementService::build((int) $acc['id'], $from, $to));
        AuditService::log('statement.downloaded', 'account', $acc['id'], null, ['from' => $from, 'to' => $to]);
        header('Content-Type: application/pdf');
        header('Content-Length: ' . strlen($pdf));
        header('Content-Disposition: attachment; filename="statement-' . $acc['account_number'] . '-' . $from . '-to-' . $to . '.pdf"');
        header('Cache-Control: private, no-store');
        echo $pdf;
        exit;
    }

    public function transactions(): void
    {
        $ids = $this->accountIds();
        $filters = $this->dateFilters();
        [$where, $params] = $this->historyWhere($filters);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $total = (int) Db::value("SELECT COUNT(*) FROM ledger_entries l JOIN transactions t ON t.id = l.transaction_id WHERE l.account_id IN ($in) $where", [...$ids, ...$params]);
        $p = paginate($total, 25);
        $pending = Db::all(
            "SELECT * FROM transactions WHERE from_account_id IN ($in) AND status = 'pending' ORDER BY id DESC", $ids
        );
        $this->view('customer/transactions', [
            'title' => 'Transactions', 'entries' => $this->history($ids, $p['limit'], $p['offset'], $filters),
            'pager' => $p, 'filters' => $filters, 'pending' => $pending,
        ]);
    }

    public function transaction(string $ref): void
    {
        $ids = $this->accountIds();
        $in = implode(',', array_fill(0, count($ids), '?'));
        $tx = Db::one(
            "SELECT t.*, fa.account_number AS from_number, ta.account_number AS to_number,
                    fu.full_name AS from_name, tu.full_name AS to_name
               FROM transactions t
               LEFT JOIN accounts fa ON fa.id = t.from_account_id LEFT JOIN customers fc ON fc.id = fa.customer_id LEFT JOIN users fu ON fu.id = fc.user_id
               LEFT JOIN accounts ta ON ta.id = t.to_account_id LEFT JOIN customers tc ON tc.id = ta.customer_id LEFT JOIN users tu ON tu.id = tc.user_id
              WHERE t.reference = ? AND (t.from_account_id IN ($in) OR t.to_account_id IN ($in))",
            [$ref, ...$ids, ...$ids]
        );
        if (!$tx) {
            $this->notFound();
        }
        $this->view('customer/transaction', ['title' => 'Transaction ' . $tx['reference'], 'tx' => $tx, 'myIds' => $ids]);
    }

    public function transferForm(): void
    {
        $this->view('customer/transfer', [
            'title' => 'Transfer money', 'accounts' => $this->myAccounts(),
            'feeFixed' => (int) setting('transfer_fee_fixed', '0'), 'feeBps' => (int) setting('transfer_fee_bps', '0'),
        ]);
    }

    public function transfer(): void
    {
        $from = $this->ownAccount((int) input('from_account'));
        $amount = Money::parse(input('amount'));
        if ($amount === null || $amount <= 0) {
            remember_input();
            flash('error', 'Enter a valid amount, e.g. 150.00');
            redirect('/transfer');
        }
        if (setting('confirm_password_for_transfers') === '1'
            && !password_verify((string) ($_POST['password'] ?? ''), Auth::user()['password_hash'])) {
            remember_input();
            AuditService::log('transfer.password_confirmation_failed', 'account', $from['id']);
            flash('error', 'Password confirmation failed.');
            redirect('/transfer');
        }
        $result = $this->attempt(fn () => TransferService::transfer(
            (int) $from['id'], input('to_account'), $amount,
            mb_substr(input('description'), 0, 140), mb_substr(input('reference'), 0, 60) ?: null, (int) Auth::id()
        ), '/transfer');
        clear_old();
        flash('success', $result['status'] === 'pending'
            ? 'Your transfer ' . $result['reference'] . ' is awaiting approval. The funds are on hold.'
            : 'Transfer ' . $result['reference'] . ' completed successfully.');
        redirect('/transactions/' . $result['reference']);
    }

    public function withdrawals(): void
    {
        $ids = $this->accountIds();
        $in = implode(',', array_fill(0, count($ids), '?'));
        $this->view('customer/withdrawals', [
            'title' => 'Withdrawals', 'accounts' => $this->myAccounts(), 'fee' => WithdrawalService::fee(),
            'requests' => Db::all("SELECT w.*, a.account_number FROM withdrawal_requests w JOIN accounts a ON a.id = w.account_id WHERE w.account_id IN ($in) ORDER BY w.id DESC LIMIT 50", $ids),
        ]);
    }

    public function requestWithdrawal(): void
    {
        $acc = $this->ownAccount((int) input('account_id'));
        $amount = Money::parse(input('amount'));
        if ($amount === null || $amount <= 0) {
            remember_input();
            flash('error', 'Enter a valid amount.');
            redirect('/withdrawals');
        }
        $method = input('method');
        if (!in_array($method, ['Bank transfer', 'Cash pickup', 'Cheque'], true)) {
            $method = 'Bank transfer';
        }
        $this->attempt(fn () => WithdrawalService::request((int) $acc['id'], $amount, $method, input('details'), (int) Auth::id()), '/withdrawals');
        clear_old();
        flash('success', 'Your withdrawal request has been submitted and the amount is on hold pending review.');
        redirect('/withdrawals');
    }

    public function cancelWithdrawal(string $id): void
    {
        $req = Db::one('SELECT * FROM withdrawal_requests WHERE id = ?', [(int) $id]);
        if (!$req) {
            $this->notFound();
        }
        $this->ownAccount((int) $req['account_id']);
        $this->attempt(fn () => WithdrawalService::cancel((int) $id, (int) Auth::id()), '/withdrawals');
        flash('success', 'Withdrawal request cancelled and funds released.');
        redirect('/withdrawals');
    }

    public function addFundsForm(): void
    {
        $ids = $this->accountIds();
        $in = implode(',', array_fill(0, count($ids), '?'));
        $this->view('customer/add_funds', [
            'title' => 'Add funds', 'accounts' => $this->myAccounts(),
            'enabled' => setting('customer_add_funds_requests') === '1',
            'requests' => Db::all("SELECT d.*, a.account_number FROM deposit_requests d JOIN accounts a ON a.id = d.account_id WHERE d.account_id IN ($in) AND d.kind = 'deposit' ORDER BY d.id DESC LIMIT 20", $ids),
        ]);
    }

    public function addFunds(): void
    {
        if (setting('customer_add_funds_requests') !== '1') {
            $this->forbidden();
        }
        $acc = $this->ownAccount((int) input('account_id'));
        $amount = Money::parse(input('amount'));
        if ($amount === null || $amount <= 0) {
            remember_input();
            flash('error', 'Enter a valid amount.');
            redirect('/add-funds');
        }
        $note = mb_substr(input('note'), 0, 200);
        $this->attempt(fn () => FundingService::request((int) $acc['id'], $amount, 'deposit',
            'Customer add-funds request' . ($note ? ': ' . $note : ''), null, (int) Auth::id()), '/add-funds');
        clear_old();
        flash('success', 'Your request has been submitted. Funds will appear once the bank confirms receipt.');
        redirect('/add-funds');
    }

    public function notifications(): void
    {
        $items = Db::all('SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 100', [Auth::id()]);
        Db::query('UPDATE notifications SET read_at = UTC_TIMESTAMP() WHERE user_id = ? AND read_at IS NULL', [Auth::id()]);
        $this->view('customer/notifications', ['title' => 'Notifications', 'items' => $items]);
    }

    public function profile(): void
    {
        $user = Auth::user();
        $this->view('customer/profile', [
            'title' => 'Profile & security',
            'user' => $user,
            'customer' => Auth::isCustomer() ? Db::one('SELECT * FROM customers WHERE user_id = ?', [$user['id']]) : null,
            'sessions' => Db::all('SELECT * FROM user_sessions WHERE user_id = ? AND revoked_at IS NULL ORDER BY last_seen_at DESC', [$user['id']]),
            'currentSession' => $_SESSION['session_row_id'] ?? null,
        ]);
    }

    public function changePassword(): void
    {
        $user = Auth::user();
        $new = (string) ($_POST['password'] ?? '');
        if (!password_verify((string) ($_POST['current_password'] ?? ''), $user['password_hash'])) {
            flash('error', 'Your current password is incorrect.');
            redirect('/profile');
        }
        if ($err = self::validatePassword($new)) {
            flash('error', $err);
            redirect('/profile');
        }
        if ($new !== ($_POST['password_confirmation'] ?? '')) {
            flash('error', 'New passwords do not match.');
            redirect('/profile');
        }
        Db::update('users', ['password_hash' => password_hash($new, PASSWORD_DEFAULT), 'password_changed_at' => now()], 'id = ?', [$user['id']]);
        $revoked = Auth::revokeOtherSessions((int) $user['id']);
        AuditService::log('security.password_changed', 'user', $user['id'], null, ['other_sessions_revoked' => $revoked]);
        \App\Services\NotificationService::event((int) $user['id'], 'password_changed');
        flash('success', 'Password updated. Other devices have been signed out.');
        redirect('/profile');
    }

    public function sendVerification(): void
    {
        $user = Auth::user();
        if (!$user['email_verified_at']) {
            AuthController::sendVerification((int) $user['id']);
            flash('success', 'A verification link has been sent to ' . $user['email'] . '.');
        }
        redirect('/profile');
    }

    public function revokeSessions(): void
    {
        $n = Auth::revokeOtherSessions((int) Auth::id());
        AuditService::log('security.sessions_revoked', 'user', Auth::id(), null, ['count' => $n]);
        flash('success', "Signed out of $n other session(s).");
        redirect('/profile');
    }
}
