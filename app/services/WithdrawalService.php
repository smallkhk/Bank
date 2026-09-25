<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/**
 * Withdrawal workflow: request (funds held) → review → approval posts ledger → completed.
 * Payout is simulated: funds move to the SYS-SETTLEMENT account. A real payout provider
 * would be called from approve() once integrated.
 */
final class WithdrawalService
{
    public static function fee(): int
    {
        return (int) setting('withdrawal_fee_fixed', '0');
    }

    public static function request(int $accountId, int $amount, string $method, string $details, int $requestedBy): int
    {
        if ($amount <= 0) {
            throw new BankingException('Enter an amount greater than zero.');
        }
        return Db::transaction(function () use ($accountId, $amount, $method, $details, $requestedBy) {
            $acc = AccountService::lockAccounts([$accountId])[$accountId];
            AccountService::assertCanDebit($acc, 'withdrawals');
            AccountService::assertWithinLimits($acc, 'withdrawal', $amount);
            $fee = self::fee();
            if (AccountService::available($acc) < $amount + $fee) {
                throw new BankingException('Insufficient available balance' . ($fee ? ' (including a fee of ' . money($fee) . ').' : '.'));
            }
            Db::query('UPDATE accounts SET held_amount = held_amount + ? WHERE id = ?', [$amount + $fee, $accountId]);
            $id = Db::insert('withdrawal_requests', [
                'reference'    => LedgerService::reference('WD'),
                'account_id'   => $accountId,
                'amount'       => $amount,
                'currency'     => $acc['currency'],
                'method'       => mb_substr($method, 0, 60),
                'details'      => mb_substr($details, 0, 500),
                'requested_by' => $requestedBy,
            ]);
            AuditService::log('withdrawal.requested', 'withdrawal_request', $id, null, ['amount' => $amount, 'fee' => $fee, 'account' => $acc['account_number']]);
            $ref = (string) Db::value('SELECT reference FROM withdrawal_requests WHERE id = ?', [$id]);
            NotificationService::eventForAccountOwner($accountId, 'withdrawal_requested', [
                'amount' => money($amount, $acc['currency']), 'account' => mask_account($acc['account_number']), 'reference' => $ref,
            ], '/withdrawals');
            return $id;
        });
    }

    private static function lockPending(int $id): array
    {
        $req = Db::one('SELECT * FROM withdrawal_requests WHERE id = ? FOR UPDATE', [$id]);
        if (!$req || $req['status'] !== 'pending') {
            throw new BankingException('This withdrawal is not pending.');
        }
        return $req;
    }

    public static function approve(int $id, int $approverId, ?string $note): void
    {
        Db::transaction(function () use ($id, $approverId, $note) {
            $req = self::lockPending($id);
            ApprovalService::assertMakerChecker((int) $req['requested_by'], $approverId);
            $acc = AccountService::lockAccounts([$req['account_id']])[$req['account_id']];
            $fee = self::fee();
            $held = (int) $req['amount'] + $fee;

            Db::query('UPDATE accounts SET held_amount = GREATEST(held_amount - ?, 0) WHERE id = ?', [$held, $acc['id']]);
            Db::update('withdrawal_requests', ['status' => 'processing', 'reviewed_by' => $approverId, 'reviewed_at' => now(), 'review_note' => $note], 'id = ?', [$id]);

            $txId = LedgerService::createTransaction('withdrawal', (int) $req['amount'], $req['currency'], [
                'from_account_id' => $acc['id'],
                'description'     => 'Withdrawal' . ($req['method'] ? ' via ' . $req['method'] : ''),
                'customer_reference' => $req['reference'],
                'initiated_by'    => $req['requested_by'],
                'approved_by'     => $approverId,
                'fee_amount'      => $fee,
            ]);
            LedgerService::postEntries($txId, [
                ['account_id' => (int) $acc['id'], 'entry' => 'debit', 'amount' => (int) $req['amount']],
                ['account_id' => LedgerService::systemAccountId(LedgerService::SYS_SETTLEMENT), 'entry' => 'credit', 'amount' => (int) $req['amount']],
            ], $approverId);
            LedgerService::postFee((int) $acc['id'], $fee, $req['currency'], $txId, 'Withdrawal fee', (int) $req['requested_by']);

            Db::update('withdrawal_requests', ['status' => 'completed', 'transaction_id' => $txId], 'id = ?', [$id]);
            ApprovalService::record('withdrawal_request', $id, 'approved', $approverId, $note);
            AuditService::log('withdrawal.approved', 'withdrawal_request', $id, 'pending', 'completed', $note);
            NotificationService::eventForAccountOwner((int) $acc['id'], 'withdrawal_completed', [
                'amount' => money((int) $req['amount'], $req['currency']), 'account' => mask_account($acc['account_number']), 'reference' => $req['reference'],
            ], '/withdrawals');
        });
    }

    public static function reject(int $id, int $approverId, string $note): void
    {
        self::close($id, 'rejected', $note, $approverId, true);
    }

    public static function cancel(int $id, int $userId): void
    {
        self::close($id, 'cancelled', 'Cancelled by requester', $userId, false);
    }

    private static function close(int $id, string $status, string $note, int $by, bool $isReview): void
    {
        Db::transaction(function () use ($id, $status, $note, $by, $isReview) {
            $req = self::lockPending($id);
            if (!$isReview && (int) $req['requested_by'] !== $by) {
                throw new BankingException('You can only cancel your own requests.');
            }
            AccountService::lockAccounts([$req['account_id']]);
            $held = (int) $req['amount'] + self::fee();
            Db::query('UPDATE accounts SET held_amount = GREATEST(held_amount - ?, 0) WHERE id = ?', [$held, $req['account_id']]);
            Db::update('withdrawal_requests', ['status' => $status, 'reviewed_by' => $by, 'reviewed_at' => now(), 'review_note' => $note], 'id = ?', [$id]);
            if ($isReview) {
                ApprovalService::record('withdrawal_request', $id, 'rejected', $by, $note);
            }
            AuditService::log('withdrawal.' . $status, 'withdrawal_request', $id, 'pending', $status, $note);
            NotificationService::eventForAccountOwner((int) $req['account_id'], 'withdrawal_closed',
                ['reference' => $req['reference'], 'status' => $status], '/withdrawals');
        });
    }
}
