<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Db;

/**
 * "Needs your attention" counters for the sidebar badges. Counts respect each person's
 * permissions and, for staff, the customers assigned to them. Polled by the browser so badges
 * update without a page reload.
 */
final class AttentionService
{
    /** @return array<string,int> badge key => count */
    public static function counts(): array
    {
        if (!Auth::check()) {
            return [];
        }
        return Auth::isStaff() ? self::staff() : self::customer();
    }

    private static function staff(): array
    {
        $c = [];
        [$cs, $cp] = StaffScope::customerFilter('a.customer_id');
        if (can('funds.approve') || can('funds.withdraw')) {
            $c['withdrawals'] = (int) Db::value("SELECT COUNT(*) FROM withdrawal_requests w JOIN accounts a ON a.id = w.account_id WHERE w.status = 'pending' AND $cs", $cp);
        }
        if (can('funds.approve') || can('funds.add') || can('funds.adjust')) {
            $c['funds'] = (int) Db::value("SELECT COUNT(*) FROM deposit_requests d JOIN accounts a ON a.id = d.account_id WHERE d.status = 'pending' AND $cs", $cp);
        }
        if (can('transactions.approve') || can('transactions.view')) {
            $c['transactions'] = (int) Db::value("SELECT COUNT(*) FROM transactions t JOIN accounts a ON a.id = t.from_account_id WHERE t.type = 'transfer' AND t.status = 'pending' AND $cs", $cp);
        }
        if (can('cards.issue') || can('cards.view')) {
            [$s, $p] = StaffScope::customerFilter('c.customer_id');
            $c['cards'] = (int) Db::value("SELECT COUNT(*) FROM cards c WHERE c.status = 'pending' AND $s", $p);
        }
        if (can('customers.lock') || can('customers.view')) {
            [$s, $p] = StaffScope::customerFilter('c.id');
            $c['customers'] = (int) Db::value("SELECT COUNT(*) FROM customers c JOIN users u ON u.id = c.user_id WHERE u.status = 'pending' AND $s", $p);
        }
        if (can('support.view')) {
            [$s, $p] = StaffScope::customerFilter('t.customer_id');
            $c['support'] = (int) Db::value("SELECT COUNT(*) FROM support_tickets t WHERE t.status IN ('open','escalated') AND t.last_reply_by = 'customer' AND $s", $p);
            [$s, $p] = StaffScope::customerFilter('cc.customer_id');
            $c['chats'] = (int) Db::value("SELECT COUNT(DISTINCT cc.id) FROM chat_conversations cc JOIN chat_messages m ON m.conversation_id = cc.id
                WHERE $s AND cc.status = 'open' AND m.sender = 'customer' AND m.id > cc.staff_last_read_id", $p);
        }
        $c['notifications'] = NotificationService::unreadCount((int) Auth::id());
        return $c;
    }

    private static function customer(): array
    {
        $cid = Auth::customerId();
        return [
            'notifications' => NotificationService::unreadCount((int) Auth::id()),
            'support' => $cid ? ChatService::unreadForCustomer($cid)
                + (int) Db::value("SELECT COUNT(*) FROM support_tickets WHERE customer_id = ? AND last_reply_by = 'staff' AND status IN ('pending','resolved') AND updated_at > UTC_TIMESTAMP() - INTERVAL 14 DAY", [$cid]) : 0,
        ];
    }
}
