<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/**
 * Internal transfers: sender account → ledger → recipient account.
 * Everything happens inside one DB transaction; a partial transfer is impossible.
 */
final class TransferService
{
    public static function fee(int $amount): int
    {
        $fixed = (int) setting('transfer_fee_fixed', '0');
        $bps = (int) setting('transfer_fee_bps', '0');
        return $fixed + intdiv($amount * $bps + 5000, 10000); // round half up
    }

    /**
     * @return array{status:string, reference:string, transaction_id:int, fee:int}
     */
    public static function transfer(int $fromAccountId, string $toAccountNumber, int $amount, string $description, ?string $customerRef, int $initiatedBy): array
    {
        if ($amount <= 0) {
            throw new BankingException('Enter an amount greater than zero.');
        }
        $toAccountNumber = preg_replace('/\s+/', '', $toAccountNumber);
        $toId = (int) Db::value('SELECT id FROM accounts WHERE account_number = ? AND is_system = 0', [$toAccountNumber]);
        if (!$toId) {
            throw new BankingException('Recipient account not found. Please check the account number.');
        }
        if ($toId === $fromAccountId) {
            throw new BankingException('You cannot transfer to the same account.');
        }

        return Db::transaction(function () use ($fromAccountId, $toId, $amount, $description, $customerRef, $initiatedBy) {
            $accounts = AccountService::lockAccounts([$fromAccountId, $toId]);
            $from = $accounts[$fromAccountId];
            $to = $accounts[$toId];

            AccountService::assertCanDebit($from, 'transfers');
            AccountService::assertCanCredit($to);
            if ($from['currency'] !== $to['currency']) {
                throw new BankingException('Transfers between different currencies are not supported.');
            }
            AccountService::assertWithinLimits($from, 'transfer', $amount);

            $fee = self::fee($amount);
            if (AccountService::available($from) < $amount + $fee) {
                throw new BankingException('Insufficient available balance' . ($fee ? ' (including a fee of ' . money($fee) . ').' : '.'));
            }
            RiskService::assessTransfer($from, $to, $amount);

            $threshold = (int) setting('transfer_approval_threshold', '0');
            $needsApproval = $threshold > 0 && $amount >= $threshold;

            $txId = LedgerService::createTransaction('transfer', $amount, $from['currency'], [
                'from_account_id'    => $fromAccountId,
                'to_account_id'      => $toId,
                'description'        => $description ?: 'Transfer',
                'customer_reference' => $customerRef ?: null,
                'initiated_by'       => $initiatedBy,
                'fee_amount'         => $fee,
            ]);
            $reference = (string) Db::value('SELECT reference FROM transactions WHERE id = ?', [$txId]);

            if ($needsApproval) {
                // Reserve the funds until an approver decides.
                Db::query('UPDATE accounts SET held_amount = held_amount + ? WHERE id = ?', [$amount + $fee, $fromAccountId]);
                AuditService::log('transfer.pending_approval', 'transaction', $txId, null,
                    ['amount' => $amount, 'fee' => $fee, 'to' => $to['account_number']]);
                return ['status' => 'pending', 'reference' => $reference, 'transaction_id' => $txId, 'fee' => $fee];
            }

            self::settle($txId, $from, $to, $amount, $fee, $initiatedBy, null);
            return ['status' => 'completed', 'reference' => $reference, 'transaction_id' => $txId, 'fee' => $fee];
        });
    }

    private static function settle(int $txId, array $from, array $to, int $amount, int $fee, int $by, ?int $approvedBy): void
    {
        LedgerService::postEntries($txId, [
            ['account_id' => (int) $from['id'], 'entry' => 'debit', 'amount' => $amount, 'description' => 'Transfer to ' . mask_account($to['account_number'])],
            ['account_id' => (int) $to['id'], 'entry' => 'credit', 'amount' => $amount, 'description' => 'Transfer from ' . mask_account($from['account_number'])],
        ], $approvedBy);
        LedgerService::postFee((int) $from['id'], $fee, $from['currency'], $txId, 'Transfer fee', $by);

        AuditService::log('transfer.completed', 'transaction', $txId, null,
            ['amount' => $amount, 'fee' => $fee, 'from' => $from['account_number'], 'to' => $to['account_number']]);
        NotificationService::notifyAccountOwner((int) $from['id'], 'Transfer sent',
            money($amount, $from['currency']) . ' sent to ' . mask_account($to['account_number']) . '.', '/transactions');
        NotificationService::notifyAccountOwner((int) $to['id'], 'Money received',
            money($amount, $to['currency']) . ' received from ' . mask_account($from['account_number']) . '.', '/transactions');
    }

    public static function approve(int $txId, int $approverId, ?string $note): void
    {
        Db::transaction(function () use ($txId, $approverId, $note) {
            $tx = Db::one("SELECT * FROM transactions WHERE id = ? AND type = 'transfer' FOR UPDATE", [$txId]);
            if (!$tx || $tx['status'] !== 'pending') {
                throw new BankingException('This transfer is not awaiting approval.');
            }
            ApprovalService::assertMakerChecker((int) $tx['initiated_by'], $approverId);
            $accounts = AccountService::lockAccounts([$tx['from_account_id'], $tx['to_account_id']]);
            $from = $accounts[$tx['from_account_id']];
            $to = $accounts[$tx['to_account_id']];
            $total = (int) $tx['amount'] + (int) $tx['fee_amount'];

            Db::query('UPDATE accounts SET held_amount = GREATEST(held_amount - ?, 0) WHERE id = ?', [$total, $from['id']]);
            $from['held_amount'] = max(0, (int) $from['held_amount'] - $total);
            AccountService::assertCanDebit($from, 'transfers');
            AccountService::assertCanCredit($to);

            ApprovalService::record('transaction', $txId, 'approved', $approverId, $note);
            self::settle($txId, $from, $to, (int) $tx['amount'], (int) $tx['fee_amount'], (int) $tx['initiated_by'], $approverId);
        });
    }

    public static function reject(int $txId, int $approverId, string $note): void
    {
        Db::transaction(function () use ($txId, $approverId, $note) {
            $tx = Db::one("SELECT * FROM transactions WHERE id = ? AND type = 'transfer' FOR UPDATE", [$txId]);
            if (!$tx || $tx['status'] !== 'pending') {
                throw new BankingException('This transfer is not awaiting approval.');
            }
            AccountService::lockAccounts([$tx['from_account_id']]);
            $total = (int) $tx['amount'] + (int) $tx['fee_amount'];
            Db::query('UPDATE accounts SET held_amount = GREATEST(held_amount - ?, 0) WHERE id = ?', [$total, $tx['from_account_id']]);
            Db::update('transactions', ['status' => 'cancelled', 'approved_by' => $approverId], 'id = ?', [$txId]);
            ApprovalService::record('transaction', $txId, 'rejected', $approverId, $note);
            AuditService::log('transfer.rejected', 'transaction', $txId, 'pending', 'cancelled', $note);
            NotificationService::notifyAccountOwner((int) $tx['from_account_id'], 'Transfer not approved',
                'Your transfer ' . $tx['reference'] . ' was not approved. Held funds have been released.', '/transactions');
        });
    }
}
