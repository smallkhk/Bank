<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Db;
use App\Services\AuditService;
use App\Services\Money;

final class CardProductController extends Controller
{
    private const MONEY = ['daily_limit', 'monthly_limit', 'atm_daily_limit', 'issuance_fee', 'replacement_fee', 'credit_limit', 'min_payment_floor', 'late_fee'];
    private const INTS = ['international_fee_bps' => [0, 1000], 'expiry_months' => [6, 120], 'interest_apr_bps' => [0, 6000],
                          'min_payment_bps' => [0, 10000], 'statement_day' => [1, 28], 'grace_days' => [1, 60]];
    private const BOOLS = ['online_enabled', 'atm_enabled', 'international_enabled', 'customer_requestable'];

    public function index(): void
    {
        $edit = input('edit') !== '' ? Db::one('SELECT * FROM card_products WHERE id = ?', [(int) input('edit')]) : null;
        $this->view('admin/card_products', [
            'title' => 'Card products', 'edit' => $edit,
            'products' => Db::all("SELECT p.*, (SELECT COUNT(*) FROM cards c WHERE c.product_id = p.id AND c.status IN ('active','frozen')) AS active_cards FROM card_products p ORDER BY p.status, p.card_type, p.name"),
        ]);
    }

    public function save(): void
    {
        $id = (int) input('id');
        $existing = $id ? Db::one('SELECT * FROM card_products WHERE id = ?', [$id]) : null;
        $back = '/admin/card-products' . ($id ? '?edit=' . $id : '');
        $data = [
            'name' => mb_substr(input('name'), 0, 100),
            'bin_prefix' => substr(preg_replace('/\D/', '', input('bin_prefix')), 0, 8),
            'status' => input('status') === 'inactive' ? 'inactive' : 'active',
        ];
        if (!$existing) {
            // Type and form cannot change after cards exist.
            $data['card_type'] = in_array(input('card_type'), ['debit', 'credit', 'prepaid'], true) ? input('card_type') : 'debit';
            $data['form_factor'] = input('form_factor') === 'physical' ? 'physical' : 'virtual';
        }
        if ($data['name'] === '' || strlen($data['bin_prefix']) < 1) {
            flash('error', 'Name and a numeric card prefix are required.');
            redirect($back);
        }
        foreach (self::MONEY as $k) {
            $m = Money::parse(input($k) === '' ? '0' : input($k));
            if ($m === null) {
                flash('error', "Invalid amount for $k.");
                redirect($back);
            }
            $data[$k] = $m;
        }
        foreach (self::INTS as $k => [$min, $max]) {
            $data[$k] = max($min, min($max, (int) input($k)));
        }
        foreach (self::BOOLS as $k) {
            $data[$k] = input($k) === '1' ? 1 : 0;
        }
        $type = $existing['card_type'] ?? $data['card_type'];
        if ($type === 'credit' && $data['credit_limit'] <= 0) {
            flash('error', 'Credit products need a credit limit.');
            redirect($back);
        }
        if ($existing) {
            Db::update('card_products', $data, 'id = ?', [$id]);
            AuditService::log('card_product.updated', 'card_product', $id, array_intersect_key($existing, $data), $data);
        } else {
            $id = Db::insert('card_products', $data);
            AuditService::log('card_product.created', 'card_product', $id, null, $data);
        }
        flash('success', 'Card product saved. Changes to limits apply to existing cards immediately; credit limits apply to newly issued cards.');
        redirect('/admin/card-products');
    }
}
