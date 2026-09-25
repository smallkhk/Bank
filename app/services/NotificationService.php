<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/**
 * In-app notifications. Email/SMS delivery is an integration point (disabled by default):
 * implement send via a provider inside deliverExternal() when configured.
 */
final class NotificationService
{
    public static function notify(int $userId, string $title, ?string $body = null, ?string $link = null): void
    {
        Db::insert('notifications', ['user_id' => $userId, 'title' => $title, 'body' => $body, 'link' => $link]);
        self::deliverExternal($userId, $title, $body);
    }

    public static function notifyAccountOwner(int $accountId, string $title, ?string $body = null, ?string $link = null): void
    {
        $userId = Db::value('SELECT c.user_id FROM accounts a JOIN customers c ON c.id = a.customer_id WHERE a.id = ?', [$accountId]);
        if ($userId) {
            self::notify((int) $userId, $title, $body, $link);
        }
    }

    public static function unreadCount(int $userId): int
    {
        return (int) Db::value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$userId]);
    }

    private static function deliverExternal(int $userId, string $title, ?string $body): void
    {
        if (!config('mail.enabled')) {
            return;
        }
        // Integration point: plug in an email/SMS provider here.
    }
}
