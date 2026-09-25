<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Db;

/**
 * Simulated card issuing and processing. Cards are internal records — no card network,
 * processor or ATM integration. Card spend posts to the ledger against SYS-CARDS.
 */
final class CardService
{
    public const CHANNELS = ['pos' => 'In-store', 'online' => 'Online', 'atm' => 'ATM'];

    public static function find(int $id): ?array
    {
        return Db::one(
            'SELECT c.*, p.name AS product_name, p.card_type, p.form_factor, p.daily_limit AS product_daily_limit,
                    p.monthly_limit AS product_monthly_limit, p.atm_daily_limit, p.online_enabled AS product_online,
                    p.atm_enabled AS product_atm, p.international_enabled AS product_international, p.international_fee_bps,
                    p.issuance_fee, p.replacement_fee, p.expiry_months, p.credit_limit AS product_credit_limit,
                    a.account_number, a.balance, a.held_amount, a.credit_limit, a.currency, a.status AS account_status,
                    u.full_name AS customer_name, cu.user_id AS owner_user_id
               FROM cards c JOIN card_products p ON p.id = c.product_id
               JOIN customers cu ON cu.id = c.customer_id JOIN users u ON u.id = cu.user_id
               LEFT JOIN accounts a ON a.id = c.account_id
              WHERE c.id = ?',
            [$id]
        );
    }

    public static function masked(array $card): string
    {
        return $card['pan_last4'] ? '•••• •••• •••• ' . $card['pan_last4'] : 'Pending issue';
    }

    public static function expiry(array $card): string
    {
        return $card['expiry_month'] ? sprintf('%02d/%02d', $card['expiry_month'], $card['expiry_year'] % 100) : '—';
    }

    // ── Requests & issuance ──────────────────────────────────────────

    public static function request(int $customerId, int $productId, ?int $accountId, int $requestedBy, ?int $replaces = null): int
    {
        if (setting('cards_enabled') !== '1') {
            throw new BankingException('Cards are not available at the moment.');
        }
        $product = Db::one("SELECT * FROM card_products WHERE id = ? AND status = 'active'", [$productId]);
        if (!$product || (!Auth::isStaff() && !$product['customer_requestable'] && !$replaces)) {
            throw new BankingException('This card product is not available.');
        }
        if ($product['card_type'] === 'credit' && setting('credit_cards_enabled') !== '1') {
            throw new BankingException('Credit cards are not available at the moment.');
        }
        $open = (int) Db::value("SELECT COUNT(*) FROM cards WHERE customer_id = ? AND status IN ('pending','active','frozen')", [$customerId]);
        if (!$replaces && $open >= (int) setting('max_cards_per_customer', '5')) {
            throw new BankingException('You have reached the maximum number of cards.');
        }
        if ($product['card_type'] === 'credit') {
            $accountId = null; // a dedicated credit account is opened on issue
            if (!$replaces && Db::value("SELECT 1 FROM cards WHERE customer_id = ? AND product_id = ? AND status IN ('pending','active','frozen')", [$customerId, $productId])) {
                throw new BankingException('You already have or have requested this credit card.');
            }
        } else {
            $acc = $accountId ? AccountService::find($accountId) : null;
            if (!$acc || (int) $acc['customer_id'] !== $customerId || $acc['status'] !== 'active' || AccountService::isCredit($acc)) {
                throw new BankingException('Choose one of your active accounts to link the card to.');
            }
        }
        $holder = (string) Db::value('SELECT u.full_name FROM customers c JOIN users u ON u.id = c.user_id WHERE c.id = ?', [$customerId]);
        $id = Db::insert('cards', [
            'product_id' => $productId, 'customer_id' => $customerId, 'account_id' => $accountId,
            'cardholder_name' => mb_strtoupper(mb_substr($holder, 0, 26)),
            'online_enabled' => $product['online_enabled'], 'atm_enabled' => $product['atm_enabled'], 'international_enabled' => 0,
            'requested_by' => $requestedBy, 'replaces_card_id' => $replaces,
        ]);
        AuditService::log('card.requested', 'card', $id, null, ['product' => $product['name'], 'replaces' => $replaces]);
        return $id;
    }

