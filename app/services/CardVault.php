<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Encryption for sensitive card data (PAN, CVV) using AES-256-GCM with a key derived from
 * config app.key. Changing app.key makes existing card data unreadable — back it up.
 * Integration point: swap for an HSM / tokenisation service before handling real cards.
 */
final class CardVault
{
    private static function key(string $purpose): string
    {
        $appKey = (string) config('app.key', '');
        if (strlen($appKey) < 32 || str_contains($appKey, 'CHANGE-ME')) {
            throw new \RuntimeException('Set a random app.key of at least 32 characters in config/config.php before issuing cards or saving integration keys.');
        }
        return hash_hmac('sha256', $purpose, $appKey, true);
    }

    public static function encrypt(string $plain, string $purpose = 'card-data'): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::key($purpose), OPENSSL_RAW_DATA, $iv, $tag);
        return 'v1:' . base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $stored, string $purpose = 'card-data'): string
    {
        $raw = base64_decode(substr($stored, 3), true);
        if (!str_starts_with($stored, 'v1:') || $raw === false || strlen($raw) < 29) {
            throw new \RuntimeException('Invalid card data.');
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key($purpose), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if ($plain === false) {
            throw new \RuntimeException('Encrypted data could not be decrypted (was app.key changed?).');
        }
        return $plain;
    }

    /** Deterministic keyed hash for uniqueness checks without storing the PAN in clear. */
    public static function panHash(string $pan): string
    {
        return hash_hmac('sha256', $pan, self::key('pan-index'));
    }
}
