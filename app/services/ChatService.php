<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Db;

/** Customer ⇄ support chat. Delivered by AJAX polling (no WebSocket server needed on cPanel). */
final class ChatService
{
    public static function openConversation(int $customerId): array
    {
        $conv = Db::one("SELECT * FROM chat_conversations WHERE customer_id = ? AND status = 'open' ORDER BY id DESC LIMIT 1", [$customerId]);
        if ($conv) {
            return $conv;
        }
        $id = Db::insert('chat_conversations', ['customer_id' => $customerId]);
        return Db::one('SELECT * FROM chat_conversations WHERE id = ?', [$id]);
    }

    public static function post(array $conv, string $body, ?int $attachmentId, bool $internal = false): int
    {
        $body = trim($body);
        if ($body === '' && !$attachmentId) {
            throw new BankingException('Message is empty.');
        }
        if (mb_strlen($body) > 4000) {
            throw new BankingException('Message is too long.');
        }
        $isStaff = Auth::isStaff();
        $id = Db::insert('chat_messages', [
            'conversation_id' => $conv['id'], 'user_id' => Auth::id(), 'sender' => $isStaff ? 'staff' : 'customer',
            'body' => $body ?: '(attachment)', 'is_internal' => $internal && $isStaff ? 1 : 0, 'attachment_id' => $attachmentId,
        ]);
        if (!$internal) {
            $update = ['last_message_at' => now(), 'status' => 'open'];
            $update[$isStaff ? 'staff_last_read_id' : 'customer_last_read_id'] = $id;
            if ($isStaff && !$conv['assigned_to']) {
                $update['assigned_to'] = Auth::id();
            }
            Db::update('chat_conversations', $update, 'id = ?', [$conv['id']]);
        }
        return $id;
    }

    /** Messages after $afterId for polling. Customers never receive internal notes. */
    public static function messages(int $convId, int $afterId, bool $includeInternal): array
    {
        $rows = Db::all(
            'SELECT m.id, m.sender, m.body, m.is_internal, m.created_at, u.full_name, a.id AS att_id, a.original_name
               FROM chat_messages m JOIN users u ON u.id = m.user_id LEFT JOIN attachments a ON a.id = m.attachment_id
              WHERE m.conversation_id = ? AND m.id > ?' . ($includeInternal ? '' : ' AND m.is_internal = 0') . '
              ORDER BY m.id LIMIT 200',
            [$convId, $afterId]
        );
        return array_map(static fn ($r) => [
            'id' => (int) $r['id'], 'sender' => $r['sender'], 'internal' => (bool) $r['is_internal'],
            'body' => $r['body'], 'name' => $r['sender'] === 'staff' && !$includeInternal ? explode(' ', $r['full_name'])[0] . ' (Support)' : $r['full_name'],
            'time' => fmt_date($r['created_at'], 'M j, g:i A'),
            'attachment' => $r['att_id'] ? ['id' => (int) $r['att_id'], 'name' => $r['original_name']] : null,
        ], $rows);
    }

    public static function markRead(array $conv, int $lastId, bool $staff): void
    {
        $col = $staff ? 'staff_last_read_id' : 'customer_last_read_id';
        Db::query("UPDATE chat_conversations SET $col = GREATEST($col, ?) WHERE id = ?", [$lastId, $conv['id']]);
    }

    public static function unreadForCustomer(int $customerId): int
    {
        return (int) Db::value(
            "SELECT COUNT(*) FROM chat_messages m JOIN chat_conversations c ON c.id = m.conversation_id
              WHERE c.customer_id = ? AND m.sender = 'staff' AND m.is_internal = 0 AND m.id > c.customer_last_read_id",
            [$customerId]
        );
    }
}
