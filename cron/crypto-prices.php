<?php
declare(strict_types=1);

/**
 * Optional: move simulated crypto prices (random walk) for assets with a volatility setting.
 * cPanel → Cron Jobs, e.g. every 15 minutes:
 *   * /15 * * * * /usr/local/bin/php /home/USER/bank/cron/crypto-prices.php
 * (write it without the space between "*" and "/15"). Replace with a market-data feed later.
 */
if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
require __DIR__ . '/../app/bootstrap.php';
if (!App\Services\CryptoService::enabled()) {
    echo "Crypto module disabled\n";
    exit(0);
}
printf("Updated %d simulated prices\n", App\Services\CryptoService::simulatePrices());
