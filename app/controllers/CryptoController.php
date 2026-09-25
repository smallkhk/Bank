<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Services\AuditService;
use App\Services\CryptoService;
use App\Services\Money;

final class CryptoController extends Controller
{
    private function cid(): int
    {
        if (!CryptoService::enabled()) {
            flash('error', 'Crypto is not available.');
            redirect('/dashboard');
        }
        return Auth::customerId() ?? $this->forbidden();
    }

    private function acknowledged(int $cid): bool
    {
        return (bool) Db::value('SELECT crypto_risk_ack_at FROM customers WHERE id = ?', [$cid]);
    }

    private function asset(string $symbol): array
    {
        $a = Db::one("SELECT * FROM crypto_assets WHERE symbol = ? AND status <> 'inactive'", [strtoupper($symbol)]);
        return $a ?: $this->notFound();
    }

    private function accounts(int $cid): array
    {
        return Db::all("SELECT a.*, t.name AS type_name FROM accounts a JOIN account_types t ON t.id = a.account_type_id
                         WHERE a.customer_id = ? AND a.status = 'active' AND a.credit_limit = 0 AND a.currency = ? ORDER BY a.id",
            [$cid, setting('currency', 'USD')]);
    }

    public function index(): void
    {
        $cid = $this->cid();
        $this->view('customer/crypto', [
            'title' => 'Crypto (simulated)',
            'acknowledged' => $this->acknowledged($cid),
            'assets' => Db::all("SELECT * FROM crypto_assets WHERE status <> 'inactive' ORDER BY sort_order, symbol"),
            'portfolio' => CryptoService::portfolio($cid),
            'recent' => Db::all('SELECT t.*, a.symbol, a.decimals FROM crypto_transactions t JOIN crypto_assets a ON a.id = t.asset_id
                                  WHERE t.customer_id = ? ORDER BY t.id DESC LIMIT 10', [$cid]),
        ]);
    }

    public function acknowledge(): void
    {
        $cid = $this->cid();
        if (input('accept') !== '1') {
            flash('error', 'Please confirm that you have read and understood the notice.');
            redirect('/crypto');
        }
        Db::update('customers', ['crypto_risk_ack_at' => now()], 'id = ?', [$cid]);
        AuditService::log('crypto.risk_acknowledged', 'customer', $cid);
        redirect('/crypto');
    }

    public function show(string $symbol): void
    {
        $cid = $this->cid();
        $asset = $this->asset($symbol);
        $holding = Db::one('SELECT * FROM crypto_holdings WHERE customer_id = ? AND asset_id = ?', [$cid, $asset['id']]);
        $this->view('customer/crypto_asset', [
            'title' => $asset['name'] . ' (' . $asset['symbol'] . ')',
            'asset' => $asset, 'holding' => $holding, 'acknowledged' => $this->acknowledged($cid),
            'accounts' => $this->accounts($cid), 'history' => CryptoService::history((int) $asset['id'], 90),
            'trades' => Db::all('SELECT * FROM crypto_transactions WHERE customer_id = ? AND asset_id = ? ORDER BY id DESC LIMIT 25', [$cid, $asset['id']]),
        ]);
    }

    /** Price a trade for the review step (JSON). */
    public function quote(string $symbol): void
    {
        $this->cid();
        $asset = $this->asset($symbol);
        [$side, $amount] = $this->parseOrder($asset);
        if ($amount === null) {
            json_response(['error' => 'Enter a valid ' . ($side === 'buy' ? 'amount' : 'quantity') . '.'], 422);
        }
        try {
            $q = CryptoService::quote($asset, $side, $amount);
        } catch (\App\Services\BankingException $e) {
            json_response(['error' => $e->getMessage()], 422);
        }
        json_response([
            'side' => $side, 'price' => $q['price'], 'price_fmt' => money($q['price']),
            'quantity' => $q['quantity'], 'quantity_fmt' => CryptoService::formatQuantity($q['quantity'], (int) $asset['decimals']) . ' ' . $asset['symbol'],
            'gross_fmt' => money($q['gross']), 'fee_fmt' => money($q['fee']), 'net_fmt' => money($q['net']),
            'below_min' => $q['gross'] < (int) $asset['min_trade'] || $q['quantity'] <= 0,
        ]);
    }

    public function trade(string $symbol): void
    {
        $cid = $this->cid();
        $asset = $this->asset($symbol);
        $back = '/crypto/' . $asset['symbol'];
        if (!$this->acknowledged($cid)) {
            redirect('/crypto');
        }
        [$side, $amount] = $this->parseOrder($asset);
        if ($amount === null) {
            flash('error', $side === 'buy' ? 'Enter a valid amount to spend.' : 'Enter a valid quantity (max ' . $asset['decimals'] . ' decimal places).');
            redirect($back);
        }
        $expected = input('expected_price') !== '' ? (int) input('expected_price') : null;
        $r = $this->attempt(fn () => CryptoService::trade($cid, (int) $asset['id'], (int) input('account_id'), $side, $amount, $expected), $back);
        flash('success', sprintf('%s %s %s at %s. %s %s.',
            $side === 'buy' ? 'Bought' : 'Sold', CryptoService::formatQuantity($r['quantity'], $r['decimals']), $r['symbol'], money($r['price']),
            $side === 'buy' ? 'Total paid' : 'Credited', money($r['net'])));
        redirect($back);
    }

    /** @return array{0:string, 1:?int} side and amount (money for buys, units for sells) */
    private function parseOrder(array $asset): array
    {
        $side = input('side') === 'sell' ? 'sell' : 'buy';
        if ($side === 'buy') {
            return [$side, Money::parse(input('amount'))];
        }
        if (input('sell_all') === '1') {
            $held = (int) Db::value('SELECT quantity FROM crypto_holdings WHERE customer_id = ? AND asset_id = ?', [Auth::customerId(), $asset['id']]);
            return [$side, $held > 0 ? $held : null];
        }
        return [$side, CryptoService::parseQuantity(input('quantity'), (int) $asset['decimals'])];
    }
}
