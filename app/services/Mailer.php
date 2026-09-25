<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Outgoing email. Drivers (config mail.driver):
 *   log  — write messages to storage/logs/mail.log (default; safe for testing)
 *   mail — PHP mail(), which uses the cPanel server's sendmail
 * Integration point: add an API-based provider driver here later.
 */
final class Mailer
{
    public static function send(string $to, string $subject, string $text): bool
    {
        if (!config('mail.enabled') || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $fromEmail = (string) config('mail.from_email', 'no-reply@localhost');
        $fromName = (string) (config('mail.from_name') ?: bank_name());
        // Strip header-injection characters.
        $subject = str_replace(["\r", "\n"], ' ', $subject);
        $fromName = str_replace(["\r", "\n", '"'], '', $fromName);

        if (config('mail.driver', 'log') === 'mail') {
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
