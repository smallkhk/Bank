<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Db;
use App\Services\AttachmentService;
use App\Services\AuditService;
use App\Services\BankingException;
use App\Services\ChatService;
use App\Services\StaffScope;
use App\Services\SupportService;

final class SupportController extends Controller
{
    private function staffList(): array
    {
        return Db::all("SELECT id, full_name FROM users WHERE user_type = 'staff' AND status = 'active' ORDER BY full_name");
    }

    private function ticket(int $id): array
    {
        $t = Db::one('SELECT t.*, u.full_name AS customer_name, u.email AS customer_email, c.customer_number
                        FROM support_tickets t JOIN customers c ON c.id = t.customer_id JOIN users u ON u.id = c.user_id WHERE t.id = ?', [$id]);
        if (!$t) {
            $this->notFound();
        }
        if (!StaffScope::canAccessCustomer((int) $t['customer_id']) && (int) $t['assigned_to'] !== Auth::id()) {
            $this->forbidden();
        }
        return $t;
    }

    public function index(): void
    {
        [$scope, $params] = StaffScope::customerFilter('t.customer_id');
        $where = "($scope OR t.assigned_to = ?)";
        $params[] = Auth::id();
        $view = input('view', 'active');
        if ($view === 'mine') {
            $where .= ' AND t.assigned_to = ? AND t.status NOT IN (\'resolved\',\'closed\')';
            $params[] = Auth::id();
        } elseif ($view === 'unassigned') {
            $where .= " AND t.assigned_to IS NULL AND t.status NOT IN ('resolved','closed')";
        } elseif ($view === 'overdue') {
            $where .= " AND t.due_at < UTC_TIMESTAMP() AND t.last_reply_by = 'customer' AND t.status NOT IN ('resolved','closed','pending')";
        } elseif (in_array($view, SupportService::STATUSES, true)) {
            $where .= ' AND t.status = ?';
            $params[] = $view;
        } else {
            $view = 'active';
            $where .= " AND t.status NOT IN ('resolved','closed')";
        }
        if (($q = input('q')) !== '') {
            $where .= ' AND (t.reference LIKE ? OR t.subject LIKE ? OR u.full_name LIKE ?)';
            array_push($params, "%$q%", "%$q%", "%$q%");
        }
        if (in_array($cat = input('category'), SupportService::categories(), true)) {
            $where .= ' AND t.category = ?';
            $params[] = $cat;
        }
        $from = 'FROM support_tickets t JOIN customers c ON c.id = t.customer_id JOIN users u ON u.id = c.user_id LEFT JOIN users s ON s.id = t.assigned_to';
        $total = (int) Db::value("SELECT COUNT(*) $from WHERE $where", $params);
        $p = paginate($total, 30);
        $this->view('admin/support', [
            'title' => 'Support tickets', 'view' => $view, 'pager' => $p,
            'rows' => Db::all("SELECT t.*, u.full_name AS customer_name, s.full_name AS assignee $from WHERE $where
                               ORDER BY FIELD(t.priority,'urgent','high','normal','low'), t.last_reply_at DESC LIMIT {$p['limit']} OFFSET {$p['offset']}", $params),
            'categories' => SupportService::categories(),
        ]);
    }

    public function show(string $id): void
    {
        $t = $this->ticket((int) $id);
        $this->view('admin/ticket', [
            'title' => $t['reference'] . ' · ' . $t['subject'], 't' => $t,
            'messages' => SupportService::messages((int) $t['id'], true),
            'staff' => $this->staffList(),
            'accounts' => Db::all('SELECT id, account_number, status, balance, currency FROM accounts WHERE customer_id = ?', [$t['customer_id']]),
        ]);
    }

    public function store(string $customerId): void
    {
        $cid = (int) $customerId;
        if (!StaffScope::canAccessCustomer($cid)) {
            $this->forbidden();
        }
        $back = '/admin/customers/' . $cid;
        $id = $this->attempt(fn () => SupportService::open($cid, input('category'), input('subject'), input('message'),
            AttachmentService::fromUpload('attachment'), (int) Auth::id()), $back);
        $t = Db::one('SELECT * FROM support_tickets WHERE id = ?', [$id]);
        SupportService::assign($t, (int) Auth::id());
        flash('success', 'Support case opened.');
        redirect('/admin/support/' . $id);
    }

    public function reply(string $id): void
    {
        $t = $this->ticket((int) $id);
        $back = '/admin/support/' . $t['id'];
        $internal = input('internal') === '1';
        $this->attempt(fn () => SupportService::reply($t, input('message'), AttachmentService::fromUpload('attachment'), $internal), $back);
        if (!$internal && ($status = input('then_status')) && in_array($status, ['resolved', 'pending'], true)) {
            SupportService::setStatus(Db::one('SELECT * FROM support_tickets WHERE id = ?', [$t['id']]), $status);
        }
        flash('success', $internal ? 'Internal note added.' : 'Reply sent to customer.');
        redirect($back);
    }

    public function update(string $id): void
    {
        $t = $this->ticket((int) $id);
        $back = '/admin/support/' . $t['id'];
        $this->attempt(function () use ($t) {
            $assignee = input('assigned_to') === '' ? null : (int) input('assigned_to');
            if ($assignee !== ($t['assigned_to'] === null ? null : (int) $t['assigned_to'])) {
                SupportService::assign($t, $assignee);
                $t = Db::one('SELECT * FROM support_tickets WHERE id = ?', [$t['id']]);
            }
            if (($s = input('status')) !== '' && $s !== $t['status']) {
                SupportService::setStatus($t, $s, input('reason') ?: null);
            }
            if (in_array($pr = input('priority'), SupportService::PRIORITIES, true) && $pr !== $t['priority']) {
                Db::update('support_tickets', ['priority' => $pr], 'id = ?', [$t['id']]);
                AuditService::log('support.priority_changed', 'ticket', $t['id'], $t['priority'], $pr);
            }
        }, $back);
        flash('success', 'Ticket updated.');
        redirect($back);
    }

    // ── Chat inbox ────────────────────────────────────────────────────

    private function conversation(int $id): array
    {
        $c = Db::one('SELECT cc.*, u.full_name AS customer_name, c.customer_number FROM chat_conversations cc
                        JOIN customers c ON c.id = cc.customer_id JOIN users u ON u.id = c.user_id WHERE cc.id = ?', [$id]);
        if (!$c) {
            $this->notFound();
        }
        if (!StaffScope::canAccessCustomer((int) $c['customer_id'])) {
            $this->forbidden();
        }
        return $c;
    }

    public function chats(): void
    {
        [$scope, $params] = StaffScope::customerFilter('cc.customer_id');
        $status = input('status') === 'closed' ? 'closed' : 'open';
        $where = "$scope AND cc.status = ?";
        $params[] = $status;
        if (($q = input('q')) !== '') {
            $where .= ' AND (u.full_name LIKE ? OR cc.id IN (SELECT conversation_id FROM chat_messages WHERE body LIKE ?))';
            array_push($params, "%$q%", "%$q%");
        }
        $rows = Db::all(
            "SELECT cc.*, u.full_name AS customer_name, s.full_name AS assignee,
                    (SELECT COUNT(*) FROM chat_messages m WHERE m.conversation_id = cc.id AND m.sender = 'customer' AND m.id > cc.staff_last_read_id) AS unread,
                    (SELECT body FROM chat_messages m WHERE m.conversation_id = cc.id AND m.is_internal = 0 ORDER BY m.id DESC LIMIT 1) AS last_body
               FROM chat_conversations cc JOIN customers c ON c.id = cc.customer_id JOIN users u ON u.id = c.user_id
               LEFT JOIN users s ON s.id = cc.assigned_to
              WHERE $where AND cc.last_message_at IS NOT NULL ORDER BY unread > 0 DESC, cc.last_message_at DESC LIMIT 100",
            $params
        );
        $this->view('admin/chats', ['title' => 'Live chat', 'rows' => $rows, 'status' => $status]);
    }

    public function chat(string $id): void
    {
        $c = $this->conversation((int) $id);
        $this->view('admin/chat', ['title' => 'Chat with ' . $c['customer_name'], 'conv' => $c, 'staff' => $this->staffList()]);
    }

    public function chatMessages(string $id): void
    {
        $c = $this->conversation((int) $id);
        $msgs = ChatService::messages((int) $c['id'], max(0, (int) ($_GET['after'] ?? 0)), true);
        if ($msgs) {
            ChatService::markRead($c, end($msgs)['id'], true);
        }
        json_response(['messages' => $msgs]);
    }

    public function chatSend(string $id): void
    {
        $c = $this->conversation((int) $id);
        try {
            $mid = ChatService::post($c, input('message'), AttachmentService::fromUpload('attachment'), input('internal') === '1');
        } catch (BankingException $e) {
            if (wants_json()) {
                json_response(['error' => $e->getMessage()], 422);
            }
            flash('error', $e->getMessage());
            redirect('/admin/chats/' . $c['id']);
        }
        if (input('internal') !== '1') {
            $uid = (int) Db::value('SELECT user_id FROM customers WHERE id = ?', [$c['customer_id']]);
            // Avoid notification spam: only notify when the customer has not read the previous staff message.
            if (!Db::value("SELECT 1 FROM notifications WHERE user_id = ? AND link = '/chat' AND read_at IS NULL", [$uid])) {
                \App\Services\NotificationService::event($uid, 'chat_reply', [], '/chat');
            }
        }
        if (wants_json()) {
            json_response(['ok' => true, 'id' => $mid]);
        }
        redirect('/admin/chats/' . $c['id']);
    }

    public function chatUpdate(string $id): void
    {
        $c = $this->conversation((int) $id);
        $data = [];
        if (in_array(input('status'), ['open', 'closed'], true)) {
            $data['status'] = input('status');
        }
        if (isset($_POST['assigned_to'])) {
            $data['assigned_to'] = input('assigned_to') === '' ? null : (int) input('assigned_to');
        }
        if ($data) {
            Db::update('chat_conversations', $data, 'id = ?', [$c['id']]);
            AuditService::log('chat.updated', 'chat', $c['id'], ['status' => $c['status'], 'assigned_to' => $c['assigned_to']], $data);
        }
        flash('success', 'Conversation updated.');
        redirect('/admin/chats/' . $c['id']);
    }
}
