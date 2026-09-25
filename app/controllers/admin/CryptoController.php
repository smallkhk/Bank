<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Db;
use App\Services\AuditService;
use App\Services\CryptoService;
use App\Services\Money;
use App\Services\StaffScope;
use App\Core\Auth;

final class CryptoController extends Controller
{
    public function index(): void
    {
        $assets = Db::all('SELECT a.*, COALESCE(SUM(h.quantity), 0) AS held, COUNT(DISTINCT CASE WHEN h.quantity > 0 THEN h.customer_id END) AS holders
                             FROM crypto_assets a LEFT JOIN crypto_holdings h ON h.asset_id = a.id GROUP BY a.id ORDER BY a.sort_order, a.symbol');
        foreach ($assets as &$a) {
            $a['exposure'] = CryptoService::valueOf((int) $a['held'], $a);
        }
        unset($a);
        $edit = input('edit') !== '' ? Db::one('SELECT * FROM crypto_assets WHERE id = ?', [(int) input('edit')]) : null;
        $this->view('admin/crypto', [
            'title' => 'Crypto (simulated)', 'assets' => $assets, 'edit' => $edit,
            'desk' => (int) Db::value("SELECT balance FROM accounts WHERE system_code = 'SYS-CRYPTO'"),
            'volume' => Db::one("SELECT COUNT(*) AS n, COALESCE(SUM(gross),0) AS gross, COALESCE(SUM(fee),0) AS fees FROM crypto_transactions WHERE created_at > UTC_TIMESTAMP() - INTERVAL 30 DAY"),
            'breaks' => CryptoService::reconciliationBreaks(),
        ]);
    }

    public function trades(): void
    {
        [$scope, $params] = StaffScope::customerFilter('t.customer_id');
        $where = $scope;
        if (($s = strtoupper(input('symbol'))) !== '') { $where .= ' AND a.symbol = ?'; $params[] = $s; }
        if (in_array(input('side'), ['buy', 'sell'], true)) { $where .= ' AND t.side = ?'; $params[] = input('side'); }
        if (($q = input('q')) !== '') { $where .= ' AND (u.full_name LIKE ? OR t.reference LIKE ?)'; array_push($params, "%$q%", "%$q%"); }
        $from = 'FROM crypto_transactions t JOIN crypto_assets a ON a.id = t.asset_id JOIN customers c ON c.id = t.customer_id JOIN users u ON u.id = c.user_id';
        $total = (int) Db::value("SELECT COUNT(*) $from WHERE $where", $params);
        $p = paginate($total, 50);
        $this->view('admin/crypto_trades', [
            'title' => 'Crypto trades', 'pager' => $p,
            'rows' => Db::all("SELECT t.*, a.symbol, a.decimals, u.full_name AS customer_name $from WHERE $where ORDER BY t.id DESC LIMIT {$p['limit']} OFFSET {$p['offset']}", $params),
        ]);
    }

    public function save(): void
    {
        $id = (int) input('id');
        $existing = $id ? Db::one('SELECT * FROM crypto_assets WHERE id = ?', [$id]) : null;
        $back = '/admin/crypto' . ($id ? '?edit=' . $id : '');
        $symbol = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', input('symbol')));
        $data = [
            'name' => mb_substr(input('name'), 0, 80),
            'trade_fee_bps' => max(0, min(1000, (int) input('trade_fee_bps'))),
            'volatility_bps' => max(0, min(2000, (int) input('volatility_bps'))),
            'sort_order' => (int) input('sort_order'),
            'status' => in_array(input('status'), ['active', 'halted', 'inactive'], true) ? input('status') : 'active',
            'min_trade' => Money::parse(input('min_trade') ?: '0') ?? 0,
        ];
        if ($data['name'] === '' || $data['min_trade'] < 1) {
            flash('error', 'Name and a minimum trade of at least 0.01 are required.');
            redirect($back);
        }
        if ($existing) {
            Db::update('crypto_assets', $data, 'id = ?', [$id]);
            AuditService::log('crypto.asset_updated', 'crypto_asset', $existing['symbol'], array_intersect_key($existing, $data), $data);
            flash('success', $existing['symbol'] . ' updated.');
            redirect('/admin/crypto');
        }
        // Decimals are fixed at creation: changing them would reinterpret every stored quantity.
        $decimals = max(0, min(8, (int) input('decimals')));
        $price = Money::parse(input('price'));
        if (!preg_match('/^[A-Z0-9]{2,12}$/', $symbol) || !$price || Db::value('SELECT 1 FROM crypto_assets WHERE symbol = ?', [$symbol])) {
            flash('error', 'Enter a unique symbol (2–12 letters/digits) and a price above zero.');
            redirect($back);
        }
        $newId = Db::insert('crypto_assets', $data + ['symbol' => $symbol, 'decimals' => $decimals, 'price' => $price, 'previous_price' => $price]);
        Db::insert('crypto_price_history', ['asset_id' => $newId, 'price' => $price, 'source' => 'initial', 'set_by' => Auth::id()]);
        AuditService::log('crypto.asset_created', 'crypto_asset', $symbol, null, $data + ['decimals' => $decimals, 'price' => $price]);
        flash('success', "$symbol created.");
        redirect('/admin/crypto');
    }

    public function price(string $id): void
    {
        $asset = Db::one('SELECT * FROM crypto_assets WHERE id = ?', [(int) $id]);
        if (!$asset) {
            $this->notFound();
        }
        $price = Money::parse(input('price'));
        if (!$price) {
            flash('error', 'Enter a valid price.');
            redirect('/admin/crypto');
        }
        $this->attempt(fn () => CryptoService::setPrice($asset, $price, 'manual', (int) Auth::id()), '/admin/crypto');
        flash('success', $asset['symbol'] . ' price set to ' . money($price) . '.');
        redirect('/admin/crypto');
    }

    public function simulate(): void
    {
        $n = CryptoService::simulatePrices();
        flash('success', "Simulated a price move for $n asset(s).");
        redirect('/admin/crypto');
    }
}
