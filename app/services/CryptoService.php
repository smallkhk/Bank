<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Db;

/**
 * Simulated crypto trading. The bank's SYS-CRYPTO account is the counterparty to every trade,
 * so each buy or sell posts balanced entries to the money ledger. Customer positions are kept in
 * crypto_transactions (source of truth), with crypto_holdings as a cache.
 *
 * Quantities are integers in 10^-decimals units (e.g. satoshis for 8 decimals) and money is integer
 * minor units. All arithmetic is exact integer maths, so bcmath and gmp are not required.
 */
final class CryptoService
{
    public static function enabled(): bool
    {
        return setting('crypto_enabled') === '1';
    }

    public static function scale(array $asset): int
    {
        return 10 ** (int) $asset['decimals'];
    }

    /** floor(a * b / c) (or ceil) for non-negative integers without 64-bit overflow. */
    public static function mulDiv(int $a, int $b, int $c, bool $roundUp = false): int
    {
        if ($a < 0 || $b < 0 || $c <= 0) {
            throw new \InvalidArgumentException('mulDiv expects non-negative operands');
        }
        if ($a === 0 || $b === 0) {
            return 0;
        }
        if ($a <= intdiv(PHP_INT_MAX, $b)) {
            $p = $a * $b;
            $q = intdiv($p, $c);
            return $roundUp && $p % $c !== 0 ? $q + 1 : $q;
        }
        // a*b would overflow: split a = q*c + r, so a*b/c = q*b + r*b/c.
        [$q, $r] = [intdiv($a, $c), $a % $c];
        if ($r !== 0 && $r > intdiv(PHP_INT_MAX, $b)) {
            throw new BankingException('This amount is too large to process.');
        }
        $hi = $q * $b;
        $lo = $r * $b;
        $res = $hi + intdiv($lo, $c);
        return $roundUp && $lo % $c !== 0 ? $res + 1 : $res;
    }

    /** Parse a user-entered quantity like "0.015" into integer units. Null if invalid. */
    public static function parseQuantity(string $input, int $decimals): ?int
    {
        $s = str_replace([',', ' '], '', trim($input));
        if (!preg_match('/^\d{1,12}(\.\d+)?$/', $s)) {
            return null;
        }
        [$whole, $frac] = array_pad(explode('.', $s), 2, '');
        if (strlen($frac) > $decimals) {
            return null;
        }
        return (int) $whole * 10 ** $decimals + (int) str_pad($frac, $decimals, '0');
    }

    public static function formatQuantity(int $units, int $decimals): string
    {
        if ($decimals === 0) {
            return number_format($units);
        }
        $scale = 10 ** $decimals;
        $frac = rtrim(str_pad((string) ($units % $scale), $decimals, '0', STR_PAD_LEFT), '0');
        return number_format(intdiv($units, $scale)) . ($frac !== '' ? '.' . $frac : '');
    }

    public static function valueOf(int $quantity, array $asset): int
    {
        return self::mulDiv($quantity, (int) $asset['price'], self::scale($asset));
    }

    public static function changeBps(array $asset): int
    {
        $prev = (int) $asset['previous_price'];
        return $prev > 0 ? intdiv(((int) $asset['price'] - $prev) * 10000, $prev) : 0;
    }

    // ── Quotes & trades ──────────────────────────────────────────────

    /**
     * Price a trade without executing it.
     * buy: $amount = money to spend (before fee). sell: $amount = quantity in units.
     * @return array{quantity:int, gross:int, fee:int, net:int, price:int}
     */
    public static function quote(array $asset, string $side, int $amount): array
    {
        $price = (int) $asset['price'];
        $scale = self::scale($asset);
        $bps = (int) $asset['trade_fee_bps'];
        if ($side === 'buy') {
            $qty = self::mulDiv($amount, $scale, $price);                  // units affordable with $amount
            $gross = self::mulDiv($qty, $price, $scale, true);             // never charge more than entered
            $fee = self::mulDiv($gross, $bps, 10000, true);
            return ['quantity' => $qty, 'gross' => $gross, 'fee' => $fee, 'net' => $gross + $fee, 'price' => $price];
        }
        $gross = self::mulDiv($amount, $price, $scale);                    // round proceeds down
        $fee = self::mulDiv($gross, $bps, 10000, true);
        return ['quantity' => $amount, 'gross' => $gross, 'fee' => $fee, 'net' => $gross - $fee, 'price' => $price];
    }