    /** Approve a pending card: open credit account if needed, generate secure data, charge fee. */
    public static function issue(int $cardId, int $issuerId): void
    {
        Db::transaction(function () use ($cardId, $issuerId) {
            $card = Db::one('SELECT * FROM cards WHERE id = ? FOR UPDATE', [$cardId]);
            if (!$card || $card['status'] !== 'pending') {
                throw new BankingException('This card is not awaiting issue.');
            }
            ApprovalService::assertMakerChecker((int) $card['requested_by'], $issuerId);
            $product = Db::one('SELECT * FROM card_products WHERE id = ?', [$card['product_id']]);

            $accountId = $card['account_id'] ? (int) $card['account_id'] : null;
            if ($product['card_type'] === 'credit') {
                $old = $card['replaces_card_id'] ? Db::value('SELECT account_id FROM cards WHERE id = ?', [$card['replaces_card_id']]) : null;
                $accountId = $old ? (int) $old : AccountService::open((int) $card['customer_id'], 'credit', null, $product['name'], (int) $product['credit_limit']);
            }

            [$pan, $cvv] = [self::generatePan($product['bin_prefix']), str_pad((string) random_int(0, 999), 3, '0', STR_PAD_LEFT)];
            $expiry = (new \DateTimeImmutable('first day of this month'))->modify('+' . (int) $product['expiry_months'] . ' months');
            Db::update('cards', [
                'account_id' => $accountId, 'pan_encrypted' => CardVault::encrypt($pan), 'pan_hash' => CardVault::panHash($pan),
                'pan_last4' => substr($pan, -4), 'cvv_encrypted' => CardVault::encrypt($cvv),
                'expiry_month' => (int) $expiry->format('n'), 'expiry_year' => (int) $expiry->format('Y'),
                'status' => 'active', 'issued_by' => $issuerId, 'issued_at' => now(),
            ], 'id = ?', [$cardId]);

            $fee = (int) ($card['replaces_card_id'] ? $product['replacement_fee'] : $product['issuance_fee']);
            if ($fee > 0) {
                $txId = LedgerService::createTransaction('fee', $fee, (string) Db::value('SELECT currency FROM accounts WHERE id = ?', [$accountId]), [
                    'from_account_id' => $accountId, 'description' => ($card['replaces_card_id'] ? 'Card replacement fee' : 'Card issuance fee') . ' •' . substr($pan, -4),
                    'initiated_by' => $issuerId,
                ]);
                LedgerService::postEntries($txId, [
                    ['account_id' => $accountId, 'entry' => 'debit', 'amount' => $fee],
                    ['account_id' => LedgerService::systemAccountId(LedgerService::SYS_FEES), 'entry' => 'credit', 'amount' => $fee],
                ], null, [$accountId]);
            }
            ApprovalService::record('card', $cardId, 'approved', $issuerId, null);
            AuditService::log('card.issued', 'card', $cardId, 'pending', 'active', null);
            NotificationService::eventForAccountOwner($accountId, 'card_issued', [
                'card' => $product['name'] . ' •' . substr($pan, -4), 'expiry' => $expiry->format('m/y'),
            ], '/cards/' . $cardId);
        });
    }

    public static function reject(int $cardId, int $by, string $reason): void
    {
        $card = Db::one('SELECT * FROM cards WHERE id = ?', [$cardId]);
        if (!$card || $card['status'] !== 'pending') {
            throw new BankingException('This card is not awaiting issue.');
        }
        Db::update('cards', ['status' => 'rejected', 'status_reason' => $reason, 'issued_by' => $by], 'id = ?', [$cardId]);
        ApprovalService::record('card', $cardId, 'rejected', $by, $reason);
        AuditService::log('card.rejected', 'card', $cardId, 'pending', 'rejected', $reason);
        $uid = (int) Db::value('SELECT user_id FROM customers WHERE id = ?', [$card['customer_id']]);
        NotificationService::event($uid, 'card_status', ['card' => 'card request', 'status' => 'declined', 'reason' => $reason], '/cards');
    }

