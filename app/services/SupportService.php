<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Db;

final class SupportService
{
    public const STATUSES = ['open', 'pending', 'assigned', 'escalated', 'resolved', 'closed'];
    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    public static function categories(): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", (string) setting('support_categories')))));
    }

    public static function open(int $customerId, string $category, string $subject, string $body, ?int $attachmentId, int $createdBy): int
    {
        if (!in_array($category, self::categories(), true)) {
            throw new BankingException('Please choose a category.');
        }
        if (mb_strlen($subject) < 3 || mb_strlen(trim($body)) < 5) {
            throw new BankingException('Please enter a subject and describe your request.');
        }
        return Db::transaction(function () use ($customerId, $category, $subject, $body, $attachmentId, $createdBy) {
            $isStaff = Auth::isStaff();
            $id = Db::insert('support_tickets', [
                'reference'   => 'T' . strtoupper(bin2hex(random_bytes(4))),
                'customer_id' => $customerId,
                'category'    => $category,
                'subject'     => mb_substr($subject, 0, 190),
                'created_by'  => $createdBy,
                'last_reply_at' => now(),
                'last_reply_by' => $isStaff ? 'staff' : 'customer',
                'status'      => $isStaff ? 'pending' : 'open',
                'due_at'      => gmdate('Y-m-d H:i:s', time() + 3600 * max(1, (int) setting('support_sla_hours', '24'))),
            ]);
            Db::insert('support_messages', ['ticket_id' => $id, 'user_id' => $createdBy, 'body' => mb_substr($body, 0, 10000), 'attachment_id' => $attachmentId]);
            AuditService::log('support.ticket_opened', 'ticket', $id, null, ['category' => $category, 'subject' => $subject]);
            return $id;
        });
    }

    public static function reply(array $ticket, string $body, ?int $attachmentId, bool $internal = false): void
    {
        if (trim($body) === '' && !$attachmentId) {
            throw new BankingException('Please write a message.');
        }
        $isStaff = Auth::isStaff();
        if ($internal && !$isStaff) {
            throw new BankingException('Not allowed.');
        }
        Db::insert('support_messages', [
            'ticket_id' => $ticket['id'], 'user_id' => Auth::id(), 'body' => mb_substr($body ?: '(attachment)', 0, 10000),
            'is_internal' => $internal ? 1 : 0, 'attachment_id' => $attachmentId,
        ]);
        if ($internal) {
            AuditService::log('support.internal_note', 'ticket', $ticket['id']);
            return;
        }
        $update = ['last_reply_at' => now(), 'last_reply_by' => $isStaff ? 'staff' : 'customer'];
        if ($isStaff) {
            if (in_array($ticket['status'], ['open', 'assigned', 'escalated'], true)) {
                $update['status'] = 'pending'; // waiting on customer
            }
            $uid = Db::value('SELECT user_id FROM customers WHERE id = ?', [$ticket['customer_id']]);
            NotificationService::event((int) $uid, 'support_reply', ['reference' => $ticket['reference'], 'subject' => $ticket['subject']], '/support/' . $ticket['id']);
        } else {
            $update['status'] = $ticket['assigned_to'] ? 'assigned' : 'open';
            $update['due_at'] = gmdate('Y-m-d H:i:s', time() + 3600 * max(1, (int) setting('support_sla_hours', '24')));
            if ($ticket['assigned_to']) {
                NotificationService::notify((int) $ticket['assigned_to'], 'Customer replied: ' . $ticket['reference'], $ticket['subject'], '/admin/support/' . $ticket['id']);
            }
        }
        Db::update('support_tickets', $update, 'id = ?', [$ticket['id']]);
    }

    public static function setStatus(array $ticket, string $status, ?string $reason = null): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new BankingException('Invalid status.');
        }
        $data = ['status' => $status];
        if ($status === 'escalated' && in_array($ticket['priority'], ['low', 'normal'], true)) {
            $data['priority'] = 'high';
        }
        Db::update('support_tickets', $data, 'id = ?', [$ticket['id']]);
        AuditService::log('support.status_changed', 'ticket', $ticket['id'], $ticket['status'], $status, $reason);
    }

    public static function assign(array $ticket, ?int $staffId): void
    {
        if ($staffId && !Db::value("SELECT 1 FROM users WHERE id = ? AND user_type = 'staff' AND status = 'active'", [$staffId])) {
            throw new BankingException('Select an active staff member.');
        }
        $status = $staffId && in_array($ticket['status'], ['open', 'pending'], true) ? 'assigned' : $ticket['status'];
        Db::update('support_tickets', ['assigned_to' => $staffId, 'status' => $status], 'id = ?', [$ticket['id']]);
        AuditService::log('support.assigned', 'ticket', $ticket['id'], $ticket['assigned_to'], $staffId);
        if ($staffId && $staffId !== Auth::id()) {
            NotificationService::notify($staffId, 'Ticket assigned: ' . $ticket['reference'], $ticket['subject'], '/admin/support/' . $ticket['id']);
        }
    }

    public static function messages(int $ticketId, bool $includeInternal): array
    {
        return Db::all(
            'SELECT m.*, u.full_name, u.user_type, a.original_name, a.id AS att_id, a.size AS att_size
               FROM support_messages m JOIN users u ON u.id = m.user_id LEFT JOIN attachments a ON a.id = m.attachment_id
              WHERE m.ticket_id = ?' . ($includeInternal ? '' : ' AND m.is_internal = 0') . ' ORDER BY m.id',
            [$ticketId]
        );
    }
}
