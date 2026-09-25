<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/**
 * Credit card accounts. The account balance is negative while money is owed; the ledger only
 * allows it down to -credit_limit (interest and penalty fees may exceed the limit).
 */
final class CreditCardService
{
    public static function owed(array $account): int
    {
        return max(0, -(int) $account['balance']);
    }

    public static function productForAccount(int $accountId): ?array
    {
        return Db::one('SELECT p.* FROM cards c JOIN card_products p ON p.id = c.product_id WHERE c.account_id = ? ORDER BY c.id DESC LIMIT 1', [$accountId]);
    }

    public static function latestStatement(int $accountId): ?array
    {
        return Db::one('SELECT * FROM credit_statements WHERE account_id = ? ORDER BY period_end DESC LIMIT 1', [$accountId]);
    }

    /** Payments received after a statement was issued (anchored on ledger position, not time). */
    public static function paidSince(array $statement): int
    {
        return (int) Db::value(
            "SELECT COALESCE(SUM(l.amount), 0) FROM ledger_entries l JOIN transactions t ON t.id = l.transaction_id
              WHERE l.account_id = ? AND l.entry_type = 'credit' AND t.type = 'transfer' AND l.id > ?",
            [$statement['account_id'], $statement['last_entry_id']]
        );
    }

    /** Pay the credit card from one of the customer's deposit accounts. */
    public static function pay(int $fromAccountId, int $creditAccountId, int $amount, int $by): int
    {
        if ($amount <= 0) {
            throw new BankingException('Enter an amount greater than zero.');
        }
        return Db::transaction(function () use ($fromAccountId, $creditAccountId, $amount, $by) {
            $accs = AccountService::lockAccounts([$fromAccountId, $creditAccountId]);
            [$from, $credit] = [$accs[$fromAccountId], $accs[$creditAccountId]];
            if ((int) $from['customer_id'] !== (int) $credit['customer_id'] || !AccountService::isCredit($credit)) {
                throw new BankingException('Invalid payment accounts.');
            }
            AccountService::assertCanDebit($from, 'transfers');
            $owed = self::owed($credit);
            if ($owed === 0) {
                throw new BankingException('There is nothing to pay on this card.');
            }
            if ($amount > $owed) {
                throw new BankingException('You can pay at most the outstanding balance of ' . money($owed, $credit['currency']) . '.');
            }
            if (AccountService::available($from) < $amount) {
                throw new BankingException('Insufficient available balance.');
            }
            $txId = LedgerService::createTransaction('transfer', $amount, $credit['currency'], [
                'from_account_id' => $from['id'], 'to_account_id' => $credit['id'],
                'description' => 'Credit card payment', 'initiated_by' => $by,
            ]);
            LedgerService::postEntries($txId, [
                ['account_id' => (int) $from['id'], 'entry' => 'debit', 'amount' => $amount, 'description' => 'Credit card payment'],
                ['account_id' => (int) $credit['id'], 'entry' => 'credit', 'amount' => $amount, 'description' => 'Payment received — thank you'],
            ]);
            self::refreshStatementStatus($creditAccountId);
            AuditService::log('credit.payment', 'account', $credit['id'], null, ['amount' => $amount, 'from' => $from['account_number']]);
            return $txId;
        });
    }

    public static function refreshStatementStatus(int $accountId): void
    {
        $st = self::latestStatement($accountId);
        if (!$st || $st['status'] === 'paid') {
            return;
        }
        $paid = self::paidSince($st);
        $status = $paid >= (int) $st['statement_balance'] ? 'paid'
            : ($paid >= (int) $st['minimum_payment'] && $st['status'] !== 'overdue' ? 'minimum_paid' : $st['status']);
        if ($status !== $st['status']) {
            Db::update('credit_statements', ['status' => $status], 'id = ?', [$st['id']]);
        }
    }

