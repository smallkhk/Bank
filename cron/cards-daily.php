<?php
declare(strict_types=1);

/**
 * Daily card jobs: credit card statements, interest, late fees and card expiry.
 * cPanel → Cron Jobs, e.g. every day at 01:10:
 *   10 1 * * * /usr/local/bin/php /home/USER/bank/cron/cards-daily.php
 * Idempotent. Optionally pass a date (YYYY-MM-DD) to process a specific day.
 */
if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
require __DIR__ . '/../app/bootstrap.php';

$day = $argv[1] ?? gmdate('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
    fwrite(STDERR, "Date must be YYYY-MM-DD\n");
    exit(1);
}
$r = App\Services\CreditCardService::runDaily($day);
App\Services\AuditService::log('cards.daily_run', 'cards', $day, null, $r);
printf("Cards %s: %d statements, %d interest charges, %d late fees, %d cards expired\n", $day, $r['statements'], $r['interest'], $r['late_fees'], $r['expired']);
