<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/** Minimal outbound HTTPS client (PHP curl) with timeouts and redacted request logging. */
final class HttpClient
{
    /**
     * @return array{status:int, body:string, json:?array, error:?string}
     */
    public static function request(string $provider, string $action, string $method, string $url, array $headers = [], array|string|null $body = null, ?array $basicAuth = null): array
    {
        $ch = curl_init($url);
        $h = [];
        foreach ($headers as $k => $v) {
            $h[] = "$k: $v";
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => $h,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_USERAGENT => 'BankPortal/1.0',
        ]);
        if ($basicAuth) {
            curl_setopt($ch, CURLOPT_USERPWD, $basicAuth[0] . ':' . $basicAuth[1]);
        }
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($body) ? http_build_query($body) : $body);
        }
        $start = microtime(true);
        $resp = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = $resp === false ? curl_error($ch) : null;
        curl_close($ch);
        $json = is_string($resp) ? json_decode($resp, true) : null;
        $ok = $error === null && $status >= 200 && $status < 300;

        self::log($provider, 'out', $action, $status ?: null, $ok, (int) ((microtime(true) - $start) * 1000),
            $error ?? ($ok ? 'OK' : self::errorSummary($json, (string) $resp)));
        return ['status' => $status, 'body' => (string) $resp, 'json' => is_array($json) ? $json : null, 'error' => $error];
    }

    private static function errorSummary(?array $json, string $raw): string
    {
        $msg = $json['error']['message'] ?? $json['message'] ?? $json['error'] ?? null;
        return mb_substr(is_string($msg) ? $msg : preg_replace('/\s+/', ' ', strip_tags($raw)), 0, 300);
    }

    public static function log(string $provider, string $direction, string $action, ?int $status, bool $ok, ?int $ms, string $summary): void
    {
        // Redact anything that looks like a key, token or card number before storing.
        $summary = preg_replace(['/\b(sk|rk|pk|whsec)_(test|live)?_?[A-Za-z0-9]+/', '/\b\d{13,19}\b/', '/\bAC[a-f0-9]{32}\b/i'], ['[redacted-key]', '[redacted-number]', '[redacted-sid]'], $summary);
        Db::insert('integration_logs', [
            'provider' => $provider, 'direction' => $direction, 'action' => mb_substr($action, 0, 80),
            'http_status' => $status, 'ok' => $ok ? 1 : 0, 'duration_ms' => $ms, 'summary' => mb_substr((string) $summary, 0, 1000),
        ]);
    }
}
