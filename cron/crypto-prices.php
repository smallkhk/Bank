<?php
declare(strict_types=1);

/**
 * Update crypto prices: live market prices from the CoinGecko integration for linked assets,
 * and the optional random walk for other assets with a volatility setting.
 * cPanel → Cron Jobs, e.g. every 15 minutes:
 *   * /15 * * * * /usr/local/bin/php /home/USER/bank/cron/crypto-prices.php
 * (write it without the space between "*" and "/15"). Every 5 minutes is fine on a CoinGecko demo key.
 */
if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
require __DIR__ . '/../app/bootstrap.php';
if (!App\Services\CryptoService::enabled()) {
    echo "Crypto module disabled\n";
    exit(0);
}
$feed = App\Services\CoinGeckoFeed::updatePrices();
printf("Live prices: %d updated, %d rejected, %d failed\n", $feed['updated'], $feed['skipped'], $feed['failed']);
foreach ($feed['messages'] as $m) {
    echo "  - $m\n";
}
printf("Simulated prices: %d updated\n", App\Services\CryptoService::simulatePrices());
