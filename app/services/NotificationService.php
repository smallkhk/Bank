<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/**
 * Event notifications rendered from admin-editable templates (notification_templates),
 * delivered in-app and by email. SMS is an integration point for later.
 */
final class NotificationService
{
    /** Default templates — seeded by the installer and used as fallback. */
    public const DEFAULTS = [
        'welcome'              => ['Welcome', 'Welcome to {{bank_name}}', "Hello {{name}},\n\nYour online banking profile has been created."],
        'profile_activated'    => ['Profile activated', 'Your profile is active', "Hello {{name}},\n\nYour online banking profile is now active. You can sign in at {{url}}."],
        'login_new_device'     => ['New device sign-in', 'New sign-in to your account', "Hello {{name}},\n\nWe noticed a sign-in from a new device or location ({{ip}}, {{device}}) at {{time}}.\nIf this was not you, change your password and contact support immediately."],
        'password_changed'     => ['Password changed', 'Your password was changed', "Hello {{name}},\n\nYour password was changed at {{time}}. If this was not you, contact support immediately."],
        'security_changed'     => ['Security setting changed', 'Security settings updated', "Hello {{name}},\n\n{{detail}}\nIf this was not you, contact support immediately."],
        'password_reset'       => ['Password reset request', 'Reset your password', "Hello {{name}},\n\nUse this link to reset your password (valid for 60 minutes):\n{{link}}\n\nIf you did not request this, ignore this email."],
        'email_verify'         => ['Verify email', 'Verify your email address', "Hello {{name}},\n\nConfirm your email address by opening this link (valid for 48 hours):\n{{link}}"],
        'transfer_sent'        => ['Transfer sent', 'Transfer sent', "{{amount}} was sent from {{account}} to {{counterparty}}. Reference {{reference}}."],
        'transfer_received'    => ['Money received', 'Money received', "{{amount}} was received into {{account}} from {{counterparty}}. Reference {{reference}}."],
        'deposit_posted'       => ['Funds added', 'Funds added to your account', "{{amount}} was credited to {{account}}."],
        'account_debited'      => ['Account debited', 'Your account was debited', "{{amount}} was debited from {{account}}: {{detail}}."],
        'withdrawal_requested' => ['Withdrawal requested', 'Withdrawal request received', "Your withdrawal of {{amount}} from {{account}} is pending review. Reference {{reference}}."],
        'withdrawal_completed' => ['Withdrawal approved', 'Withdrawal completed', "Your withdrawal of {{amount}} from {{account}} has been approved and completed. Reference {{reference}}."],
        'withdrawal_closed'    => ['Withdrawal not processed', 'Withdrawal {{status}}', "Withdrawal {{reference}} was {{status}}. Held funds have been released."],
        'transfer_rejected'    => ['Transfer not approved', 'Transfer not approved', "Your transfer {{reference}} was not approved. Held funds have been released."],
        'account_status'       => ['Account status changed', 'Account status updated', "Your account {{account}} is now {{status}}."],
        'account_opened'       => ['Account opened', 'New account opened', "A new {{type}} account {{account}} has been opened for you."],
        'fee_charged'          => ['Fee charged', 'Fee charged', "A {{fee}} of {{amount}} was charged to {{account}}."],
        'support_reply'        => ['Support reply', 'Update on your support request {{reference}}', "There is a new reply on your support request \"{{subject}}\". Sign in to view it."],
        'chat_reply'           => ['Chat reply', 'New message from support', "You have a new message from our support team."],
    ];

    /** Send an event notification using its template. $vars fill {{placeholders}}. */
    public static function event(int $userId, string $event, array $vars = [], ?string $link = null): void
    {
        $user = Db::one('SELECT id, full_name, email FROM users WHERE id = ?', [$userId]);
        if (!$user) {
            return;
        }
        $tpl = self::template($event);
        $vars += [
            'name' => $user['full_name'], 'bank_name' => bank_name(),
            'url' => rtrim((string) config('app.url', ''), '/'), 'time' => fmt_date(now()),
        ];
        $subject = self::render($tpl['subject'], $vars);
        $body = self::render($tpl['body'], $vars);

        if ($tpl['send_inapp']) {
            // In-app text skips the email greeting line.
            $inapp = trim((string) preg_replace('/^Hello [^\n]*\n+/', '', $body));
            Db::insert('notifications', [
                'user_id' => $userId, 'title' => mb_substr($subject, 0, 150),
                'body' => mb_substr($inapp, 0, 500), 'link' => $link,
            ]);
        }
        if ($tpl['send_email']) {
            $footer = "\n\n— " . bank_name() . (setting('support_phone') ? ' · ' . setting('support_phone') : '')
                . "\nThis is an automated message. Never share your password or one-time codes.";
            Mailer::send($user['email'], $subject, $body . $footer);
        }
    }

    public static function eventForAccountOwner(int $accountId, string $event, array $vars = [], ?string $link = null): void
    {
        $userId = Db::value('SELECT c.user_id FROM accounts a JOIN customers c ON c.id = a.customer_id WHERE a.id = ?', [$accountId]);
        if ($userId) {
            self::event((int) $userId, $event, $vars, $link);
        }
    }

    /** Plain in-app notification without a template. */
    public static function notify(int $userId, string $title, ?string $body = null, ?string $link = null): void
    {
        Db::insert('notifications', ['user_id' => $userId, 'title' => $title, 'body' => $body, 'link' => $link]);
    }

    public static function unreadCount(int $userId): int
    {
        return (int) Db::value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$userId]);
    }

    private static function template(string $event): array
    {
        try {
            $row = Db::one('SELECT subject, body, send_email, send_inapp FROM notification_templates WHERE event = ?', [$event]);
        } catch (\PDOException) {
            $row = null;
        }
        if ($row) {
            return $row;
        }
        [, $subject, $body] = self::DEFAULTS[$event] ?? [$event, $event, ''];
        return ['subject' => $subject, 'body' => $body, 'send_email' => 1, 'send_inapp' => 1];
    }

    public static function render(string $text, array $vars): string
    {
        return (string) preg_replace_callback('/\{\{\s*(\w+)\s*\}\}/', fn ($m) => (string) ($vars[$m[1]] ?? $m[0]), $text);
    }
}
