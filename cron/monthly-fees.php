<?php
declare(strict_types=1);

/**
 * Charge monthly account fees. cPanel → Cron Jobs, e.g. 02:15 on the 1st of each month:
 *   15 2 1 * * /usr/local/bin/php /home/USER/bank/cron/monthly-fees.php
 * Charges the PREVIOUS month by default; pass YYYY-MM to charge a specific period.
 * Idempotent: an account is never charged twice for the same period.
 */
if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
require __DIR__ . '/../app/bootstrap.php';

$period = $argv[1] ?? gmdate('Y-m', strtotime('first day of last month'));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
    fwrite(STDERR, "Period must be YYYY-MM\n");
    exit(1);
}
$r = App\Services\FeeService::runMonthly($period);
printf("Monthly fees %s: %d charged (%s), %d skipped/waived, %d already processed\n",
    $period, $r['charged'], App\Services\Money::toDecimal($r['total']), $r['skipped'], $r['already']);
