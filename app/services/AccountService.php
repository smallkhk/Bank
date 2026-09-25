<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Db;

final class AccountService
{
    public const STATUSES = ['active', 'restricted', 'frozen', 'locked', 'suspended', 'closed'];
    public const RESTRICTIONS = ['transfers', 'withdrawals', 'deposits', 'cards'];

    /**
     * Generate a unique account number: [prefix][branch][random digits][Luhn check digit].
     * Total length is configurable (account_number_length).
     */
    public static function generateNumber(): string
    {
        $prefix = preg_replace('/\D/', '', (string) setting('account_number_prefix', ''));
        $branch = preg_replace('/\D/', '', (string) setting('account_number_branch', ''));
        $length = max(8, min(20, (int) setting('account_number_length', '10')));
        $randomLen = $length - strlen($prefix) - strlen($branch) - 1;
        if ($randomLen < 4) {
            throw new \RuntimeException('Account number length is too short for the configured prefix/branch code.');
        }
        for ($i = 0; $i < 50; $i++) {
            $body = $prefix . $branch;
            // First random digit non-zero when there is no prefix, so numbers never start with 0.
            $body .= ($body === '' ? (string) random_int(1, 9) : (string) random_int(0, 9));
            for ($j = 1; $j < $randomLen; $j++) {
                $body .= (string) random_int(0, 9);
            }
            $number = $body . self::luhnDigit($body);
            if (!Db::value('SELECT 1 FROM accounts WHERE account_number = ?', [$number])) {
                return $number;
            }
        }
        throw new \RuntimeException('Could not generate a unique account number; increase the length.');
    }

