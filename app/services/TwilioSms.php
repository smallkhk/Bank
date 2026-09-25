<?php
declare(strict_types=1);

namespace App\Services;

final class TwilioSms
{
    private static function base(array $c): string
    {
        return rtrim(($c['api_base'] ?? '') ?: 'https://api.twilio.com', '/');
    }

    public static function validate(array $c, string $mode): void
    {
        if (!preg_match('/^AC[a-f0-9]{32}$/i', (string) ($c['account_sid'] ?? ''))) {
            throw new BankingException('The Account SID should look like AC followed by 32 characters.');
        }
        if (!preg_match('/^\+[1-9]\d{6,14}$/', (string) ($c['from'] ?? ''))) {
            throw new BankingException('The sender number must be in international format, e.g. +15551234567.');
        }
        if ($mode === 'live' && ($c['api_base'] ?? '') !== '' && !str_starts_with($c['api_base'], 'https://')) {
            throw new BankingException('Live mode requires an https:// API URL.');
        }
    }

    public static function test(array $c, string $mode): array
    {
        $r = HttpClient::request('twilio', 'test.account', 'GET', self::base($c) . '/2010-04-01/Accounts/' . $c['account_sid'] . '.json', [], null, [$c['account_sid'], $c['auth_token']]);
        if ($r['status'] !== 200) {
            return ['ok' => false, 'message' => $r['status'] === 401 ? 'Twilio rejected the Account SID or auth token.' : 'Could not reach Twilio.'];
        }
        return ['ok' => true, 'message' => 'Connected to Twilio account "' . ($r['json']['friendly_name'] ?? $c['account_sid']) . '".'];
    }

    public static function send(array $c, string $to, string $body): bool
    {
        if (!preg_match('/^\+[1-9]\d{6,14}$/', $to)) {
            return false;
        }
        $r = HttpClient::request('twilio', 'sms.send', 'POST', self::base($c) . '/2010-04-01/Accounts/' . $c['account_sid'] . '/Messages.json',
            [], ['From' => $c['from'], 'To' => $to, 'Body' => mb_substr($body, 0, 480)], [$c['account_sid'], $c['auth_token']]);
        return $r['status'] === 201 || $r['status'] === 200;
    }

    /** Normalise a stored phone number to E.164 where possible (numbers without "+" get the default country code). */
    public static function e164(?string $phone): ?string
    {
        $p = preg_replace('/[^\d+]/', '', (string) $phone);
        if ($p === '') {
            return null;
        }
        if ($p[0] !== '+') {
            $cc = ['US' => '1', 'CA' => '1', 'GB' => '44', 'NG' => '234', 'GH' => '233', 'KE' => '254', 'ZA' => '27', 'IN' => '91', 'AU' => '61', 'DE' => '49', 'FR' => '33'][strtoupper((string) setting('bank_country', 'US'))] ?? null;
            if (!$cc) {
                return null;
            }
            $p = '+' . $cc . ltrim($p, '0');
        }
        return preg_match('/^\+[1-9]\d{6,14}$/', $p) ? $p : null;
    }
}
