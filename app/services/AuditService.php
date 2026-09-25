<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Db;

final class AuditService
{
    public static function log(
        string $action,
        ?string $targetType = null,
        int|string|null $targetId = null,
        mixed $old = null,
        mixed $new = null,
        ?string $reason = null,
        ?int $userId = null,
    ): void {
        Db::insert('audit_logs', [
            'user_id'     => $userId ?? Auth::id(),
            'action'      => $action,
            'target_type' => $targetType,
            'target_id'   => $targetId === null ? null : (string) $targetId,
            'old_value'   => self::encode($old),
            'new_value'   => self::encode($new),
            'reason'      => $reason === null ? null : mb_substr($reason, 0, 255),
            'ip_address'  => client_ip(),
            'user_agent'  => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? (PHP_SAPI === 'cli' ? 'cli' : '')), 0, 255),
        ]);
    }

    private static function encode(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        return is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