    public static function generatePan(string $bin): string
    {
        $bin = preg_replace('/\D/', '', $bin) ?: '4';
        for ($i = 0; $i < 20; $i++) {
            $body = $bin;
            while (strlen($body) < 15) {
                $body .= (string) random_int(0, 9);
            }
            $pan = $body . AccountService::luhnDigit($body);
            if (!Db::value('SELECT 1 FROM cards WHERE pan_hash = ?', [CardVault::panHash($pan)])) {
                return $pan;
            }
        }
        throw new \RuntimeException('Could not generate a unique card number.');
    }

    // ── Lifecycle & controls ─────────────────────────────────────────

    /**
     * Status transitions. Customers may only freeze/unfreeze. Blocked, cancelled and expired are final.
     */
    public static function setStatus(array $card, string $status, string $reason): void
    {
        $allowed = [
            'active' => ['frozen', 'blocked', 'cancelled'],
            'frozen' => ['active', 'blocked', 'cancelled'],
            'pending' => ['cancelled'],
        ];
        if (!in_array($status, $allowed[$card['status']] ?? [], true)) {
            throw new BankingException('This card cannot be changed from ' . $card['status'] . ' to ' . $status . '.');
        }
        if (Auth::isCustomer() && !in_array($status, ['frozen', 'active'], true)) {
            throw new BankingException('Please contact us or report the card lost or stolen.');
        }
        if ($status === 'active' && $card['expiry_year'] && self::isExpired($card)) {
            throw new BankingException('This card has expired.');
        }
        Db::update('cards', ['status' => $status, 'status_reason' => $reason], 'id = ?', [$card['id']]);
        AuditService::log('card.status_changed', 'card', $card['id'], $card['status'], $status, $reason);
        $label = ['frozen' => 'frozen', 'active' => ($card['status'] === 'frozen' ? 'unfrozen' : 'active'), 'blocked' => 'blocked', 'cancelled' => 'cancelled'][$status];
        NotificationService::event((int) $card['owner_user_id'], 'card_status', [
            'card' => $card['product_name'] . ' •' . $card['pan_last4'], 'status' => $label, 'reason' => $reason,
        ], '/cards/' . $card['id']);
    }

    public static function updateControls(array $card, bool $online, bool $atm, bool $intl, ?int $dailyLimit): void
    {
        if (in_array($card['status'], ['blocked', 'cancelled', 'expired', 'rejected'], true)) {
            throw new BankingException('This card can no longer be changed.');
        }
        $productDaily = (int) $card['product_daily_limit'];
        if ($dailyLimit !== null && ($dailyLimit <= 0 || ($productDaily > 0 && $dailyLimit > $productDaily))) {
            throw new BankingException('Daily limit must be between 0.01 and ' . money($productDaily) . '.');
        }
        $new = [
            'online_enabled' => $online && $card['product_online'] ? 1 : 0,
            'atm_enabled' => $atm && $card['product_atm'] ? 1 : 0,
            'international_enabled' => $intl && $card['product_international'] ? 1 : 0,
            'daily_limit' => $dailyLimit,
        ];
        $old = array_intersect_key($card, $new);
        Db::update('cards', $new, 'id = ?', [$card['id']]);
        AuditService::log('card.controls_changed', 'card', $card['id'], $old, $new);
    }

    /** Lost/stolen/damaged: block the old card now and queue a replacement for issue. */
    public static function replace(array $card, string $reason, int $by): int
    {
        if (!in_array($card['status'], ['active', 'frozen'], true)) {
            throw new BankingException('Only active or frozen cards can be replaced.');
        }
        return Db::transaction(function () use ($card, $reason, $by) {
            Db::update('cards', ['status' => 'blocked', 'status_reason' => 'Replaced: ' . $reason], 'id = ?', [$card['id']]);
            AuditService::log('card.blocked_for_replacement', 'card', $card['id'], $card['status'], 'blocked', $reason);
            return self::request((int) $card['customer_id'], (int) $card['product_id'], $card['account_id'] ? (int) $card['account_id'] : null, $by, (int) $card['id']);
        });
    }

    public static function isExpired(array $card): bool
    {
        if (!$card['expiry_year']) {
            return false;
        }
        $end = (new \DateTimeImmutable(sprintf('%04d-%02d-01', $card['expiry_year'], $card['expiry_month'])))->modify('last day of this month 23:59:59');
        return $end < new \DateTimeImmutable('now');
    }

