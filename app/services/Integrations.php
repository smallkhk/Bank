<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/**
 * Registry of external providers. Credentials are stored AES-256-GCM encrypted and are
 * never shown back in full. Everything is disabled until an administrator enables it.
 */
final class Integrations
{
    /**
     * driver: class implementing the provider (null = connection point awaiting a provider choice).
     * fields: key => [label, secret?, required?, help]
     */
    public const PROVIDERS = [
        'stripe' => [
            'type' => 'Payments', 'name' => 'Stripe', 'driver' => StripeGateway::class,
            'description' => 'Customers add funds by card through Stripe Checkout (hosted by Stripe; card data never touches this server).',
            'fields' => [
                'secret_key' => ['Secret key', true, true, 'sk_test_… or sk_live_… — Stripe Dashboard → Developers → API keys'],
                'webhook_secret' => ['Webhook signing secret', true, true, 'whsec_… — from the webhook endpoint you create in Stripe'],
                'min_amount' => ['Minimum top-up', false, false, 'e.g. 10.00'],
                'max_amount' => ['Maximum top-up', false, false, 'e.g. 5000.00'],
                'api_base' => ['API base URL (advanced)', false, false, 'Leave blank for https://api.stripe.com'],
            ],
            'webhook' => true,
        ],
        'smtp' => [
            'type' => 'Email', 'name' => 'SMTP email', 'driver' => SmtpMailer::class,
            'description' => 'Send notification emails through your cPanel mailbox or any email provider (SendGrid, Mailgun, SES, Microsoft 365…).',
            'fields' => [
                'host' => ['SMTP server', false, true, 'e.g. mail.yourbank.com'],
                'port' => ['Port', false, true, '465 (SSL) or 587 (TLS)'],
                'encryption' => ['Encryption', false, true, 'ssl, tls or none'],
                'username' => ['Username', false, false, 'Usually the full email address'],
                'password' => ['Password', true, false, ''],
            ],
        ],
        'twilio' => [
            'type' => 'SMS', 'name' => 'Twilio SMS', 'driver' => TwilioSms::class,
            'description' => 'Text-message alerts for security events (new device, password change, declined card…). Choose which events in Templates.',
            'fields' => [
                'account_sid' => ['Account SID', false, true, 'AC… — Twilio Console'],
                'auth_token' => ['Auth token', true, true, ''],
                'from' => ['Sender number', false, true, 'Your Twilio number in +E.164 format, e.g. +15551234567'],
                'api_base' => ['API base URL (advanced)', false, false, 'Leave blank for https://api.twilio.com'],
            ],
        ],
        'coingecko' => [
            'type' => 'Crypto prices', 'name' => 'CoinGecko price feed', 'driver' => CoinGeckoFeed::class,
            'description' => 'Live market prices for crypto assets. Link each asset to its CoinGecko coin ID (e.g. bitcoin) in Admin → Crypto. Prices update from the crypto-prices cron job. Holdings remain internal records.',
            'fields' => [
                'plan' => ['Plan', false, true, 'demo (free key) or pro'],
                'api_key' => ['API key', true, false, 'From coingecko.com/en/developers/dashboard — recommended, the keyless API is heavily rate-limited'],
                'api_base' => ['API base URL (advanced)', false, false, 'Leave blank for the official CoinGecko API'],
            ],
        ],
        // Connection points: the application exposes the hooks, a provider must be chosen and contracted.
        'card_processor' => ['type' => 'Card processing', 'name' => 'Card processor', 'driver' => null,
            'description' => 'Issuer processor (e.g. Marqeta, Galileo, Lithic) for real card issuing and authorisations. Plugs into CardService::authorize()/reverse(); requires a programme manager/BIN sponsor and PCI DSS compliance.'],
        'kyc' => ['type' => 'KYC / AML', 'name' => 'Identity verification', 'driver' => null,
            'description' => 'Identity verification & sanctions screening (e.g. Onfido, Sumsub, Veriff, ComplyAdvantage). Plugs into customer onboarding (customers.kyc_status).'],
        'open_banking' => ['type' => 'Open banking / bank API', 'name' => 'Bank connectivity', 'driver' => null,
            'description' => 'Real deposits and payouts via a banking partner or open-banking API (e.g. Plaid, TrueLayer, a sponsor bank). Plugs into FundingService and WithdrawalService::approve().'],
        'blockchain' => ['type' => 'Crypto custody', 'name' => 'Crypto custody / exchange', 'driver' => null,
            'description' => 'Licensed custody or brokerage (e.g. Coinbase Prime, Fireblocks, Paxos) for real crypto. Plugs into CryptoService (prices, execution, wallets); requires the relevant licences.'],
    ];

