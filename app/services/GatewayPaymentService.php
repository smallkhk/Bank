<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/**
 * Card top-ups through an external gateway (Stripe Checkout).
 * Flow: create pending payment → redirect customer to the hosted page → Stripe confirms
 * (signed webhook, or API lookup on return) → credit the account via the ledger, exactly once.
 */
final class GatewayPaymentService
{
    public static function available(): bool
    {
        return Integrations::enabled('stripe');
    }

    public static function limits(): array
    {
        $c = Integrations::config('stripe');
        return [
            'min' => Money::parse((string) ($c['min_amount'] ?? '')) ?: 100,
            'max' => Money::parse((string) ($c['max_amount'] ?? '')) ?: 500000,
        ];
    }

    /** @return string URL of the hosted payment page */
    public static function startTopUp(int $customerId, int $accountId, int $amount, string $email, int $by): string
    {
        if (!self::available()) {
            throw new BankingException('Card payments are not available at the moment.');
        }
        $acc = AccountService::find($accountId);
        if (!$acc || (int) $acc['customer_id'] !== $customerId || AccountService::isCredit($acc) || $acc['status'] !== 'active') {
            throw new BankingException('Choose one of your active accounts.');
        }
        AccountService::assertCanCredit($acc);
        $lim = self::limits();
        if ($amount < $lim['min'] || $amount > $lim['max']) {
            throw new BankingException('Card top-ups must be between ' . money($lim['min']) . ' and ' . money($lim['max']) . '.');
        }
        $recent = (int) Db::value("SELECT COUNT(*) FROM gateway_payments WHERE customer_id = ? AND created_at > UTC_TIMESTAMP() - INTERVAL 1 HOUR", [$customerId]);
        if ($recent >= 10) {
            throw new BankingException('Too many payment attempts. Please try again later.');
        }
        $ref = LedgerService::reference('GP');
        $id = Db::insert('gateway_payments', [
            'reference' => $ref, 'provider' => 'stripe', 'customer_id' => $customerId, 'account_id' => $accountId,
            'amount' => $amount, 'currency' => $acc['currency'], 'created_by' => $by,
        ]);
        $payment = Db::one('SELECT * FROM gateway_payments WHERE id = ?', [$id]);
        $base = rtrim((string) config('app.url'), '/');
        try {
            $s = StripeGateway::createCheckout(Integrations::config('stripe'), $payment, $email,
                $base . url('add-funds/card/return') . '?ref=' . $ref,
                $base . url('add-funds/card/return') . '?ref=' . $ref . '&cancelled=1');
        } catch (BankingException $e) {
            Db::update('gateway_payments', ['status' => 'failed', 'failure_reason' => 'Could not create checkout'], 'id = ?', [$id]);
            throw $e;
        }
        Db::update('gateway_payments', ['provider_ref' => $s['id'], 'status' => 'pending'], 'id = ?', [$id]);
        AuditService::log('gateway.topup_started', 'gateway_payment', $ref, null, ['amount' => $amount, 'account' => $acc['account_number']]);
        return $s['url'];
    }

