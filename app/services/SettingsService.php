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
        'allow_self_approval'   => '0',      // maker-checker: approver must differ from requester
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
