<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Db;
use App\Services\AccountService;
use App\Services\LedgerService;
use App\Services\Money;
use App\Services\StaffScope;

final class AccountController extends Controller
{
    public static function loadScoped(int $id): array
    {
        $acc = AccountService::find($id);
        if (!$acc) {
            http_response_code(404);
            \App\Core\View::render('errors/404', ['title' => 'Not found'], 'app');
            exit;
        }
        if (!StaffScope::canAccessAccount($acc)) {
            \App\Core\Middleware::forbidden();
        }
        return $acc;
    }

    public function index(): void
    {
        [$scope, $params] = StaffScope::customerFilter('a.customer_id');
        $where = "a.is_system = 0 AND $scope";
        if (($q = input('q')) !== '') {
            $where .= ' AND (a.account_number LIKE ? OR u.full_name LIKE ?)';
            array_push($params, "%$q%", "%$q%");
        }
        if (in_array($s = input('status'), AccountService::STATUSES, true)) {
            $where .= ' AND a.status = ?';
            $params[] = $s;
        }
        $from = 'FROM accounts a LEFT JOIN customers c ON c.id = a.customer_id LEFT JOIN users u ON u.id = c.user_id LEFT JOIN account_types t ON t.id = a.account_type_id';
        $total = (int) Db::value("SELECT COUNT(*) $from WHERE $where", $params);
        $p = paginate($total);
        $rows = Db::all("SELECT a.*, u.full_name AS customer_name, t.name AS type_name $from WHERE $where ORDER BY a.id DESC LIMIT {$p['limit']} OFFSET {$p['offset']}", $params);
        $system = StaffScope::seesAll() ? Db::all('SELECT * FROM accounts WHERE is_system = 1 ORDER BY id') : [];
        $this->view('admin/accounts', ['title' => 'Accounts', 'rows' => $rows, 'pager' => $p, 'system' => $system]);
    }

    public function show(string $id): void
    {
        $acc = self::loadScoped((int) $id);
        $total = (int) Db::value('SELECT COUNT(*) FROM ledger_entries WHERE account_id = ?', [$acc['id']]);
        $p = paginate($total, 30);
        $this->view('admin/account', [
            'title'        => 'Account ' . $acc['account_number'],
            'a'            => $acc,
            'limits'       => AccountService::limits($acc),
            'restrictions' => AccountService::activeRestrictions((int) $acc['id']),
            'ledgerBalance'=> LedgerService::ledgerBalance((int) $acc['id']),
            'entries'      => Db::all(
                "SELECT l.*, t.reference, t.type, t.description AS tx_description, t.id AS tx_id, u.full_name AS initiated_name
                   FROM ledger_entries l JOIN transactions t ON t.id = l.transaction_id LEFT JOIN users u ON u.id = t.initiated_by
                  WHERE l.account_id = ? ORDER BY l.id DESC LIMIT {$p['limit']} OFFSET {$p['offset']}", [$acc['id']]),
            'pager'        => $p,
            'withdrawals'  => Db::all("SELECT * FROM withdrawal_requests WHERE account_id = ? AND status = 'pending'", [$acc['id']]),
            'audit'        => Db::all("SELECT a.*, u.full_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id WHERE a.target_type = 'account' AND a.target_id = ? ORDER BY a.id DESC LIMIT 15", [(string) $acc['id']]),
        ]);
    }

    public function statement(string $id): void
    {
        $acc = self::loadScoped((int) $id);
        if ($acc['is_system']) {
            $this->notFound();
        }
        \App\Controllers\CustomerController::sendStatement($acc, input('from'), input('to'));
    }

    public function updateStatus(string $id): void
    {
        $acc = self::loadScoped((int) $id);
        $back = '/admin/accounts/' . $acc['id'];
        $reason = $this->requireReason($back);
        $status = input('status');
        if (in_array($status, ['frozen', 'restricted'], true) && !can('accounts.freeze')) {
            $this->forbidden();
        }
        $this->attempt(fn () => AccountService::changeStatus((int) $acc['id'], $status, $reason), $back);
        flash('success', 'Account status changed to ' . $status . '.');
        redirect($back);
    }

    public function addRestriction(string $id): void
    {
        $acc = self::loadScoped((int) $id);
        $back = '/admin/accounts/' . $acc['id'];
        $reason = $this->requireReason($back);
        $expires = input('expires_at');
        $expiresAt = null;
        if ($expires !== '') {
            $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $expires);
            if (!$dt || $dt <= new \DateTimeImmutable('today')) {
                flash('error', 'Expiry date must be in the future.');
                redirect($back);
            }
            $expiresAt = $dt->format('Y-m-d 23:59:59');
        }
        $this->attempt(fn () => AccountService::addRestriction((int) $acc['id'], input('restriction'), $reason, $expiresAt), $back);
        flash('success', 'Restriction added.');
        redirect($back);
    }

    public function liftRestriction(string $id): void
    {
        $r = Db::one('SELECT * FROM account_restrictions WHERE id = ?', [(int) $id]);
        if (!$r) {
            $this->notFound();
        }
        $acc = self::loadScoped((int) $r['account_id']);
        $back = '/admin/accounts/' . $acc['id'];
        $reason = $this->requireReason($back);
        $this->attempt(fn () => AccountService::liftRestriction((int) $id, $reason), $back);
        flash('success', 'Restriction lifted.');
        redirect($back);
    }

    public function updateLimits(string $id): void
    {
        $acc = self::loadScoped((int) $id);
        $back = '/admin/accounts/' . $acc['id'];
        $reason = $this->requireReason($back);
        $parse = function (string $field) use ($back): ?int {
            $v = input($field);
            if ($v === '') {
                return null; // inherit from account type / global default
            }
            $m = Money::parse($v);
            if ($m === null) {
                flash('error', 'Invalid limit amount.');
                redirect($back);
            }
            return $m;
        };
        AccountService::setLimits((int) $acc['id'], $parse('daily_transfer_limit'), $parse('daily_withdrawal_limit'), $parse('monthly_limit'), $reason);
        flash('success', 'Limits updated.');
        redirect($back);
    }
}
