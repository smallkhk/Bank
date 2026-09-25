<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Services\AuditService;
use App\Services\Money;
use App\Services\SettingsService;

final class SettingsController extends Controller
{
    /** Settings entered as money in the UI but stored in minor units. */
    private const MONEY_KEYS = [
        'transfer_fee_fixed', 'withdrawal_fee_fixed', 'transfer_approval_threshold',
        'default_daily_transfer_limit', 'default_daily_withdrawal_limit', 'default_monthly_limit',
    ];
    private const BOOL_KEYS = [
        'maintenance_mode', 'sandbox_notice', 'registration_enabled', 'registration_auto_activate',
        'add_funds_requires_approval', 'customer_add_funds_requests', 'confirm_password_for_transfers', 'allow_self_approval',
    ];
    private const INT_KEYS = [
        'account_number_length' => [8, 20], 'transfer_fee_bps' => [0, 10000], 'password_min_length' => [8, 64],
        'login_max_attempts' => [3, 20], 'login_lockout_minutes' => [1, 1440], 'session_idle_minutes' => [5, 480],
    ];

    public function index(): void
    {
        $this->view('admin/settings', [
            'title' => 'Settings', 's' => SettingsService::all(), 'moneyKeys' => self::MONEY_KEYS,
            'tab' => in_array(input('tab'), ['general', 'accounts', 'transactions', 'security', 'legal'], true) ? input('tab') : 'general',
        ]);
    }

    public function update(): void
    {
        $tab = input('tab', 'general');
        $back = '/admin/settings?tab=' . urlencode($tab);
        $current = SettingsService::all();
        $changes = [];
        $posted = (array) ($_POST['s'] ?? []);

        foreach ($posted as $key => $value) {
            if (!array_key_exists($key, SettingsService::DEFAULTS) || in_array($key, ['logo_path', 'favicon_path'], true)) {
                continue;
            }
            $value = is_string($value) ? trim($value) : '';
            if (in_array($key, self::MONEY_KEYS, true)) {
                $m = Money::parse($value === '' ? '0' : $value);
                if ($m === null) {
                    flash('error', "Invalid amount for $key.");
                    redirect($back);
                }
                $value = (string) $m;
            } elseif (isset(self::INT_KEYS[$key])) {
                [$min, $max] = self::INT_KEYS[$key];
                $value = (string) max($min, min($max, (int) $value));
            } elseif (str_starts_with($key, 'color_')) {
                if (!preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
                    continue;
                }
            } elseif ($key === 'currency') {
                $value = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $value), 0, 3));
            } elseif (in_array($key, ['account_number_prefix', 'account_number_branch'], true)) {
                $value = substr(preg_replace('/\D/', '', $value), 0, 6);
            }
            if (($current[$key] ?? null) !== $value) {
                $changes[$key] = [$current[$key] ?? null, $value];
                SettingsService::set($key, $value);
            }
        }
        // Unchecked checkboxes are not posted: the form sends a list of boolean keys it rendered.
        foreach ((array) ($_POST['bools'] ?? []) as $key) {
            if (in_array($key, self::BOOL_KEYS, true)) {
                $value = isset($posted[$key]) ? '1' : '0';
                if (($current[$key] ?? null) !== $value) {
                    $changes[$key] = [$current[$key] ?? null, $value];
                    SettingsService::set($key, $value);
                }
            }
        }
        foreach (['logo' => 'logo_path', 'favicon' => 'favicon_path'] as $field => $key) {
            if (!empty($_FILES[$field]['name'])) {
                $path = $this->storeImage($field, $back);
                $changes[$key] = [$current[$key] ?? null, $path];
                SettingsService::set($key, $path);
            }
        }
        if ($changes) {
            AuditService::log('settings.updated', 'settings', $tab,
                array_map(fn ($c) => $c[0], $changes), array_map(fn ($c) => $c[1], $changes));
        }
        flash('success', $changes ? 'Settings saved.' : 'No changes.');
        redirect($back);
    }

    /** Branding images are stored outside the web root and served through /branding/{kind}. */
    private function storeImage(string $field, string $back): string
    {
        $f = $_FILES[$field];
        if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > 1024 * 1024) {
            flash('error', 'Upload failed. Images must be under 1 MB.');
            redirect($back);
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/x-icon' => 'ico', 'image/vnd.microsoft.icon' => 'ico'][$mime] ?? null;
        if (!$ext || ($ext !== 'ico' && !getimagesize($f['tmp_name']))) {
            flash('error', 'Only PNG, JPG, WEBP or ICO images are allowed.');
            redirect($back);
        }
        $dir = STORAGE_PATH . '/branding';
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        $name = $field . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
        move_uploaded_file($f['tmp_name'], "$dir/$name");
        return $name;
    }

    public function brandingFile(string $kind): void
    {
        $name = match ($kind) { 'logo' => setting('logo_path'), 'favicon' => setting('favicon_path'), default => null };
        $file = $name ? STORAGE_PATH . '/branding/' . basename($name) : null;
        if (!$file || !is_file($file)) {
            http_response_code(404);
            exit;
        }
        $types = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp', 'ico' => 'image/x-icon'];
        header('Content-Type: ' . ($types[pathinfo($file, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
        header('Cache-Control: public, max-age=86400');
        readfile($file);
        exit;
    }
}
