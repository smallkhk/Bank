<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/**
 * Double-entry ledger. The ledger is the source of truth — accounts.balance is a
 * cache that is ONLY modified here, in the same DB transaction as the ledger rows.
 *
 * Convention (customer-facing): a debit reduces an account balance, a credit increases it.
 * Every posting must balance (total debits == total credits). Money entering or
 * leaving the platform flows through internal system accounts (SYS-FUNDING, etc.).
 */
final class LedgerService
{
    public const SYS_FUNDING    = 'SYS-FUNDING';     // simulated external deposits
    public const SYS_SETTLEMENT = 'SYS-SETTLEMENT';  // simulated external withdrawals
    public const SYS_FEES       = 'SYS-FEES';        // fee income
    public const SYS_ADJUST     = 'SYS-ADJUST';      // manual adjustments

    public static function reference(string $prefix = 'TX'): string
    {
        return $prefix . gmdate('ymd') . strtoupper(bin2hex(random_bytes(5)));
    }

    public static function systemAccountId(string $code): int
    {
        $id = Db::value('SELECT id FROM accounts WHERE system_code = ?', [$code]);
        if (!$id) {
            throw new \RuntimeException("System account $code is missing — run the installer.");
        }
        return (int) $id;
    }

    /**
     * Create a transaction header (no balance movement yet).
     */
    public static function createTransaction(string $type, int $amount, string $currency, array $meta = []): int
    {
        return Db::insert('transactions', [
            'reference'          => $meta['reference'] ?? self::reference(),
            'type'               => $type,
            'status'             => $meta['status'] ?? 'pending',
            'amount'             => $amount,
            'currency'           => $currency,
            'fee_amount'         => $meta['fee_amount'] ?? 0,
            'from_account_id'    => $meta['from_account_id'] ?? null,
            'to_account_id'      => $meta['to_account_id'] ?? null,
            'description'        => $meta['description'] ?? null,
            'customer_reference' => $meta['customer_reference'] ?? null,
            'initiated_by'       => $meta['initiated_by'] ?? null,
            'approved_by'        => $meta['approved_by'] ?? null,
            'parent_id'          => $meta['parent_id'] ?? null,
            'ip_address'         => PHP_SAPI === 'cli' ? null : client_ip(),
        ]);
    }

    /**
     * Post balanced ledger legs for an existing transaction and mark it completed.
     * Must run inside Db::transaction().
     *
     * @param array<int, array{account_id:int, entry:string, amount:int, description?:string}> $legs
     */
    public static function postEntries(int $transactionId, array $legs, ?int $approvedBy = null): void
    {
        $pdo = Db::pdo();
        if (!$pdo->inTransaction()) {
            throw new \LogicException('Ledger postings must run inside a database transaction.');
        }

        $debits = $credits = 0;
        foreach ($legs as $leg) {
            if ($leg['amount'] <= 0) {
                throw new \LogicException('Ledger leg amounts must be positive.');
            }
            if ($leg['entry'] === 'debit') {
                $debits += $leg['amount'];
            } elseif ($leg['entry'] === 'credit') {
                $credits += $leg['amount'];
            } else {
                throw new \LogicException('Invalid ledger entry type.');
            }
        }
        if ($debits !== $credits) {
            throw new \LogicException("Unbalanced posting: debits $debits != credits $credits");
        }

        $tx = Db::one('SELECT * FROM transactions WHERE id = ? FOR UPDATE', [$transactionId]);
        if (!$tx || $tx['status'] !== 'pending') {
            throw new BankingException('This transaction can no longer be processed.');
        }

        $accounts = AccountService::lockAccounts(array_column($legs, 'account_id'));

        foreach ($legs as $leg) {
            $acc = &$accounts[$leg['account_id']];
            if ($acc['currency'] !== $tx['currency']) {
                throw new BankingException('Currency mismatch between accounts.');
            }
            if ($acc['status'] === 'closed') {
                throw new BankingException('Account ' . $acc['account_number'] . ' is closed.');
            }
            $before = (int) $acc['balance'];
            $after = $leg['entry'] === 'debit' ? $before - $leg['amount'] : $before + $leg['amount'];
            if (!$acc['is_system'] && $after < 0) {
                throw new BankingException('Insufficient funds.');
            }
            Db::insert('ledger_entries', [
                'transaction_id' => $transactionId,
                'account_id'     => $acc['id'],
                'customer_id'    => $acc['customer_id'],
                'entry_type'     => $leg['entry'],
                'amount'         => $leg['amount'],
                'currency'       => $tx['currency'],
                'balance_before' => $before,
                'balance_after'  => $after,
                'description'    => $leg['description'] ?? $tx['description'],
            ]);
            Db::update('accounts', ['balance' => $after], 'id = ?', [$acc['id']]);
            $acc['balance'] = $after;
            unset($acc);
        }

        Db::update('transactions', [
            'status'       => 'completed',
            'completed_at' => now(),
            'approved_by'  => $approvedBy ?? $tx['approved_by'],
        ], 'id = ?', [$transactionId]);
    }

    /** Convenience: create a transaction and post it immediately. */
    public static function post(string $type, int $amount, string $currency, array $legs, array $meta = []): int
    {
        return Db::transaction(function () use ($type, $amount, $currency, $legs, $meta) {
            $id = self::createTransaction($type, $amount, $currency, ['status' => 'pending'] + $meta);
            self::postEntries($id, $legs, $meta['approved_by'] ?? null);
            return $id;
        });
    }

    /** Post a fee (debit customer, credit fee income) linked to a parent transaction. */
    public static function postFee(int $accountId, int $fee, string $currency, int $parentId, string $description, ?int $by): ?int
    {
        if ($fee <= 0) {
            return null;
        }
        return self::post('fee', $fee, $currency, [
            ['account_id' => $accountId, 'entry' => 'debit', 'amount' => $fee],
            ['account_id' => self::systemAccountId(self::SYS_FEES), 'entry' => 'credit', 'amount' => $fee],
        ], [
            'from_account_id' => $accountId,
            'description'     => $description,
            'parent_id'       => $parentId,
            'initiated_by'    => $by,
        ]);
    }

    /** Recompute an account's balance from the ledger (source of truth). */
    public static function ledgerBalance(int $accountId): int
    {
        return (int) Db::value(
            "SELECT COALESCE(SUM(CASE entry_type WHEN 'credit' THEN amount ELSE -amount END), 0)
               FROM ledger_entries WHERE account_id = ?",
            [$accountId]
        );
    }

    /** Accounts whose cached balance differs from the ledger. Should always be empty. */
    public static function reconciliationBreaks(): array
    {
        return Db::all(
            "SELECT a.id, a.account_number, a.balance,
                    COALESCE(SUM(CASE l.entry_type WHEN 'credit' THEN l.amount ELSE -l.amount END), 0) AS ledger_balance
               FROM accounts a LEFT JOIN ledger_entries l ON l.account_id = a.id
              GROUP BY a.id, a.account_number, a.balance
             HAVING ledger_balance <> a.balance"
        );
    }
}
