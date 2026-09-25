<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Db;
use App\Services\AuditService;
use App\Services\Money;

/** Limits and monthly fees per account type (between global defaults and per-account overrides). */
final class AccountTypeController extends Controller
{
    private const MONEY = ['daily_transfer_limit', 'daily_withdrawal_limit', 'monthly_limit', 'monthly_fee'];

    public function index(): void
    {
        $this->view('admin/account_types', [
            'title' => 'Account types',
            'types' => Db::all('SELECT t.*, (SELECT COUNT(*) FROM accounts a WHERE a.account_type_id = t.id) AS accounts FROM account_types t ORDER BY t.id'),
        ]);
    }

    public function update(string $id): void
    {
        $type = Db::one('SELECT * FROM account_types WHERE id = ?', [(int) $id]);
        if (!$type) {
            $this->notFound();
        }
        $data = ['name' => mb_substr(input('name') ?: $type['name'], 0, 80), 'is_active' => input('is_active') === '1' ? 1 : 0];
        foreach (self::MONEY as $k) {
            $v = input($k);
            if ($v === '') {
                $data[$k] = $k === 'monthly_fee' ? 0 : null;
                continue;
            }
            $m = Money::parse($v);
            if ($m === null) {
                flash('error', 'Invalid amount.');
                redirect('/admin/account-types');
            }
            $data[$k] = $m;
        }
        Db::update('account_types', $data, 'id = ?', [$type['id']]);
        AuditService::log('account_type.updated', 'account_type', $type['id'], array_intersect_key($type, $data), $data);
        flash('success', $data['name'] . ' updated.');
        redirect('/admin/account-types');
    }
}