    /** Full details for the cardholder (password re-authentication required by the controller). */
    public static function reveal(array $card): array
    {
        AuditService::log('card.details_revealed', 'card', $card['id']);
        $pan = CardVault::decrypt((string) $card['pan_encrypted']);
        return [
            'number' => trim(chunk_split($pan, 4, ' ')), 'cvv' => CardVault::decrypt((string) $card['cvv_encrypted']),
            'expiry' => self::expiry($card), 'name' => $card['cardholder_name'],
        ];
    }

    // ── Authorisation (simulated card network) ───────────────────────

    /**
     * Authorise and post a card transaction. Declines are recorded but never touch the ledger.
     * @return array{id:int, status:string, reason:?string}
     */
    public static function authorize(int $cardId, int $amount, string $merchant, ?string $category, string $channel, string $country): array
    {
        if ($amount <= 0 || !isset(self::CHANNELS[$channel]) || !preg_match('/^[A-Z]{2}$/', $country) || trim($merchant) === '') {
            throw new BankingException('Invalid transaction details.');
        }
        return Db::transaction(function () use ($cardId, $amount, $merchant, $category, $channel, $country) {
            $card = self::find($cardId);
            if (!$card || !$card['account_id']) {
                throw new BankingException('Card not found.');
            }
            $acc = AccountService::lockAccounts([$card['account_id']])[$card['account_id']];
            $home = strtoupper((string) setting('bank_country', 'US'));
            $international = $country !== $home;
            $fee = $international ? intdiv($amount * (int) $card['international_fee_bps'] + 5000, 10000) : 0;

            $reason = self::declineReason($card, $acc, $amount + $fee, $channel, $international);
            $record = [
                'card_id' => $cardId, 'merchant_name' => mb_substr(trim($merchant), 0, 120), 'merchant_category' => $category ? mb_substr($category, 0, 60) : null,
                'channel' => $channel, 'country' => $country, 'amount' => $amount, 'fee_amount' => $fee, 'currency' => $acc['currency'],
                'created_by' => Auth::id(),
            ];
            if ($reason !== null) {
                $id = Db::insert('card_transactions', $record + ['status' => 'declined', 'decline_reason' => $reason]);
                AuditService::log('card.declined', 'card', $cardId, null, ['amount' => $amount, 'merchant' => $merchant, 'reason' => $reason]);
                NotificationService::event((int) $card['owner_user_id'], 'card_declined', [
                    'card' => '•' . $card['pan_last4'], 'amount' => money($amount, $acc['currency']), 'merchant' => $merchant, 'reason' => $reason,
                ], '/cards/' . $cardId);
                return ['id' => $id, 'status' => 'declined', 'reason' => $reason];
            }

            $txId = LedgerService::createTransaction('card', $amount, $acc['currency'], [
                'from_account_id' => $acc['id'], 'description' => mb_substr(trim($merchant), 0, 100) . ($channel === 'atm' ? ' (ATM)' : ''),
                'customer_reference' => 'CARD •' . $card['pan_last4'], 'initiated_by' => Auth::id(), 'fee_amount' => $fee,
            ]);
            LedgerService::postEntries($txId, [
                ['account_id' => (int) $acc['id'], 'entry' => 'debit', 'amount' => $amount],
                ['account_id' => LedgerService::systemAccountId(LedgerService::SYS_CARDS), 'entry' => 'credit', 'amount' => $amount],
            ]);
            LedgerService::postFee((int) $acc['id'], $fee, $acc['currency'], $txId, 'International card fee', Auth::id());
            $id = Db::insert('card_transactions', $record + ['status' => 'approved', 'transaction_id' => $txId]);
            NotificationService::event((int) $card['owner_user_id'], 'card_transaction', [
                'card' => '•' . $card['pan_last4'], 'amount' => money($amount, $acc['currency']), 'merchant' => $merchant,
            ], '/cards/' . $cardId);
            return ['id' => $id, 'status' => 'approved', 'reason' => null];
        });
    }

