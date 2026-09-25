<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Services\AuditService;
use App\Services\NotificationService;
use App\Services\Totp;

/** Two-factor authentication enrolment for customers and staff. */
final class SecurityController extends Controller
{
    public function twofa(): void
    {
        $user = Auth::user();
        if (!$user['twofa_enabled_at'] && empty($_SESSION['2fa_setup_secret'])) {
            $_SESSION['2fa_setup_secret'] = Totp::generateSecret();
        }
        $secret = $_SESSION['2fa_setup_secret'] ?? null;
        $this->view('customer/twofa', [
            'title'    => 'Two-step verification',
            'user'     => $user,
            'secret'   => $secret,
            'uri'      => $secret ? Totp::uri($secret, $user['email']) : null,
            'codes'    => $_SESSION['2fa_new_codes'] ?? null,
            'remaining'=> count(json_decode((string) $user['twofa_recovery_codes'], true) ?: []),
        ]);
        unset($_SESSION['2fa_new_codes']);
    }

    public function enable(): void
    {
        $user = Auth::user();
        $secret = $_SESSION['2fa_setup_secret'] ?? null;
        if ($user['twofa_enabled_at'] || !$secret) {
            redirect('/profile/2fa');
        }
        if (!password_verify((string) ($_POST['password'] ?? ''), $user['password_hash'])) {
            flash('error', 'Your password is incorrect.');
            redirect('/profile/2fa');
        }
        if (!Totp::verify($secret, input('code'))) {
            flash('error', 'That code is not valid. Check the time on your phone and try again.');
            redirect('/profile/2fa');
        }
        [$plain, $hashed] = self::recoveryCodes();
        Db::update('users', [
            'twofa_secret' => $secret, 'twofa_enabled_at' => now(), 'twofa_recovery_codes' => json_encode($hashed),
        ], 'id = ?', [$user['id']]);
        unset($_SESSION['2fa_setup_secret']);
        $_SESSION['2fa_new_codes'] = $plain;
        AuditService::log('security.2fa_enabled', 'user', $user['id']);
        NotificationService::event((int) $user['id'], 'security_changed', ['detail' => 'Two-step verification was turned on for your profile.']);
        flash('success', 'Two-step verification is on. Save your recovery codes now — they will not be shown again.');
        redirect('/profile/2fa');
    }

    public function disable(): void
    {
        $user = Auth::user();
        if (!$user['twofa_enabled_at']) {
            redirect('/profile/2fa');
        }
        if (Auth::isStaff() && setting('require_2fa_staff') === '1') {
            flash('error', 'Two-step verification is mandatory for staff.');
            redirect('/profile/2fa');
        }
        if (!password_verify((string) ($_POST['password'] ?? ''), $user['password_hash'])
            || !Totp::verify((string) $user['twofa_secret'], input('code'))) {
            flash('error', 'Password or code is incorrect.');
            redirect('/profile/2fa');
        }
        Db::update('users', ['twofa_secret' => null, 'twofa_enabled_at' => null, 'twofa_recovery_codes' => null], 'id = ?', [$user['id']]);
        AuditService::log('security.2fa_disabled', 'user', $user['id']);
        NotificationService::event((int) $user['id'], 'security_changed', ['detail' => 'Two-step verification was turned off for your profile.']);
        flash('success', 'Two-step verification has been turned off.');
        redirect('/profile/2fa');
    }

    /** @return array{0: string[], 1: string[]} plain codes and their hashes */
    public static function recoveryCodes(int $n = 8): array
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $plain = $hashed = [];
        for ($i = 0; $i < $n; $i++) {
            $c = '';
            for ($j = 0; $j < 8; $j++) {
                $c .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $plain[] = substr($c, 0, 4) . '-' . substr($c, 4);
            $hashed[] = password_hash($c, PASSWORD_DEFAULT);
        }
        return [$plain, $hashed];
    }
}