    /**
     * Daily job: generate statements on each product's statement day, apply interest when the
     * previous statement was not paid in full, charge late fees after the due date, expire cards.
     * Idempotent — safe to run more than once a day.
     */
    public static function runDaily(?string $today = null): array
    {
        $today = $today ?? gmdate('Y-m-d');
        $stats = ['statements' => 0, 'interest' => 0, 'late_fees' => 0, 'expired' => 0];

        // 1. Late fees on statements past their due date.
        foreach (Db::all("SELECT * FROM credit_statements WHERE status IN ('open','minimum_paid') AND due_date < ?", [$today]) as $st) {
            $paid = self::paidSince($st);
            if ($paid >= (int) $st['statement_balance']) {
                Db::update('credit_statements', ['status' => 'paid'], 'id = ?', [$st['id']]);
                continue;
            }
            if ($paid >= (int) $st['minimum_payment']) {
                Db::update('credit_statements', ['status' => 'minimum_paid'], 'id = ?', [$st['id']]);
                continue;
            }
            $product = self::productForAccount((int) $st['account_id']);
            $fee = (int) ($product['late_fee'] ?? 0);
            Db::transaction(function () use ($st, $fee, &$stats) {
                Db::update('credit_statements', ['status' => 'overdue', 'late_fee_charged' => $fee], 'id = ? AND status <> ?', [$st['id'], 'overdue']);
                if ($fee > 0) {
                    self::charge((int) $st['account_id'], $fee, 'Late payment fee', LedgerService::SYS_FEES);
                    $stats['late_fees']++;
                }
                NotificationService::eventForAccountOwner((int) $st['account_id'], 'credit_overdue', [
                    'amount' => money((int) $st['minimum_payment']), 'due' => fmt_date($st['due_date'], 'M j, Y'), 'fee' => money($fee),
                ], '/cards');
            });
        }

        // 2. Statements for credit accounts whose statement day is today.
        $day = (int) substr($today, 8, 2);
        $accounts = Db::all(
            "SELECT DISTINCT a.id, a.currency, p.id AS product_id, p.statement_day, p.min_payment_bps, p.min_payment_floor, p.grace_days, p.interest_apr_bps
               FROM accounts a JOIN cards c ON c.account_id = a.id JOIN card_products p ON p.id = c.product_id
              WHERE a.credit_limit > 0 AND a.status <> 'closed' AND c.status IN ('active','frozen','blocked') AND p.statement_day = ?",
            [$day]
        );
        $periodEnd = (new \DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
        foreach ($accounts as $a) {
            if (Db::value('SELECT 1 FROM credit_statements WHERE account_id = ? AND period_end = ?', [$a['id'], $periodEnd])
                || Db::value('SELECT 1 FROM accounts WHERE id = ? AND DATE(created_at) > ?', [$a['id'], $periodEnd])) {
                continue; // already issued, or the account is newer than this statement period
            }
            Db::transaction(function () use ($a, $today, $periodEnd, &$stats) {
                $prev = self::latestStatement((int) $a['id']);
                $interest = 0;
                // Interest applies only when the previous statement was not paid in full.
                if ($prev && (int) $a['interest_apr_bps'] > 0 && self::paidSince($prev) < (int) $prev['statement_balance']) {
                    $acc = AccountService::lockAccounts([$a['id']])[$a['id']];
                    $interest = intdiv(self::owed($acc) * (int) $a['interest_apr_bps'] + 60000, 120000); // monthly rate, rounded
                    if ($interest > 0) {
                        self::charge((int) $a['id'], $interest, 'Interest charge', LedgerService::SYS_INTEREST);
                        $stats['interest']++;
                    }
                }
                $acc = AccountService::lockAccounts([$a['id']])[$a['id']];
                $balance = self::owed($acc);
                $minimum = $balance === 0 ? 0 : min($balance, max((int) $a['min_payment_floor'], intdiv($balance * (int) $a['min_payment_bps'] + 5000, 10000)));
                $due = (new \DateTimeImmutable($today))->modify('+' . (int) $a['grace_days'] . ' days')->format('Y-m-d');
                Db::insert('credit_statements', [
                    'account_id' => $a['id'], 'period_start' => $prev ? (new \DateTimeImmutable($prev['period_end']))->modify('+1 day')->format('Y-m-d') : substr((string) $acc['created_at'], 0, 10),
                    'period_end' => $periodEnd, 'statement_balance' => $balance, 'minimum_payment' => $minimum, 'due_date' => $due,
                    'interest_charged' => $interest, 'status' => $balance === 0 ? 'paid' : 'open',
                    'last_entry_id' => (int) Db::value('SELECT COALESCE(MAX(id), 0) FROM ledger_entries WHERE account_id = ?', [$a['id']]),
                ]);
                if ($balance > 0) {
                    NotificationService::eventForAccountOwner((int) $a['id'], 'credit_statement', [
                        'balance' => money($balance, $acc['currency']), 'minimum' => money($minimum, $acc['currency']), 'due' => fmt_date($due, 'M j, Y'),
                    ], '/cards');
                }
                $stats['statements']++;
            });
        }

        // 3. Expire cards past their expiry month.
        foreach (Db::all("SELECT id, expiry_month, expiry_year FROM cards WHERE status IN ('active','frozen') AND expiry_year IS NOT NULL") as $c) {
            if (CardService::isExpired($c)) {
                Db::update('cards', ['status' => 'expired', 'status_reason' => 'Expired'], 'id = ?', [$c['id']]);
                AuditService::log('card.expired', 'card', $c['id'], null, null, null, null);
                $stats['expired']++;
            }
        }
        return $stats;
    }

    private static function charge(int $accountId, int $amount, string $description, string $incomeAccount): void
    {
        $txId = LedgerService::createTransaction('fee', $amount, (string) Db::value('SELECT currency FROM accounts WHERE id = ?', [$accountId]), [
            'from_account_id' => $accountId, 'description' => $description,
        ]);
        LedgerService::postEntries($txId, [
            ['account_id' => $accountId, 'entry' => 'debit', 'amount' => $amount],
            ['account_id' => LedgerService::systemAccountId($incomeAccount), 'entry' => 'credit', 'amount' => $amount],
        ], null, [$accountId]);
    }
}
