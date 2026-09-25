<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/** Recurring fees. Run monthly from cron (see cron/monthly-fees.php); safe to re-run. */
final class FeeService
{
    /**
     * Charge the monthly account fee for $period (YYYY-MM) to every eligible account.
     * @return array{charged:int, skipped:int, already:int, total:int}
     */
    public static function runMonthly(string $period): array
    {
        $default = (int) setting('monthly_account_fee', '0');
        $waiver = (int) setting('monthly_fee_min_balance_waiver', '0');
        $stats = ['charged' => 0, 'skipped' => 0, 'already' => 0, 'total' => 0];

        $accounts = Db::all(
            "SELECT a.id, a.account_number, a.currency, COALESCE(NULLIF(t.monthly_fee, 0), ?) AS fee
               FROM accounts a LEFT JOIN account_types t ON t.id = a.account_type_id
              WHERE a.is_system = 0 AND a.status <> 'closed' AND a.created_at < ?",
            [$default, $period . '-01 00:00:00']
        );
        foreach ($accounts as $row) {
            $fee = (int) $row['fee'];
            if ($fee <= 0) {
                continue;
            }
            try {
                Db::transaction(function () use ($row, $fee, $period, $waiver, &$stats) {
                    // Claim the (account, period) slot first — the unique key makes re-runs no-ops.
                    try {
                        $runId = Db::insert('fee_runs', ['fee_code' => 'monthly', 'account_id' => $row['id'], 'period' => $period, 'amount' => $fee, 'status' => 'skipped']);
                    } catch (\PDOException $e) {
                        if ($e->getCode() === '23000') {
                            $stats['already']++;
                            return;
                        }
                        throw $e;
                    }
                    $acc = AccountService::lockAccounts([$row['id']])[$row['id']];
                    if ($waiver > 0 && (int) $acc['balance'] >= $waiver) {
                        Db::update('fee_runs', ['note' => 'Waived: balance above threshold'], 'id = ?', [$runId]);
                        $stats['skipped']++;
                        return;
                    }
                    if (AccountService::available($acc) < $fee) {
                        Db::update('fee_runs', ['note' => 'Insufficient available balance'], 'id = ?', [$runId]);
                        $stats['skipped']++;
                        return;
                    }
                    $txId = LedgerService::createTransaction('fee', $fee, $acc['currency'], [
                        'from_account_id' => $acc['id'], 'description' => 'Monthly account fee ' . $period, 'customer_reference' => 'FEE-' . $period,
                    ]);
                    LedgerService::postEntries($txId, [
                        ['account_id' => (int) $acc['id'], 'entry' => 'debit', 'amount' => $fee],
                        ['account_id' => LedgerService::systemAccountId(LedgerService::SYS_FEES), 'entry' => 'credit', 'amount' => $fee],
                    ]);
                    Db::update('fee_runs', ['status' => 'charged', 'transaction_id' => $txId], 'id = ?', [$runId]);
                    NotificationService::eventForAccountOwner((int) $acc['id'], 'fee_charged',
                        ['fee' => 'monthly account fee', 'amount' => money($fee, $acc['currency']), 'account' => mask_account($acc['account_number'])], '/transactions');
                    $stats['charged']++;
                    $stats['total'] += $fee;
                });
            } catch (\Throwable $e) {
                \App\Core\ErrorHandler::log($e);
                $stats['skipped']++;
            }
        }
        AuditService::log('fees.monthly_run', 'fees', $period, null, $stats);
        return $stats;
    }
}
