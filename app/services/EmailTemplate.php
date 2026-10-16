<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Wraps a plain-text message in a branded, email-safe HTML layout.
 *
 * Works on the text every caller already builds (greeting + body + the
 * "— Bank · phone / automated message" footer appended by NotificationService),
 * so no caller has to change. The plain text is still sent as the fallback part.
 *
 * Email HTML must be inline-styled table markup: Gmail, Outlook and Apple Mail
 * strip <style> blocks, fld..exbox and most modern CSS. Keep it boring on purpose.
 */
final class EmailTemplate
{
    public static function wrap(string $subject, string $text): string
    {
        $primary   = self::color(setting('color_primary'), '#0b3d63');
        $secondary = self::color(setting('color_secondary'), '#16629b');
        $name      = bank_name();
        $url       = (string) config('app.url', '');
        $logo      = trim((string) setting('logo_path'));

        // Split the trailing footer (first line starting with an em dash) from the body.
        $footer = '';
        $body   = $text;
        if (preg_match('/\n+(—[\s\S]+)$/u', $text, $m, PREG_OFFSET_CAPTURE)) {
            $body   = substr($text, 0, $m[0][1]);
            $footer = ltrim($m[1][0], "— \t");
        }

        // First "Hello ..." line becomes a heading; the rest become paragraphs.
        $lead = '';
        $paragraphs = preg_split('/\n\s*\n/', trim($body)) ?: [];
        if ($paragraphs && preg_match('/^(Hi|Hello|Dear)\b/i', trim($paragraphs[0]))) {
            $lead = trim(array_shift($paragraphs));
        }

        $e  = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $br = static fn (string $s): string => nl2br(self::linkify($s));

        // Header: logo image when an absolute URL is set, otherwise the bank name wordmark.
        $brandUrl = $logo !== '' && preg_match('#^https?://#i', $logo)
            ? $logo
            : ($url && $logo !== '' ? rtrim($url, '/') . '/' . ltrim($logo, '/') : '');
        $header = $brandUrl !== ''
            ? '<img src="' . $e($brandUrl) . '" alt="' . $e($name) . '" height="34" style="height:34px;border:0;display:block">'
            : '<span style="font:700 22px/1 Arial,Helvetica,sans-serif;color:#ffffff;letter-spacing:.3px">' . $e($name) . '</span>';

        $paraHtml = '';
        foreach ($paragraphs as $p) {
            $p = trim($p);
            if ($p !== '') {
                $paraHtml .= '<p style="margin:0 0 16px;font:400 15px/1.6 Arial,Helvetica,sans-serif;color:#2b3440">' . $br($p) . '</p>';
            }
        }

        $leadHtml = $lead !== ''
            ? '<p style="margin:0 0 18px;font:700 17px/1.4 Arial,Helvetica,sans-serif;color:#10171f">' . $e($lead) . '</p>'
            : '';

        $footHtml = $footer !== ''
            ? '<p style="margin:0;font:400 12px/1.6 Arial,Helvetica,sans-serif;color:#8a97a6">' . $br($footer) . '</p>'
            : '';

        $year = gmdate('Y');

        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="color-scheme" content="light"><title>' . $e($subject) . '</title></head>'
            . '<body style="margin:0;padding:0;background:#eef2f6;-webkit-text-size-adjust:100%">'
            . '<div style="display:none;max-height:0;overflow:hidden;opacity:0">' . $e($subject) . '</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef2f6">'
            . '<tr><td align="center" style="padding:28px 14px">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:14px;overflow:hidden;box-shadow:0 1px 4px rgba(16,23,31,.08)">'
            // Header band
            . '<tr><td style="background:' . $e($primary) . ';background:linear-gradient(135deg,' . $e($primary) . ',' . $e($secondary) . ');padding:22px 28px">' . $header . '</td></tr>'
            // Body
            . '<tr><td style="padding:30px 28px 26px">' . $leadHtml . $paraHtml . '</td></tr>'
            // Divider + footer
            . '<tr><td style="padding:0 28px"><div style="border-top:1px solid #e7ecf1"></div></td></tr>'
            . '<tr><td style="padding:18px 28px 26px">' . $footHtml . '</td></tr>'
            . '</table>'
            . '<p style="margin:16px 0 0;font:400 11px/1.5 Arial,Helvetica,sans-serif;color:#9aa6b2">&copy; ' . $year . ' ' . $e($name) . '. All rights reserved.</p>'
            . '</td></tr></table></body></html>';
    }

    /** Accepts a #rrggbb colour, else falls back; guards against markup injection in style attributes. */
    private static function color(?string $v, string $fallback): string
    {
        $v = trim((string) $v);
        return preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? $v : $fallback;
    }

    /** Escape, then turn bare https URLs into links. */
    private static function linkify(string $s): string
    {
        $s = htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        return preg_replace(
            '#(https?://[^\s<]+)#i',
            '<a href="$1" style="color:#16629b;text-decoration:underline">$1</a>',
            $s
        ) ?? $s;
    }
}
