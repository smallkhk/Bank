<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Outgoing email. Drivers (config mail.driver):
 *   log  — write messages to storage/logs/mail.log (default; safe for testing)
 *   mail — PHP mail(), which uses the cPanel server's sendmail
 *   smtp — SMTP server configured under Admin → Integrations
 * Integration point: add an API-based provider driver here later.
 */
final class Mailer
{
    /** Admin setting wins; blank falls back to config/config.php. */
    public static function enabled(): bool
    {
        $s = setting('mail_enabled');
        return $s === '' || $s === null ? (bool) config('mail.enabled') : $s === '1';
    }

    public static function driver(): string
    {
        return (string) (setting('mail_driver') ?: config('mail.driver', 'log'));
    }

    public static function send(string $to, string $subject, string $text): bool
    {
        if (!self::enabled() || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $fromEmail = (string) (setting('mail_from_email') ?: config('mail.from_email', 'no-reply@localhost'));
        $fromName = (string) (setting('mail_from_name') ?: config('mail.from_name') ?: bank_name());
        // Strip header-injection characters.
        $subject = str_replace(["\r", "\n"], ' ', $subject);
        $fromName = str_replace(["\r", "\n", '"'], '', $fromName);

        if (self::driver() === 'smtp') {
            if (!Integrations::enabled('smtp')) {
                HttpClient::log('smtp', 'out', 'send', null, false, null, 'SMTP selected but the SMTP integration is disabled');
                return false;
            }
            return SmtpMailer::send(Integrations::config('smtp'), $to, $subject, $text, $fromEmail, $fromName);
        }
        if (self::driver() === 'mail') {
            $headers = [
                'From' => sprintf('"%s" <%s>', $fromName, $fromEmail),
                'MIME-Version' => '1.0',
                'Content-Type' => 'text/plain; charset=UTF-8',
                'Content-Transfer-Encoding' => '8bit',
            ];
            return mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $text, $headers);
        }
        $entry = sprintf("[%s] To: %s\nSubject: %s\n\n%s\n%s\n", gmdate('Y-m-d H:i:s'), $to, $subject, $text, str_repeat('-', 60));
        return (bool) file_put_contents(STORAGE_PATH . '/logs/mail.log', $entry, FILE_APPEND | LOCK_EX);
    }
}
