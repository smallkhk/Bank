<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/** Single-use, expiring tokens (password reset, email verification). Only hashes are stored. */
final class TokenService
{
    public static function issue(int $userId, string $purpose, int $minutes): string
    {
        Db::query('UPDATE user_tokens SET used_at = UTC_TIMESTAMP() WHERE user_id = ? AND purpose = ? AND used_at IS NULL', [$userId, $purpose]);
        $token = bin2hex(random_bytes(32));
        Db::insert('user_tokens', [
            'user_id' => $userId, 'purpose' => $purpose, 'token_hash' => hash('sha256', $token),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + $minutes * 60),
        ]);
        return $token;
    }

    /** Returns the user id if valid, without consuming the token. */
    public static function peek(string $token, string $purpose): ?int
    {
        if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
            return null;
        }
        $id = Db::value(
            'SELECT user_id FROM user_tokens WHERE token_hash = ? AND purpose = ? AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()',
            [hash('sha256', $token), $purpose]
        );
        return $id === null ? null : (int) $id;
    }

    public static function consume(string $token, string $purpose): ?int
    {
        $uid = self::peek($token, $purpose);
        if ($uid !== null) {
            Db::query('UPDATE user_tokens SET used_at = UTC_TIMESTAMP() WHERE token_hash = ?', [hash('sha256', $token)]);
        }
        return $uid;
    }
}