    public static function luhnDigit(string $digits): int
    {
        $sum = 0;
        $double = true;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $d = (int) $digits[$i];
            if ($double) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $sum += $d;
            $double = !$double;
        }
        return (10 - $sum % 10) % 10;
    }

    public static function open(int $customerId, string $typeSlug, ?string $currency = null, ?string $nickname = null): int
    {
        $type = Db::one('SELECT * FROM account_types WHERE slug = ? AND is_active = 1', [$typeSlug]);
        if (!$type) {
            throw new BankingException('Unknown or inactive account type.');
        }
        // The unique index is the final guard against duplicates; retry on the rare collision.
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $id = Db::insert('accounts', [
                    'account_number'  => self::generateNumber(),
                    'customer_id'     => $customerId,
                    'account_type_id' => $type['id'],
                    'nickname'        => $nickname,
                    'currency'        => $currency ?: setting('currency', 'USD'),
                    'status'          => 'active',
                    'created_by'      => Auth::id(),
                ]);
                AuditService::log('account.created', 'account', $id, null, ['customer_id' => $customerId, 'type' => $typeSlug]);
                return $id;
            } catch (\PDOException $e) {
                if ($e->getCode() !== '23000' || $attempt === 2) {
                    throw $e;
                }
            }
        }
        throw new \RuntimeException('unreachable');
    }

    /** Lock rows in a consistent (id) order to avoid deadlocks. Returns rows keyed by id. */
    public static function lockAccounts(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        if (!$ids) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $rows = Db::all("SELECT * FROM accounts WHERE id IN ($in) ORDER BY id FOR UPDATE", $ids);
        $byId = array_column($rows, null, 'id');
        if (count($byId) !== count($ids)) {
            throw new BankingException('Account not found.');
        }
        return $byId;
    }

    public static function find(int $id): ?array
    {
        return Db::one(
            'SELECT a.*, t.name AS type_name, t.slug AS type_slug, u.full_name AS customer_name, c.user_id AS owner_user_id
               FROM accounts a
               LEFT JOIN account_types t ON t.id = a.account_type_id
               LEFT JOIN customers c ON c.id = a.customer_id
               LEFT JOIN users u ON u.id = c.user_id
              WHERE a.id = ?',
            [$id]
        );
    }

    public static function available(array $account): int
    {
        return (int) $account['balance'] - (int) $account['held_amount'];
    }

    public static function activeRestrictions(int $accountId): array
    {
        return Db::all(
            'SELECT r.*, u.full_name AS created_by_name FROM account_restrictions r
               JOIN users u ON u.id = r.created_by
              WHERE r.account_id = ? AND r.lifted_at IS NULL AND (r.expires_at IS NULL OR r.expires_at > UTC_TIMESTAMP())
              ORDER BY r.created_at DESC',
            [$accountId]
        );
    }

    public static function isRestricted(int $accountId, string $restriction): bool
    {
        return (bool) Db::value(
            'SELECT 1 FROM account_restrictions WHERE account_id = ? AND restriction = ? AND lifted_at IS NULL
                AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP()) LIMIT 1',
            [$accountId, $restriction]
        );
    }

    /**
     * Validate an account may be debited (money out) for the given operation.
     * $operation: transfers | withdrawals
     */
    public static function assertCanDebit(array $account, string $operation): void
    {
        if ($account['is_system']) {
            return;
        }
        if ($account['status'] !== 'active') {
            throw new BankingException('Account ' . mask_account($account['account_number']) . ' is ' . $account['status'] . ' and cannot send funds.');
        }
        if (self::isRestricted((int) $account['id'], $operation)) {
            throw new BankingException(ucfirst($operation) . ' are currently restricted on this account. Please contact support.');
        }
    }

    /** Validate an account may receive funds (credits). */
    public static function assertCanCredit(array $account): void
    {
        if ($account['is_system']) {
            return;
        }
        if (!in_array($account['status'], ['active', 'restricted', 'frozen'], true)) {
            throw new BankingException('The receiving account cannot accept funds at this time.');
        }
        if (self::isRestricted((int) $account['id'], 'deposits')) {
            throw new BankingException('The receiving account cannot accept funds at this time.');
        }
    }

    /** Effective limits: account override → account type → global default. */
    public static function limits(array $account): array
    {
        $type = $account['account_type_id']
            ? Db::one('SELECT * FROM account_types WHERE id = ?', [$account['account_type_id']])
            : null;
        $pick = static fn (string $col, string $settingKey) =>
            $account[$col] !== null ? (int) $account[$col]
                : ($type && $type[$col] !== null ? (int) $type[$col] : (int) setting($settingKey));
        return [
            'daily_transfer'   => $pick('daily_transfer_limit', 'default_daily_transfer_limit'),
            'daily_withdrawal' => $pick('daily_withdrawal_limit', 'default_daily_withdrawal_limit'),
            'monthly'          => $pick('monthly_limit', 'default_monthly_limit'),
        ];
    }

    /** Sum of outgoing amounts of a type since $since (pending + completed). */
    public static function outgoingSince(int $accountId, string $type, string $since): int
    {
        if ($type === 'withdrawal') {
            return (int) Db::value(
                "SELECT COALESCE(SUM(amount),0) FROM withdrawal_requests
                  WHERE account_id = ? AND status IN ('pending','approved','processing','completed') AND created_at >= ?",
                [$accountId, $since]
            );
        }
        return (int) Db::value(
            "SELECT COALESCE(SUM(amount),0) FROM transactions
              WHERE from_account_id = ? AND type = ? AND status IN ('pending','completed') AND created_at >= ?",
            [$accountId, $type, $since]
        );
    }

    public static function assertWithinLimits(array $account, string $type, int $amount): void
    {
        $limits = self::limits($account);
        $today = gmdate('Y-m-d 00:00:00');
        $month = gmdate('Y-m-01 00:00:00');
        $dailyKey = $type === 'withdrawal' ? 'daily_withdrawal' : 'daily_transfer';

        $usedToday = self::outgoingSince((int) $account['id'], $type, $today);
        if ($limits[$dailyKey] > 0 && $usedToday + $amount > $limits[$dailyKey]) {
            throw new BankingException(sprintf(
                'This exceeds your daily %s limit of %s (remaining today: %s).',
                $type, money($limits[$dailyKey]), money(max(0, $limits[$dailyKey] - $usedToday))
            ));
        }
        $usedMonth = self::outgoingSince((int) $account['id'], 'transfer', $month)
                   + self::outgoingSince((int) $account['id'], 'withdrawal', $month);
        if ($limits['monthly'] > 0 && $usedMonth + $amount > $limits['monthly']) {
            throw new BankingException('This exceeds your monthly limit of ' . money($limits['monthly']) . '.');
        }
    }

    public static function changeStatus(int $accountId, string $status, string $reason): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new BankingException('Invalid status.');
        }
        if (trim($reason) === '') {
            throw new BankingException('A reason is required.');
        }
        Db::transaction(function () use ($accountId, $status, $reason) {
            $acc = self::lockAccounts([$accountId])[$accountId];
            if ($acc['is_system']) {
                throw new BankingException('System accounts cannot be changed.');
            }
            if ($status === 'closed' && ((int) $acc['balance'] !== 0 || (int) $acc['held_amount'] !== 0)) {
                throw new BankingException('An account can only be closed with a zero balance and no pending holds.');
            }
            Db::update('accounts', ['status' => $status, 'status_reason' => $reason], 'id = ?', [$accountId]);
            AuditService::log('account.status_changed', 'account', $accountId, $acc['status'], $status, $reason);
            NotificationService::notifyAccountOwner($accountId, 'Account status updated',
                'Your account ' . mask_account($acc['account_number']) . ' is now ' . $status . '.');
        });
    }

    public static function addRestriction(int $accountId, string $restriction, string $reason, ?string $expiresAt): void
    {
        if (!in_array($restriction, self::RESTRICTIONS, true)) {
            throw new BankingException('Invalid restriction type.');
        }
        if (trim($reason) === '') {
            throw new BankingException('A reason is required.');
        }
        $id = Db::insert('account_restrictions', [
            'account_id'  => $accountId,
            'restriction' => $restriction,
            'reason'      => $reason,
            'created_by'  => Auth::id(),
            'expires_at'  => $expiresAt ?: null,
        ]);
        AuditService::log('account.restriction_added', 'account', $accountId, null,
            ['restriction' => $restriction, 'expires_at' => $expiresAt, 'restriction_id' => $id], $reason);
    }

    public static function liftRestriction(int $restrictionId, string $reason): void
    {
        $r = Db::one('SELECT * FROM account_restrictions WHERE id = ? AND lifted_at IS NULL', [$restrictionId]);
        if (!$r) {
            throw new BankingException('Restriction not found.');
        }
        Db::update('account_restrictions', ['lifted_at' => now(), 'lifted_by' => Auth::id()], 'id = ?', [$restrictionId]);
        AuditService::log('account.restriction_lifted', 'account', $r['account_id'], ['restriction' => $r['restriction']], null, $reason);
    }

    public static function setLimits(int $accountId, ?int $daily, ?int $dailyWithdrawal, ?int $monthly, string $reason): void
    {
        $old = Db::one('SELECT daily_transfer_limit, daily_withdrawal_limit, monthly_limit FROM accounts WHERE id = ?', [$accountId]);
        $new = ['daily_transfer_limit' => $daily, 'daily_withdrawal_limit' => $dailyWithdrawal, 'monthly_limit' => $monthly];
        Db::update('accounts', $new, 'id = ?', [$accountId]);
        AuditService::log('account.limits_changed', 'account', $accountId, $old, $new, $reason);
    }
}
