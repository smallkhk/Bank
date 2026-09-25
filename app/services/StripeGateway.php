<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Stripe Checkout (hosted payment page). This server never sees card numbers, which keeps
 * PCI scope minimal (SAQ A). Funds are credited only after Stripe confirms payment, either by
 * a signed webhook or by fetching the session from Stripe's API — never from the browser redirect alone.
 */
final class StripeGateway
{
    private static function base(array $c): string
    {
        return rtrim(($c['api_base'] ?? '') ?: 'https://api.stripe.com', '/');
    }

    public static function validate(array $c, string $mode): void
    {
        $key = (string) ($c['secret_key'] ?? '');
        if (!preg_match('/^(sk|rk)_' . ($mode === 'live' ? 'live' : 'test') . '_/', $key)) {
            throw new BankingException($mode === 'live'
                ? 'Live mode needs a live secret key (sk_live_…).'
                : 'Test mode needs a test secret key (sk_test_…).');
        }
        if (!str_starts_with((string) ($c['webhook_secret'] ?? ''), 'whsec_')) {
            throw new BankingException('The webhook signing secret should start with whsec_.');
        }
        if ($mode === 'live' && ($c['api_base'] ?? '') !== '' && !str_starts_with($c['api_base'], 'https://')) {
            throw new BankingException('Live mode requires an https:// API URL.');
        }
        foreach (['min_amount', 'max_amount'] as $k) {
            if (($c[$k] ?? '') !== '' && Money::parse($c[$k]) === null) {
                throw new BankingException('Enter top-up limits as amounts, e.g. 10.00');
            }
        }
    }

    private static function call(array $c, string $action, string $method, string $path, ?array $body = null, ?string $idempotencyKey = null): array
    {
        $headers = ['Authorization' => 'Bearer ' . $c['secret_key'], 'Stripe-Version' => '2024-06-20'];
        if ($idempotencyKey) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }
        $r = HttpClient::request('stripe', $action, $method, self::base($c) . $path, $headers, $body);
        if ($r['error'] || $r['status'] < 200 || $r['status'] >= 300 || !$r['json']) {
            throw new BankingException('The card payment service is unavailable right now. Please try again later.');
        }
        return $r['json'];
    }

    public static function test(array $c, string $mode): array
    {
        $bal = self::call($c, 'test.balance', 'GET', '/v1/balance');
        $live = (bool) ($bal['livemode'] ?? false);
        if ($live !== ($mode === 'live')) {
            return ['ok' => false, 'message' => 'Key works, but it is a ' . ($live ? 'live' : 'test') . ' key while the integration is in ' . $mode . ' mode.'];
        }
        return ['ok' => true, 'message' => 'Connected to Stripe (' . ($live ? 'live' : 'test') . ' mode).'];
    }

    /** @return array{id:string, url:string} */
    public static function createCheckout(array $c, array $payment, string $email, string $successUrl, string $cancelUrl): array
    {
        $s = self::call($c, 'checkout.create', 'POST', '/v1/checkout/sessions', [
            'mode' => 'payment',
            'line_items[0][quantity]' => 1,
            'line_items[0][price_data][currency]' => strtolower($payment['currency']),
            'line_items[0][price_data][unit_amount]' => (int) $payment['amount'],
            'line_items[0][price_data][product_data][name]' => 'Account top-up — ' . bank_name(),
            'client_reference_id' => $payment['reference'],
            'customer_email' => $email,
            'metadata[reference]' => $payment['reference'],
            'payment_intent_data[metadata][reference]' => $payment['reference'],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
        ], 'checkout-' . $payment['reference']);
        if (empty($s['id']) || empty($s['url'])) {
            throw new BankingException('The card payment service returned an unexpected response.');
        }
        return ['id' => $s['id'], 'url' => $s['url']];
    }

    public static function retrieveSession(array $c, string $id): array
    {
        if (!preg_match('/^cs_[A-Za-z0-9_]+$/', $id)) {
            throw new BankingException('Invalid payment session.');
        }
        return self::call($c, 'checkout.retrieve', 'GET', '/v1/checkout/sessions/' . $id);
    }

    /** Verify the Stripe-Signature header (HMAC-SHA256 of "timestamp.payload"), with replay tolerance. */
    public static function verifySignature(string $payload, string $header, string $secret, int $tolerance = 300): bool
    {
        $t = null;
        $sigs = [];
        foreach (explode(',', $header) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($k === 't') {
                $t = (int) $v;
            } elseif ($k === 'v1') {
                $sigs[] = $v;
            }
        }
        if (!$t || !$sigs || abs(time() - $t) > $tolerance) {
            return false;
        }
        $expected = hash_hmac('sha256', $t . '.' . $payload, $secret);
        foreach ($sigs as $sig) {
            if (hash_equals($expected, $sig)) {
                return true;
            }
        }
        return false;
    }
}
