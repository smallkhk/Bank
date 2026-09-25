<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

final class SettingsService
{
    private static ?array $cache = null;

    /** Defaults used when a key has never been saved. */
    public const DEFAULTS = [
        // General / branding
        'bank_name'            => '{{BANK_NAME}}',
        'bank_short_name'      => '{{BANK}}',
        'logo_path'            => '',
        'favicon_path'         => '',
        'color_primary'        => '#0b3d63',
        'color_secondary'      => '#16629b',
        'color_accent'         => '#1a9e75',
        'currency'             => 'USD',
        'currency_symbol'      => '$',
        'contact_email'        => '',
        'support_phone'        => '',
        'address'              => '',
        'website_url'          => '',
        'terms_text'           => 'Terms and conditions have not been published yet.',
        'privacy_text'         => 'The privacy policy has not been published yet.',
        'login_message'        => 'Secure online banking. Never share your password or one-time codes with anyone.',
        'footer_text'          => '',
        'maintenance_mode'     => '0',
        'maintenance_message'  => 'Online banking is temporarily unavailable for scheduled maintenance.',
        'sandbox_notice'       => '1',
        // Registration
        'registration_enabled' => '1',
        'registration_auto_activate' => '0',
        'default_account_type' => 'checking',
        // Account numbers
        'account_number_prefix' => '',
        'account_number_branch' => '',
        'account_number_length' => '10',
        // Transactions (amounts in minor units)
        'transfer_fee_fixed'     => '0',
        'transfer_fee_bps'       => '0',      // basis points: 50 = 0.50%
        'transfer_approval_threshold' => '0',   // 0 = never require approval
        'withdrawal_fee_fixed'   => '0',
        'default_daily_transfer_limit'   => '1000000',
        'default_daily_withdrawal_limit' => '500000',
        'default_monthly_limit'          => '10000000',
        'add_funds_requires_approval'    => '1',
        'customer_add_funds_requests'    => '1',
        // Security
        'password_min_length'   => '10',
        'login_max_attempts'    => '5',
        'login_lockout_minutes' => '15',
        'session_idle_minutes'  => '30',
        'confirm_password_for_transfers' => '1',
        'allow_self_approval'   => '0',
        'require_2fa_staff'     => '0',
        'require_email_verification' => '0',
        // Fees (minor units)
        'monthly_account_fee'   => '0',      // default when the account type has no monthly fee
        'monthly_fee_min_balance_waiver' => '0', // waive when balance >= this (0 = no waiver)
        // Support
        'support_categories'    => "Account\nTransfers\nWithdrawals\nCards\nTechnical\nComplaint\nOther",
        'support_sla_hours'     => '24',
        'chat_enabled'          => '1',
        'support_enabled'       => '1',
        // Feature switches
        'transfers_enabled'     => '1',
        'withdrawals_enabled'   => '1',
        'risk_max_transfers_10min' => '10',
        // Email (empty values fall back to config/config.php)
        'mail_enabled'          => '',
        'mail_driver'           => '',
        'mail_from_email'       => '',
        'mail_from_name'        => '',
        // Cards
        'cards_enabled'         => '1',
        'credit_cards_enabled'  => '1',
        'max_cards_per_customer'=> '5',
        'bank_country'          => 'US',
        // Crypto (simulated)
        'crypto_enabled'        => '0',
        'crypto_max_trade'      => '100000000', // 1,000,000.00 per trade
        'crypto_max_price_age_minutes' => '30',  // pause trading on feed-linked assets with older prices
        'crypto_risk_text'      => "Crypto assets on this platform are SIMULATED. They are internal records, are not real cryptocurrency, cannot be sent to or received from a blockchain wallet, and exist only inside this platform. Prices are set by the bank and can move sharply. You may lose money you use to buy simulated assets.",      // maker-checker: approver must differ from requester
    ];

    public static function get(string $key, ?string $default = null): ?string
    {
        if (self::$cache === null) {
            self::load();
        }
        return self::$cache[$key] ?? $default ?? (self::DEFAULTS[$key] ?? null);
    }

    public static function set(string $key, ?string $value): void
    {
        Db::query('INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)', [$key, $value]);
        if (self::$cache !== null) {
            self::$cache[$key] = $value;
        }
    }

    public static function all(): array
    {
        if (self::$cache === null) {
            self::load();
        }
        return self::$cache + self::DEFAULTS;
    }

    private static function load(): void
    {
        try {
            self::$cache = array_column(Db::all('SELECT `key`, `value` FROM settings'), 'value', 'key');
        } catch (\Throwable) {
            self::$cache = [];
        }
    }
}