    private static function declineReason(array $card, array $acc, int $total, string $channel, bool $international): ?string
    {
        if ($card['status'] !== 'active') {
            return 'Card ' . $card['status'];
        }
        if (self::isExpired($card)) {
            return 'Card expired';
        }
        if ($acc['status'] !== 'active' || AccountService::isRestricted((int) $acc['id'], 'cards')) {
            return 'Account restricted';
        }
        if ($channel === 'online' && !($card['online_enabled'] && $card['product_online'])) {
            return 'Online payments disabled';
        }
        if ($channel === 'atm' && !($card['atm_enabled'] && $card['product_atm'])) {
            return 'ATM use disabled';
        }
        if ($international && !($card['international_enabled'] && $card['product_international'])) {
            return 'International use disabled';
        }
        $spent = fn (string $since, ?string $ch = null) => (int) Db::value(
            "SELECT COALESCE(SUM(amount + fee_amount), 0) FROM card_transactions WHERE card_id = ? AND status = 'approved' AND created_at >= ?" . ($ch ? ' AND channel = ?' : ''),
            array_merge([$card['id'], $since], $ch ? [$ch] : [])
        );
        $today = gmdate('Y-m-d 00:00:00');
        $daily = $card['daily_limit'] !== null ? (int) $card['daily_limit'] : (int) $card['product_daily_limit'];
        if ($daily > 0 && $spent($today) + $total > $daily) {
            return 'Daily card limit exceeded';
        }
        if ((int) $card['product_monthly_limit'] > 0 && $spent(gmdate('Y-m-01 00:00:00')) + $total > (int) $card['product_monthly_limit']) {
            return 'Monthly card limit exceeded';
        }
        if ($channel === 'atm' && (int) $card['atm_daily_limit'] > 0 && $spent($today, 'atm') + $total > (int) $card['atm_daily_limit']) {
            return 'Daily ATM limit exceeded';
        }
        if (AccountService::available($acc) < $total) {
            return AccountService::isCredit($acc) ? 'Insufficient available credit' : 'Insufficient funds';
        }
        return null;
    }

    /** Merchant refund / reversal of an approved card transaction (including its fee). */
    public static function reverse(int $cardTxId, string $reason): void
    {
        Db::transaction(function () use ($cardTxId, $reason) {
            $ct = Db::one('SELECT * FROM card_transactions WHERE id = ? FOR UPDATE', [$cardTxId]);
            if (!$ct || $ct['status'] !== 'approved') {
                throw new BankingException('Only approved card transactions can be reversed.');
            }
            $card = self::find((int) $ct['card_id']);
            $total = (int) $ct['amount'] + (int) $ct['fee_amount'];
            $legs = [
                ['account_id' => LedgerService::systemAccountId(LedgerService::SYS_CARDS), 'entry' => 'debit', 'amount' => (int) $ct['amount']],
                ['account_id' => (int) $card['account_id'], 'entry' => 'credit', 'amount' => $total],
            ];
            if ((int) $ct['fee_amount'] > 0) {
                $legs[] = ['account_id' => LedgerService::systemAccountId(LedgerService::SYS_FEES), 'entry' => 'debit', 'amount' => (int) $ct['fee_amount']];
            }
            $txId = LedgerService::createTransaction('refund', $total, $ct['currency'], [
                'to_account_id' => $card['account_id'], 'description' => 'Refund: ' . $ct['merchant_name'],
                'parent_id' => $ct['transaction_id'], 'initiated_by' => Auth::id(), 'customer_reference' => 'CARD •' . $card['pan_last4'],
            ]);
            LedgerService::postEntries($txId, $legs);
            Db::update('transactions', ['status' => 'reversed'], 'id = ?', [$ct['transaction_id']]);
            Db::update('card_transactions', ['status' => 'reversed', 'reversal_transaction_id' => $txId], 'id = ?', [$ct['id']]);
            AuditService::log('card.transaction_reversed', 'card', $card['id'], null, ['card_transaction' => $ct['id'], 'amount' => $total], $reason);
            NotificationService::event((int) $card['owner_user_id'], 'card_refund', [
                'card' => '•' . $card['pan_last4'], 'amount' => money($total, $ct['currency']), 'merchant' => $ct['merchant_name'],
            ], '/cards/' . $card['id']);
        });
    }
}
