<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/**
 * CoinGecko market-data feed (GET /simple/price). Only prices come from the market;
 * customer holdings and trades remain internal records.
 */
final class CoinGeckoFeed
{
    /** A price move bigger than this in one update is treated as bad data and not applied. */
    public const MAX_JUMP_BPS = 5000;

    private static function base(array $c): string
    {
        if (($c['api_base'] ?? '') !== '') {
            return rtrim($c['api_base'], '/');
        }
        return ($c['plan'] ?? 'demo') === 'pro' ? 'https://pro-api.coingecko.com/api/v3' : 'https://api.coingecko.com/api/v3';
    }

    private static function headers(array $c): array
    {
        $key = (string) ($c['api_key'] ?? '');
        if ($key === '') {
            return ['Accept' => 'application/json'];
        }
        return ['Accept' => 'application/json', (($c['plan'] ?? 'demo') === 'pro' ? 'x-cg-pro-api-key' : 'x-cg-demo-api-key') => $key];
    }

    public static function validate(array $c, string $mode): void
    {
        if (!in_array($c['plan'] ?? '', ['demo', 'pro'], true)) {
            throw new BankingException('Plan must be "demo" or "pro".');
        }
        if (($c['plan'] ?? '') === 'pro' && ($c['api_key'] ?? '') === '') {
            throw new BankingException('The pro plan needs an API key.');
        }
        if ($mode === 'live' && ($c['api_base'] ?? '') !== '' && !str_starts_with($c['api_base'], 'https://')) {
            throw new BankingException('Live mode requires an https:// API URL.');
        }
    }

    public static function test(array $c, string $mode): array
    {
        $r = HttpClient::request('coingecko', 'test.price', 'GET', self::base($c) . '/simple/price?ids=bitcoin&vs_currencies=' . strtolower((string) setting('currency', 'USD')), self::headers($c));
        $p = $r['json']['bitcoin'][strtolower((string) setting('currency', 'USD'))] ?? null;
        if ($r['status'] !== 200 || !is_numeric($p)) {
            return ['ok' => false, 'message' => $r['status'] === 401 || $r['status'] === 403 ? 'CoinGecko rejected the API key.'
                : ($r['status'] === 429 ? 'Rate limited by CoinGecko — add an API key.' : 'Could not get prices from CoinGecko (is ' . setting('currency') . ' supported?).')];
        }
        return ['ok' => true, 'message' => 'Connected. Bitcoin = ' . money((int) round((float) $p * 100)) . '.'];
    }

    /** Check that a coin id exists and is priced in the bank currency. Returns the price in minor units. */
    public static function lookup(string $feedId): ?int
    {
        $prices = self::fetch([$feedId]);
        return $prices[$feedId] ?? null;
    }

    /** @return array<string,int> feed id => price in minor units (only valid, non-zero prices) */
    public static function fetch(array $ids): array
    {
        $c = Integrations::config('coingecko');
        $cur = strtolower((string) setting('currency', 'USD'));
        $ids = array_values(array_unique(array_filter($ids, fn ($i) => preg_match('/^[a-z0-9-]{1,80}$/', $i))));
        $out = [];
        foreach (array_chunk($ids, 100) as $chunk) {
            $r = HttpClient::request('coingecko', 'prices', 'GET',
                self::base($c) . '/simple/price?ids=' . rawurlencode(implode(',', $chunk)) . '&vs_currencies=' . $cur, self::headers($c));
            if ($r['status'] !== 200 || !is_array($r['json'])) {
                continue;
            }
            foreach ($chunk as $id) {
                $v = $r['json'][$id][$cur] ?? null;
                if (is_numeric($v) && (float) $v > 0 && (float) $v < 1e12) {
                    $minor = (int) round((float) $v * 100);
                    if ($minor > 0) {
                        $out[$id] = $minor;
                    }
                }
            }
        }
        return $out;
    }

    /**
     * Update all feed-linked assets. Implausible jumps are skipped and logged, never applied.
     * @return array{updated:int, skipped:int, failed:int, messages:string[]}
     */
    public static function updatePrices(): array
    {
        $stats = ['updated' => 0, 'skipped' => 0, 'failed' => 0, 'messages' => []];
        if (!Integrations::enabled('coingecko')) {
            return $stats;
        }
        $assets = Db::all("SELECT * FROM crypto_assets WHERE feed_id IS NOT NULL AND feed_id <> '' AND status <> 'inactive'");
        if (!$assets) {
            return $stats;
        }
        $prices = self::fetch(array_column($assets, 'feed_id'));
        foreach ($assets as $a) {
            $new = $prices[$a['feed_id']] ?? null;
            if ($new === null) {
                $stats['failed']++;
                $stats['messages'][] = "{$a['symbol']}: no price returned for \"{$a['feed_id']}\"";
                continue;
            }
            $old = (int) $a['price'];
            if ($old > 0 && abs($new - $old) * 10000 > $old * self::MAX_JUMP_BPS) {
                $stats['skipped']++;
                $msg = "{$a['symbol']}: feed price " . money($new) . ' differs from ' . money($old) . ' by more than ' . (self::MAX_JUMP_BPS / 100) . '% — not applied';
                $stats['messages'][] = $msg;
                HttpClient::log('coingecko', 'in', 'price.rejected', null, false, null, $msg);
                AuditService::log('crypto.feed_price_rejected', 'crypto_asset', $a['symbol'], $old, $new);
                continue;
            }
            CryptoService::setPrice($a, $new, 'feed', null);
            $stats['updated']++;
        }
        return $stats;
    }
}
