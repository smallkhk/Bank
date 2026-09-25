<?php
declare(strict_types=1);

namespace App\Services;

/** Dependency-free SMTP client: SSL/TLS (STARTTLS), AUTH LOGIN, plain-text UTF-8 messages. */
final class SmtpMailer
{
    public static function validate(array $c, string $mode): void
    {
        if (!ctype_digit((string) ($c['port'] ?? '')) || (int) $c['port'] < 1 || (int) $c['port'] > 65535) {
            throw new BankingException('Enter a valid SMTP port (usually 465 or 587).');
        }
        if (!in_array($c['encryption'] ?? '', ['ssl', 'tls', 'none'], true)) {
            throw new BankingException('Encryption must be ssl, tls or none.');
        }
        if ($mode === 'live' && $c['encryption'] === 'none') {
            throw new BankingException('Live mode requires an encrypted (ssl or tls) SMTP connection.');
        }
    }

    public static function test(array $c, string $mode): array
    {
        $s = self::open($c);
        self::cmd($s, 'QUIT', [221]);
        fclose($s);
        return ['ok' => true, 'message' => 'Connected and signed in to ' . $c['host'] . '.'];
    }

    public static function send(array $c, string $to, string $subject, string $text, string $fromEmail, string $fromName): bool
    {
        $start = microtime(true);
        try {
            $s = self::open($c);
            self::cmd($s, 'MAIL FROM:<' . $fromEmail . '>', [250]);
            self::cmd($s, 'RCPT TO:<' . $to . '>', [250, 251]);
            self::cmd($s, 'DATA', [354]);
            $headers = [
                'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
                'From: =?UTF-8?B?' . base64_encode($fromName) . '?= <' . $fromEmail . '>',
                'To: <' . $to . '>',
                'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
                'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . (explode('@', $fromEmail)[1] ?? 'localhost') . '>',
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: base64',
            ];
            $data = implode("\r\n", $headers) . "\r\n\r\n" . rtrim(chunk_split(base64_encode($text), 76, "\r\n")) . "\r\n.";
            self::cmd($s, $data, [250]);
            self::cmd($s, 'QUIT', [221]);
            fclose($s);
            HttpClient::log('smtp', 'out', 'send', null, true, (int) ((microtime(true) - $start) * 1000), 'Sent "' . mb_substr($subject, 0, 80) . '"');
            return true;
        } catch (\Throwable $e) {
            HttpClient::log('smtp', 'out', 'send', null, false, (int) ((microtime(true) - $start) * 1000), mb_substr($e->getMessage(), 0, 300));
            return false;
        }
    }

    /** @return resource */
    private static function open(array $c)
    {
        $host = (string) $c['host'];
        $remote = ($c['encryption'] === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . (int) $c['port'];
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host]]);
        $s = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (!$s) {
            throw new \RuntimeException("Could not connect to $host: $errstr");
        }
        stream_set_timeout($s, 20);
        self::expect($s, [220]);
        $ehlo = 'EHLO ' . (parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost');
        self::cmd($s, $ehlo, [250]);
        if ($c['encryption'] === 'tls') {
            self::cmd($s, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                throw new \RuntimeException('TLS negotiation failed.');
            }
            self::cmd($s, $ehlo, [250]);
        }
        if (($c['username'] ?? '') !== '') {
            self::cmd($s, 'AUTH LOGIN', [334]);
            self::cmd($s, base64_encode($c['username']), [334]);
            self::cmd($s, base64_encode((string) ($c['password'] ?? '')), [235], true);
        }
        return $s;
    }

    private static function cmd($s, string $line, array $codes, bool $secret = false): string
    {
        fwrite($s, $line . "\r\n");
        try {
            return self::expect($s, $codes);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException(($secret ? 'Authentication' : strtok($line, ' ')) . ' failed: ' . $e->getMessage());
        }
    }

    private static function expect($s, array $codes): string
    {
        $resp = '';
        while (($line = fgets($s, 1024)) !== false) {
            $resp .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        $code = (int) substr($resp, 0, 3);
        if (!in_array($code, $codes, true)) {
            throw new \RuntimeException(trim($resp) ?: 'no response');
        }
        return $resp;
    }
}
