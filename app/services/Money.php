<?php
declare(strict_types=1);

namespace App\Services;

/** Money is always handled as integer minor units (cents) — never floats. */
final class Money
{
    /** Parse user input like "1,234.56" into minor units. Returns null if invalid. */
    public static function parse(string $input): ?int
    {
        $s = str_replace([',', ' '], '', trim($input));
        if (!preg_match('/^\d{1,13}(\.\d{1,2})?$/', $s)) {
            return null;
        }
        [$whole, $frac] = array_pad(explode('.', $s), 2, '');
        return (int) $whole * 100 + (int) str_pad($frac, 2, '0');
    }

    public static function format(int $minor, ?string $currency = null): string
    {
        $symbol = setting('currency_symbol', '$');
        $code = $currency ?? setting('currency', 'USD');
        $sign = $minor < 0 ? '-' : '';
        $abs = abs($minor);
        $formatted = number_format(intdiv($abs, 100)) . '.' . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
        if ($code !== setting('currency', 'USD')) {
            return $sign . $formatted . ' ' . $code;
        }
        return $sign . $symbol . $formatted;
    }

    public static function toDecimal(int $minor): string
    {
        $sign = $minor < 0 ? '-' : '';
        $abs = abs($minor);
        return $sign . intdiv($abs, 100) . '.' . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }
}
