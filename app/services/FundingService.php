<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Db;

/**
 * Add funds (simulated deposits) and manual balance adjustments.
 * Balances are never edited directly — every change posts to the ledger.
 */
final class FundingService
{
    public const KINDS = ['deposit', 'adjustment_credit', 'adjustment_debit'];

    /**
     * Create an add-funds / adjustment request. Deposits by staff holding funds.approve are
     * executed immediately when approval is not required; adjustments always need a second approver.
     *
     * @return array{id:int, status:string}
     */
    public static function request(int $accountId, int $amount, string $kind, string $reason, ?string $externalRef, int $requestedBy): array
    {
        if ($amount <= 0) {
            throw new BankingException('Enter an amount greater than zero.');
        }
        if (!in_array($kind, self::KINDS, true)) {
            throw new BankingException('Invalid request type.');
        }
        if (trim($reason) === '') {
            throw new BankingException('A reason is required.');
        }
        $acc = AccountService::find($accountId);
        if (!$acc || $acc['is_system']) {
            throw new BankingException('Account not found.');
        }

        $id = Db::insert('deposit_requests', [
            'reference'          => LedgerService::reference($kind === 'deposit' ? 'DP' : 'AJ'),
            'account_id'         => $accountId,
            'amount'             => $amount,
            'currency'           => $acc['currency'],
            'kind'               => $kind,
            'reason'             => mb_substr($reason, 0, 255),
            'external_reference' => $externalRef ?: null,
            'requested_by'       => $requestedBy,
        ]);
        AuditService::log('funds.requested', 'deposit_request', $id, null, ['kind' => $kind, 'amount' => $amount, 'account' => $acc['account_number']], $reason);

        $instant = $kind === 'deposit'
            && setting('add_funds_requires_approval', '1') !== '1'
            && Auth::isStaff() && Auth::can('funds.approve');
        if ($instant) {
            self::execute($id, $requestedBy, 'Auto-approved (approval not required)', true);
            return ['id' => $id, 'status' => 'approved'];
        }
        return ['id' => $id, 'status' => 'pending'];
    }

    public static function approve(int $id, int $approverId, ?string $note): void
    {
        self::execute($id, $approverId, $note, false);
    }

    private static function execute(int $id, int $approverId, ?string $note, bool $skipMakerChecker): void
    {
        Db::transaction(function () use ($id, $approverId, $note, $skipMakerChecker) {
            $req = Db::one('SELECT * FROM deposit_requests WHERE id = ? FOR UPDATE', [$id]);
            if (!$req || $req['status'] !== 'pending') {
                throw new BankingException('This request is not pending.');
            }
            if (!$skipMakerChecker) {
                ApprovalService::assertMakerChecker((int) $req['requested_by'], $approverId);
            }
            $acc = AccountService::lockAccounts([$req['account_id']])[$req['account_id']];
            $amount = (int) $req['amount'];

            if ($req['kind'] === 'adjustment_debit') {
                $type = 'adjustment';
                $legs = [
                    ['account_id' => (int) $acc['id'], 'entry' => 'debit', 'amount' => $amount],
                    ['account_id' => LedgerService::systemAccountId(LedgerService::SYS_ADJUST), 'entry' => 'credit', 'amount' => $amount],
                ];
                if (AccountService::available($acc) < $amount) {
                    throw new BankingException('The account does not have enough available balance for this debit adjustment.');
                }
                $meta = ['from_account_id' => $acc['id']];
            } else {
                AccountService::assertCanCredit($acc);
                $type = $req['kind'] === 'deposit' ? 'deposit' : 'adjustment';
                $source = $req['kind'] === 'deposit' ? LedgerService::SYS_FUNDING : LedgerService::SYS_ADJUST;
                $legs = [
                    ['account_id' => LedgerService::systemAccountId($source), 'entry' => 'debit', 'amount' => $amount],
                    ['account_id' => (int) $acc['id'], 'entry' => 'credit', 'amount' => $amount],
                ];
                $meta = ['to_account_id' => $acc['id']];
            }

            $txId = LedgerService::createTransaction($type, $amount, $req['currency'], $meta + [
                'description'        => $type === 'deposit' ? 'Deposit' : 'Balance adjustment: ' . $req['reason'],
                'customer_reference' => $req['external_reference'] ?: $req['reference'],
                'initiated_by'       => $req['requested_by'],
                'approved_by'        => $approverId,
            ]);
            LedgerService::postEntries($txId, $legs, $approverId);

            Db::update('deposit_requests', ['status' => 'approved', 'reviewed_by' => $approverId, 'reviewed_at' => now(), 'review_note' => $note, 'transaction_id' => $txId], 'id = ?', [$id]);
            ApprovalService::record('deposit_request', $id, 'approved', $approverId, $note);
            AuditService::log($type === 'deposit' ? 'funds.deposit_posted' : 'funds.adjustment_posted', 'account', $acc['id'],
                ['balance' => (int) $acc['balance']], ['transaction_id' => $txId, 'kind' => $req['kind'], 'amount' => $amount], $req['reason']);
            NotificationService::notifyAccountOwner((int) $acc['id'],
                $req['kind'] === 'adjustment_debit' ? 'Account debited' : 'Funds added',
                money($amount, $req['currency']) . ($req['kind'] === 'adjustment_debit' ? ' was debited from ' : ' was credited to ') . mask_account($acc['account_number']) . '.',
                '/transactions');
        });
    }

    public static function reject(int $id, int $approverId, string $note): void
    {
        Db::transaction(function () use ($id, $approverId, $note) {
            $req = Db::one('SELECT * FROM deposit_requests WHERE id = ? FOR UPDATE', [$id]);
            if (!$req || $req['status'] !== 'pending') {
                throw new BankingException('This request is not pending.');
            }
            Db::update('deposit_requests', ['status' => 'rejected', 'reviewed_by' => $approverId, 'reviewed_at' => now(), 'review_note' => $note], 'id = ?', [$id]);
            ApprovalService::record('deposit_request', $id, 'rejected', $approverId, $note);
            AuditService::log('funds.rejected', 'deposit_request', $id, 'pending', 'rejected', $note);
        });
    }
}
