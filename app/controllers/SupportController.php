<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Services\AttachmentService;
use App\Services\BankingException;
use App\Services\ChatService;
use App\Services\StaffScope;
use App\Services\SupportService;

final class SupportController extends Controller
{
    private function requireTickets(): void
    {
        if (setting('support_enabled') !== '1') {
            if (setting('chat_enabled') === '1') {
                redirect('/chat');
            }
            flash('error', 'Online support is not available at the moment.');
            redirect('/dashboard');
        }
    }

    private function cid(): int
    {
        return Auth::customerId() ?? $this->forbidden();
    }

    private function ownTicket(int $id): array
    {
        $t = Db::one('SELECT * FROM support_tickets WHERE id = ? AND customer_id = ?', [$id, $this->cid()]);
        return $t ?: $this->notFound();
    }

    public function index(): void
    {
        $this->requireTickets();
        $this->view('customer/support', [
            'title' => 'Support',
            'tickets' => Db::all('SELECT * FROM support_tickets WHERE customer_id = ? ORDER BY updated_at DESC LIMIT 100', [$this->cid()]),
            'categories' => SupportService::categories(),
            'chatUnread' => ChatService::unreadForCustomer($this->cid()),
        ]);
    }

    public function store(): void
    {
        $this->requireTickets();
        $id = $this->attempt(function () {
            $att = AttachmentService::fromUpload('attachment');
            return SupportService::open($this->cid(), input('category'), input('subject'), input('message'), $att, (int) Auth::id());
        }, '/support');
        clear_old();
        flash('success', 'Your request has been received. We aim to respond within ' . (int) setting('support_sla_hours') . ' hours.');
        redirect('/support/' . $id);
    }

    public function show(string $id): void
    {
        $this->requireTickets();
        $t = $this->ownTicket((int) $id);
        $this->view('customer/ticket', ['title' => $t['subject'], 't' => $t, 'messages' => SupportService::messages((int) $t['id'], false)]);
    }

    public function reply(string $id): void
    {
        $this->requireTickets();
        $t = $this->ownTicket((int) $id);
        if ($t['status'] === 'closed') {
            flash('error', 'This request is closed. Please open a new one.');
            redirect('/support/' . $t['id']);
        }
        $this->attempt(fn () => SupportService::reply($t, input('message'), AttachmentService::fromUpload('attachment')), '/support/' . $t['id']);
        clear_old();
        flash('success', 'Reply sent.');
        redirect('/support/' . $t['id']);
    }

    public function close(string $id): void
    {
        $this->requireTickets();
        $t = $this->ownTicket((int) $id);
        SupportService::setStatus($t, 'closed', 'Closed by customer');
        flash('success', 'Request closed.');
        redirect('/support/' . $t['id']);
    }

    // ── Live chat (several conversations per customer, resumable any time) ──

    private function requireChat(): void
    {
        if (setting('chat_enabled') !== '1') {
            redirect('/support');
        }
    }

    private function ownConversation(int $id): array
    {
        return ChatService::find($this->cid(), $id) ?? $this->notFound();
    }

    public function chat(): void
    {
        $this->requireChat();
        $list = ChatService::conversations($this->cid());
        $this->showChat($list, $list[0] ?? null);
    }

    public function chatView(string $id): void
    {
        $this->requireChat();
        $conv = $this->ownConversation((int) $id);
        $this->showChat(ChatService::conversations($this->cid()), $conv);
    }

    private function showChat(array $list, ?array $conv): void
    {
        $this->view('customer/chat', [
            'title' => 'Chat with us', 'conversations' => $list, 'conv' => $conv,
            'online' => ChatService::supportOnline(), 'ticketsEnabled' => setting('support_enabled') === '1',
        ]);
    }

    public function chatStart(): void
    {
        $this->requireChat();
        $conv = $this->attempt(fn () => ChatService::start($this->cid(), input('subject')), '/chat');
        if (input('message') !== '') {
            ChatService::post($conv, input('message'), null);
        }
        redirect('/chat/' . $conv['id']);
    }

    public function chatClose(string $id): void
    {
        $conv = $this->ownConversation((int) $id);
        \App\Core\Db::update('chat_conversations', ['status' => 'closed'], 'id = ?', [$conv['id']]);
        flash('success', 'Chat ended. You can reopen it any time by sending a new message.');
        redirect('/chat/' . $conv['id']);
    }

    /** Poll: new messages for one conversation plus unread counts for the list. */
    public function chatMessages(string $id = ''): void
    {
        $conv = $id !== '' ? $this->ownConversation((int) $id) : ChatService::openConversation($this->cid());
        $msgs = ChatService::messages((int) $conv['id'], max(0, (int) ($_GET['after'] ?? 0)), false);
        if ($msgs) {
            ChatService::markRead($conv, end($msgs)['id'], false);
        }
        $unread = [];
        foreach (ChatService::conversations($this->cid()) as $c) {
            $unread[(int) $c['id']] = (int) $c['unread'];
        }
        json_response(['messages' => $msgs, 'unread' => $unread, 'online' => ChatService::supportOnline(),
            'status' => (string) \App\Core\Db::value('SELECT status FROM chat_conversations WHERE id = ?', [$conv['id']])]);
    }

    public function chatSend(string $id = ''): void
    {
        $this->requireChat();
        $conv = $id !== '' ? $this->ownConversation((int) $id) : ChatService::openConversation($this->cid());
        try {
            $mid = ChatService::post($conv, input('message'), AttachmentService::fromUpload('attachment'));
        } catch (BankingException $e) {
            if (wants_json()) {
                json_response(['error' => $e->getMessage()], 422);
            }
            flash('error', $e->getMessage());
            redirect('/chat/' . $conv['id']);
        }
        if (wants_json()) {
            json_response(['ok' => true, 'id' => $mid]);
        }
        redirect('/chat/' . $conv['id']);
    }

    // ── Attachments (customers and staff) ─────────────────────────────

    public function attachment(string $id): void
    {
        $att = Db::one('SELECT * FROM attachments WHERE id = ?', [(int) $id]);
        if (!$att) {
            $this->notFound();
        }
        $links = Db::all(
            'SELECT t.customer_id, m.is_internal FROM support_messages m JOIN support_tickets t ON t.id = m.ticket_id WHERE m.attachment_id = ?
             UNION ALL
             SELECT c.customer_id, m.is_internal FROM chat_messages m JOIN chat_conversations c ON c.id = m.conversation_id WHERE m.attachment_id = ?',
            [$att['id'], $att['id']]
        );
        $allowed = false;
        foreach ($links as $l) {
            if (Auth::isCustomer()) {
                $allowed = $allowed || ((int) $l['customer_id'] === Auth::customerId() && !$l['is_internal']);
            } else {
                $allowed = $allowed || (can('support.view') && StaffScope::canAccessCustomer((int) $l['customer_id']));
            }
        }
        if (!$allowed) {
            $this->forbidden();
        }
        AttachmentService::stream($att);
    }
}
