<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/**
 * Basic fraud/risk rules. Extend with more rules or an external risk provider later.
 */
final class RiskService
{
    public static function assessTransfer(array $from, array $to, int $amount): void
    {
        // Velocity rule: too many outgoing transfers in a short window.
        $recent = (int) Db::value(
            "SELECT COUNT(*) FROM transactions WHERE from_account_id = ? AND type = 'transfer'
                AND created_at > (UTC_TIMESTAMP() - INTERVAL 10 MINUTE)",
            [$from['id']]
        );
        $maxPer10 = (int) setting('risk_max_transfers_10min', '10');
        if ($maxPer10 > 0 && $recent >= $maxPer10) {
            AuditService::log('risk.velocity_block', 'account', $from['id'], null, ['recent' => $recent, 'amount' => $amount]);
            throw new BankingException('Too many transfers in a short period. Please wait a few minutes or contact support.');
        }
    }
}
