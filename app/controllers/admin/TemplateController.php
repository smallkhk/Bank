<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Db;
use App\Services\AuditService;
use App\Services\NotificationService;

final class TemplateController extends Controller
{
    public function index(): void
    {
        $this->view('admin/templates', [
            'title' => 'Notification templates',
            'templates' => Db::all('SELECT * FROM notification_templates ORDER BY name'),
            'mailEnabled' => (bool) config('mail.enabled'),
            'mailDriver' => (string) config('mail.driver', 'log'),
        ]);
    }

    public function update(string $id): void
    {
        $t = Db::one('SELECT * FROM notification_templates WHERE id = ?', [(int) $id]);
        if (!$t) {
            $this->notFound();
        }
        $data = [
            'subject' => mb_substr(input('subject'), 0, 190) ?: $t['subject'],
            'body' => mb_substr((string) ($_POST['body'] ?? ''), 0, 5000) ?: $t['body'],
            'send_email' => input('send_email') === '1' ? 1 : 0,
            'send_inapp' => input('send_inapp') === '1' ? 1 : 0,
        ];
        // Security-critical messages must always be delivered by email.
        if (in_array($t['event'], ['password_reset', 'email_verify'], true)) {
            $data['send_email'] = 1;
            $data['send_inapp'] = 0;
        }
        Db::update('notification_templates', $data, 'id = ?', [$t['id']]);
        AuditService::log('template.updated', 'notification_template', $t['event'], ['subject' => $t['subject']], ['subject' => $data['subject']]);
        flash('success', 'Template “' . $t['name'] . '” saved.');
        redirect('/admin/templates#t' . $t['id']);
    }

    public function reset(string $id): void
    {
        $t = Db::one('SELECT * FROM notification_templates WHERE id = ?', [(int) $id]);
        if (!$t || !isset(NotificationService::DEFAULTS[$t['event']])) {
            $this->notFound();
        }
        [, $subject, $body] = NotificationService::DEFAULTS[$t['event']];
        Db::update('notification_templates', ['subject' => $subject, 'body' => $body], 'id = ?', [$t['id']]);
        AuditService::log('template.reset', 'notification_template', $t['event']);
        flash('success', 'Template restored to default.');
        redirect('/admin/templates#t' . $t['id']);
    }
}