    public static function row(string $provider): ?array
    {
        return Db::one('SELECT * FROM integrations WHERE provider = ?', [$provider]);
    }

    /** Decrypted config for a provider (empty array if none saved). */
    public static function config(string $provider): array
    {
        $row = self::row($provider);
        if (!$row || !$row['config_encrypted']) {
            return [];
        }
        return json_decode(CardVault::decrypt($row['config_encrypted'], 'integration-secrets'), true) ?: [];
    }

    public static function enabled(string $provider): bool
    {
        $row = self::row($provider);
        return $row && $row['enabled'] && isset(self::PROVIDERS[$provider]['driver']);
    }

    /** Merge submitted fields into the stored config. Blank secret fields keep their saved value. */
    public static function save(string $provider, array $input, bool $enabled, string $mode, int $by): void
    {
        $def = self::PROVIDERS[$provider] ?? null;
        if (!$def || !$def['driver']) {
            throw new BankingException('This connection point needs a provider to be chosen and integrated first.');
        }
        $config = self::config($provider);
        foreach ($def['fields'] as $key => [$label, $secret, $required]) {
            $v = trim((string) ($input[$key] ?? ''));
            if ($secret && $v === '') {
                continue; // keep existing secret
            }
            $config[$key] = mb_substr($v, 0, 500);
        }
        if ($enabled) {
            foreach ($def['fields'] as $key => [$label, $secret, $required]) {
                if ($required && ($config[$key] ?? '') === '') {
                    throw new BankingException("$label is required to enable {$def['name']}.");
                }
            }
            ($def['driver'])::validate($config, $mode);
        }
        $old = self::row($provider);
        Db::query(
            'INSERT INTO integrations (provider, enabled, mode, config_encrypted, updated_by) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), mode = VALUES(mode), config_encrypted = VALUES(config_encrypted), updated_by = VALUES(updated_by)',
            [$provider, $enabled ? 1 : 0, $mode, CardVault::encrypt(json_encode($config), 'integration-secrets'), $by]
        );
        // Audit which fields changed, never their values.
        AuditService::log('integration.updated', 'integration', $provider,
            ['enabled' => (bool) ($old['enabled'] ?? false), 'mode' => $old['mode'] ?? null],
            ['enabled' => $enabled, 'mode' => $mode, 'fields_set' => array_keys(array_filter($config, fn ($v) => $v !== ''))]);
    }

    /** @return array{ok:bool, message:string} */
    public static function test(string $provider): array
    {
        $def = self::PROVIDERS[$provider] ?? null;
        if (!$def || !$def['driver']) {
            return ['ok' => false, 'message' => 'Not available.'];
        }
        try {
            $r = ($def['driver'])::test(self::config($provider), self::row($provider)['mode'] ?? 'test');
        } catch (\Throwable $e) {
            $r = ['ok' => false, 'message' => $e instanceof BankingException ? $e->getMessage() : 'Connection failed: ' . mb_substr($e->getMessage(), 0, 200)];
        }
        Db::update('integrations', ['last_tested_at' => now(), 'last_test_ok' => $r['ok'] ? 1 : 0, 'last_test_message' => mb_substr($r['message'], 0, 255)], 'provider = ?', [$provider]);
        AuditService::log('integration.tested', 'integration', $provider, null, $r);
        return $r;
    }

    public static function mask(string $value): string
    {
        return $value === '' ? '' : str_repeat('•', 8) . substr($value, -4);
    }
}