    /**
     * Apply a Stripe Checkout Session to our payment record. Idempotent and amount-checked:
     * the ledger is credited only once and only with the amount we asked Stripe to collect.
     */
    public static function applySession(array $session): string
    {
        return Db::transaction(function () use ($session) {
            $p = Db::one('SELECT * FROM gateway_payments WHERE provider = ? AND provider_ref = ? FOR UPDATE', ['stripe', (string) ($session['id'] ?? '')]);
            if (!$p) {
                return 'unknown session';
            }
            if (in_array($p['status'], ['completed', 'failed', 'expired'], true)) {
                return 'already ' . $p['status'];
            }
            $status = $session['status'] ?? '';
            if ($status === 'expired') {
                Db::update('gateway_payments', ['status' => 'expired'], 'id = ?', [$p['id']]);
                return 'expired';
            }
            if (($session['payment_status'] ?? '') !== 'paid') {
                return 'not paid yet';
            }
            if ((int) ($session['amount_total'] ?? -1) !== (int) $p['amount'] || strtoupper((string) ($session['currency'] ?? '')) !== $p['currency']
                || ($session['client_reference_id'] ?? null) !== $p['reference']) {
                Db::update('gateway_payments', ['status' => 'failed', 'failure_reason' => 'Amount/currency/reference mismatch — held for review'], 'id = ?', [$p['id']]);
                AuditService::log('gateway.mismatch', 'gateway_payment', $p['reference'], ['amount' => (int) $p['amount']], ['amount_total' => $session['amount_total'] ?? null], null, null);
                return 'mismatch';
            }
            $txId = LedgerService::createTransaction('deposit', (int) $p['amount'], $p['currency'], [
                'to_account_id' => $p['account_id'], 'description' => 'Card top-up', 'customer_reference' => $p['reference'],
                'initiated_by' => $p['created_by'],
            ]);
            LedgerService::postEntries($txId, [
                ['account_id' => LedgerService::systemAccountId(LedgerService::SYS_GATEWAY), 'entry' => 'debit', 'amount' => (int) $p['amount']],
                ['account_id' => (int) $p['account_id'], 'entry' => 'credit', 'amount' => (int) $p['amount']],
            ]);
            Db::update('gateway_payments', ['status' => 'completed', 'transaction_id' => $txId, 'completed_at' => now()], 'id = ?', [$p['id']]);
            AuditService::log('gateway.topup_completed', 'gateway_payment', $p['reference'], null, ['amount' => (int) $p['amount'], 'transaction_id' => $txId], null, null);
            $acc = AccountService::find((int) $p['account_id']);
            NotificationService::eventForAccountOwner((int) $p['account_id'], 'deposit_posted',
                ['amount' => money((int) $p['amount'], $p['currency']), 'account' => mask_account($acc['account_number'])], '/transactions');
            return 'completed';
        });
    }

    /** Customer returned from checkout: confirm with Stripe's API (never trust the redirect itself). */
    public static function syncFromProvider(array $payment): array
    {
        if ($payment['status'] === 'pending' && $payment['provider_ref'] && self::available()) {
            try {
                self::applySession(StripeGateway::retrieveSession(Integrations::config('stripe'), $payment['provider_ref']));
            } catch (BankingException) {
                // Webhook will complete it later.
            }
        }
        return Db::one('SELECT * FROM gateway_payments WHERE id = ?', [$payment['id']]);
    }

    /** Signed webhook from Stripe. Returns [httpStatus, message]. */
    public static function handleStripeWebhook(string $payload, string $signature): array
    {
        $c = Integrations::config('stripe');
        if (!Integrations::enabled('stripe') || empty($c['webhook_secret'])) {
            return [503, 'stripe integration disabled'];
        }
        if (!StripeGateway::verifySignature($payload, $signature, $c['webhook_secret'])) {
            HttpClient::log('stripe', 'in', 'webhook', 400, false, null, 'Invalid signature');
            return [400, 'invalid signature'];
        }
        $event = json_decode($payload, true);
        if (!is_array($event) || empty($event['id']) || empty($event['type'])) {
            return [400, 'invalid payload'];
        }
        if (Db::value('SELECT 1 FROM webhook_events WHERE provider = ? AND event_id = ?', ['stripe', $event['id']])) {
            return [200, 'duplicate'];
        }
        $obj = $event['data']['object'] ?? [];
        $result = match ($event['type']) {
            'checkout.session.completed', 'checkout.session.async_payment_succeeded', 'checkout.session.expired' => self::applySession($obj),
            'checkout.session.async_payment_failed' => self::fail((string) ($obj['id'] ?? ''), 'Payment failed'),
            default => 'ignored',
        };
        try {
            Db::insert('webhook_events', ['provider' => 'stripe', 'event_id' => mb_substr($event['id'], 0, 120), 'event_type' => mb_substr($event['type'], 0, 80),
                'status' => $result === 'ignored' ? 'ignored' : 'processed', 'message' => mb_substr($result, 0, 255)]);
        } catch (\PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
        }
        HttpClient::log('stripe', 'in', 'webhook ' . $event['type'], 200, true, null, $result);
        return [200, $result];
    }

    private static function fail(string $sessionId, string $reason): string
    {
        Db::update('gateway_payments', ['status' => 'failed', 'failure_reason' => $reason], "provider = 'stripe' AND provider_ref = ? AND status IN ('created','pending')", [$sessionId]);
        return 'failed';
    }
}