    /**
     * Execute a buy or sell at the current price. $expectedPrice guards against the price moving
     * between the customer's review and confirmation.
     */
    public static function trade(int $customerId, int $assetId, int $accountId, string $side, int $amount, ?int $expectedPrice): array
    {
        if (!self::enabled()) {
            throw new BankingException('Crypto trading is not available.');
        }
        if (!in_array($side, ['buy', 'sell'], true) || $amount <= 0) {
            throw new BankingException('Invalid trade.');
        }
        return Db::transaction(function () use ($customerId, $assetId, $accountId, $side, $amount, $expectedPrice) {
            $asset = Db::one('SELECT * FROM crypto_assets WHERE id = ? LOCK IN SHARE MODE', [$assetId]);
            if (!$asset || $asset['status'] !== 'active') {
                throw new BankingException('Trading in this asset is currently unavailable.');
            }
            if ($expectedPrice !== null && $expectedPrice !== (int) $asset['price']) {
                throw new BankingException('The price changed to ' . money((int) $asset['price']) . ' before your order was placed. Please review and try again.');
            }
            $acc = AccountService::lockAccounts([$accountId])[$accountId];
            if ((int) $acc['customer_id'] !== $customerId || $acc['currency'] !== setting('currency', 'USD')) {
                throw new BankingException('Choose one of your accounts.');
            }
            $holding = Db::one('SELECT * FROM crypto_holdings WHERE customer_id = ? AND asset_id = ? FOR UPDATE', [$customerId, $assetId]);
            if (!$holding) {
                Db::insert('crypto_holdings', ['customer_id' => $customerId, 'asset_id' => $assetId]);
                $holding = Db::one('SELECT * FROM crypto_holdings WHERE customer_id = ? AND asset_id = ? FOR UPDATE', [$customerId, $assetId]);
            }

            if ($side === 'buy') {
                AccountService::assertCanDebit($acc, 'transfers');
                if ($amount > (int) setting('crypto_max_trade')) {
                    throw new BankingException('The maximum per trade is ' . money((int) setting('crypto_max_trade')) . '.');
                }
            } else {
                AccountService::assertCanCredit($acc);
                if ($amount > (int) $holding['quantity']) {
                    throw new BankingException('You only hold ' . self::formatQuantity((int) $holding['quantity'], (int) $asset['decimals']) . ' ' . $asset['symbol'] . '.');
                }
            }
            $q = self::quote($asset, $side, $amount);
            if ($q['quantity'] <= 0 || $q['gross'] < (int) $asset['min_trade']) {
                throw new BankingException('The minimum trade is ' . money((int) $asset['min_trade']) . '.');
            }
            if ($side === 'sell' && $q['gross'] > (int) setting('crypto_max_trade')) {
                throw new BankingException('The maximum per trade is ' . money((int) setting('crypto_max_trade')) . '. Sell a smaller quantity.');
            }
            if ($side === 'buy' && AccountService::available($acc) < $q['net']) {
                throw new BankingException('Insufficient available balance (' . money($q['net']) . ' including fee).');
            }

            $desk = LedgerService::systemAccountId(LedgerService::SYS_CRYPTO);
            $fees = LedgerService::systemAccountId(LedgerService::SYS_FEES);
            $label = ($side === 'buy' ? 'Buy ' : 'Sell ') . self::formatQuantity($q['quantity'], (int) $asset['decimals']) . ' ' . $asset['symbol'] . ' (simulated)';
            $txId = LedgerService::createTransaction('crypto', $q['gross'], $acc['currency'], [
                ($side === 'buy' ? 'from_account_id' : 'to_account_id') => $acc['id'],
                'description' => $label, 'initiated_by' => Auth::id(), 'fee_amount' => $q['fee'],
            ]);
            $legs = $side === 'buy'
                ? [['account_id' => (int) $acc['id'], 'entry' => 'debit', 'amount' => $q['net']],
                   ['account_id' => $desk, 'entry' => 'credit', 'amount' => $q['gross']]]
                : [['account_id' => $desk, 'entry' => 'debit', 'amount' => $q['gross']],
                   ['account_id' => (int) $acc['id'], 'entry' => 'credit', 'amount' => $q['net']]];
            if ($q['fee'] > 0) {
                $legs[] = ['account_id' => $fees, 'entry' => 'credit', 'amount' => $q['fee']];
            }
            LedgerService::postEntries($txId, $legs);

            // Update the position (average-cost method).
            $realized = null;
            if ($side === 'buy') {
                Db::query('UPDATE crypto_holdings SET quantity = quantity + ?, cost_basis = cost_basis + ? WHERE id = ?', [$q['quantity'], $q['net'], $holding['id']]);
            } else {
                $held = (int) $holding['quantity'];
                $costOut = $q['quantity'] === $held ? (int) $holding['cost_basis'] : self::mulDiv((int) $holding['cost_basis'], $q['quantity'], $held);
                $realized = $q['net'] - $costOut;
                Db::query('UPDATE crypto_holdings SET quantity = quantity - ?, cost_basis = cost_basis - ?, realized_pnl = realized_pnl + ? WHERE id = ?',
                    [$q['quantity'], $costOut, $realized, $holding['id']]);
            }
            $ref = LedgerService::reference('CX');
            $id = Db::insert('crypto_transactions', [
                'reference' => $ref, 'customer_id' => $customerId, 'asset_id' => $assetId, 'account_id' => $acc['id'], 'side' => $side,
                'quantity' => $q['quantity'], 'price' => $q['price'], 'gross' => $q['gross'], 'fee' => $q['fee'], 'net' => $q['net'],
                'realized_pnl' => $realized, 'transaction_id' => $txId, 'created_by' => Auth::id(),
            ]);
            AuditService::log('crypto.' . $side, 'crypto_transaction', $id, null, [
                'asset' => $asset['symbol'], 'quantity' => self::formatQuantity($q['quantity'], (int) $asset['decimals']),
                'price' => $q['price'], 'net' => $q['net'],
            ]);
            return $q + ['id' => $id, 'reference' => $ref, 'symbol' => $asset['symbol'], 'decimals' => (int) $asset['decimals']];
        });
    }

    // ── Portfolio ────────────────────────────────────────────────────

    public static function portfolio(int $customerId): array
    {
        $rows = Db::all(
            'SELECT h.*, a.symbol, a.name, a.decimals, a.price, a.previous_price, a.status
               FROM crypto_holdings h JOIN crypto_assets a ON a.id = h.asset_id
              WHERE h.customer_id = ? AND (h.quantity > 0 OR h.realized_pnl <> 0) ORDER BY a.sort_order, a.symbol',
            [$customerId]
        );
        $totals = ['value' => 0, 'cost' => 0, 'unrealized' => 0, 'realized' => 0];
        foreach ($rows as &$r) {
            $r['value'] = self::valueOf((int) $r['quantity'], $r);
            $r['unrealized'] = $r['value'] - (int) $r['cost_basis'];
            $r['avg_cost'] = (int) $r['quantity'] > 0 ? self::mulDiv((int) $r['cost_basis'], self::scale($r), (int) $r['quantity']) : 0;
            $r['pnl_bps'] = (int) $r['cost_basis'] > 0 ? intdiv($r['unrealized'] * 10000, (int) $r['cost_basis']) : 0;
            $totals['value'] += $r['value'];
            $totals['cost'] += (int) $r['cost_basis'];
            $totals['unrealized'] += $r['unrealized'];
            $totals['realized'] += (int) $r['realized_pnl'];
        }
        unset($r);
        return ['rows' => $rows, 'totals' => $totals];
    }

    /** Holdings whose cached quantity disagrees with the trade history. Should always be empty. */
    public static function reconciliationBreaks(): array
    {
        return Db::all(
            "SELECT h.customer_id, a.symbol, h.quantity,
                    COALESCE((SELECT SUM(CASE side WHEN 'buy' THEN quantity ELSE -quantity END) FROM crypto_transactions t
                               WHERE t.customer_id = h.customer_id AND t.asset_id = h.asset_id), 0) AS traded
               FROM crypto_holdings h JOIN crypto_assets a ON a.id = h.asset_id
             HAVING traded <> h.quantity"
        );
    }

    // ── Prices ───────────────────────────────────────────────────────

    public static function setPrice(array $asset, int $price, string $source, ?int $by): void
    {
        if ($price <= 0) {
            throw new BankingException('Price must be greater than zero.');
        }
        Db::transaction(function () use ($asset, $price, $source, $by) {
            // Reference for "24h change": the last price recorded at least 24 hours ago (or the oldest known).
            $ref = Db::value('SELECT price FROM crypto_price_history WHERE asset_id = ? AND recorded_at <= UTC_TIMESTAMP() - INTERVAL 1 DAY ORDER BY id DESC LIMIT 1', [$asset['id']])
                ?? Db::value('SELECT price FROM crypto_price_history WHERE asset_id = ? ORDER BY id ASC LIMIT 1', [$asset['id']])
                ?? $asset['price'];
            Db::update('crypto_assets', ['price' => $price, 'previous_price' => (int) $ref, 'price_updated_at' => now()], 'id = ?', [$asset['id']]);
            Db::insert('crypto_price_history', ['asset_id' => $asset['id'], 'price' => $price, 'source' => $source, 'set_by' => $by]);
            if ($source === 'manual') {
                AuditService::log('crypto.price_set', 'crypto_asset', $asset['symbol'], (int) $asset['price'], $price);
            }
        });
    }

    /** Random-walk price simulator for assets with volatility > 0 (run from cron). */
    public static function simulatePrices(): int
    {
        $n = 0;
        foreach (Db::all("SELECT * FROM crypto_assets WHERE status = 'active' AND volatility_bps > 0") as $a) {
            $step = random_int(-(int) $a['volatility_bps'], (int) $a['volatility_bps']);
            $new = max(1, (int) $a['price'] + intdiv((int) $a['price'] * $step, 10000));
            self::setPrice($a, $new, 'simulator', null);
            $n++;
        }
        return $n;
    }

    public static function history(int $assetId, int $limit = 60): array
    {
        return array_reverse(Db::all('SELECT price, recorded_at FROM crypto_price_history WHERE asset_id = ? ORDER BY id DESC LIMIT ' . (int) $limit, [$assetId]));
    }
}
