<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Csrf;
use App\Services\Money;
use App\Services\SettingsService;

function config(string $key, mixed $default = null): mixed
{
    $value = $GLOBALS['__config'] ?? [];
    foreach (explode('.', $key) as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }
        $value = $value[$segment];
    }
    return $value;
}

function setting(string $key, ?string $default = null): ?string
{
    return SettingsService::get($key, $default);
}

function bank_name(): string
{
    return setting('bank_name') ?: '{{BANK_NAME}}';
}

/** Escape for HTML output. */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = '/'): string
{
    return rtrim((string) config('app.base_path', ''), '/') . '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    // Look beside the running front controller first: in the release layout public_html
    // sits next to the app folder, not inside it.
    $rel = '/assets/' . ltrim($path, '/');
    $v = 0;
    foreach ([dirname($_SERVER['SCRIPT_FILENAME'] ?? '') . $rel, ROOT_PATH . '/public_html' . $rel, dirname(ROOT_PATH) . '/public_html' . $rel] as $file) {
        if (is_file($file)) { $v = filemtime($file); break; }
    }
    return url('assets/' . ltrim($path, '/')) . '?v=' . $v;
}

function redirect(string $path): never
{
    header('Location: ' . (str_starts_with($path, 'http') ? $path : url($path)));
    exit;
}

function back(string $fallback = '/'): never
{
    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($ref !== '' && parse_url($ref, PHP_URL_HOST) === $host) {
        header('Location: ' . $ref);
        exit;
    }
    redirect($fallback);
}

function flash(string $type, ?string $message = null): ?string
{
    if ($message !== null) {
        $_SESSION['_flash'][$type] = $message;
        return null;
    }
    $msg = $_SESSION['_flash'][$type] ?? null;
    unset($_SESSION['_flash'][$type]);
    return $msg;
}

function old(string $key, string $default = ''): string
{
    return (string) ($_SESSION['_old'][$key] ?? $default);
}

function remember_input(array $except = ['password', 'password_confirmation', 'current_password', '_csrf']): void
{
    $_SESSION['_old'] = array_diff_key($_POST, array_flip($except));
}

function clear_old(): void
{
    unset($_SESSION['_old']);
}

function input(string $key, string $default = ''): string
{
    $v = $_POST[$key] ?? $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function csrf_field(): string
{
    return Csrf::field();
}

function now(): string
{
    return gmdate('Y-m-d H:i:s');
}

function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

function money(int|string|null $minor, ?string $currency = null): string
{
    return Money::format((int) $minor, $currency);
}

function can(string $permission): bool
{
    return Auth::can($permission);
}

function fmt_date(?string $dt, string $format = 'M j, Y g:i A'): string
{
    if (!$dt) {
        return '—';
    }
    return (new DateTimeImmutable($dt, new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone((string) config('app.timezone', 'UTC')))
        ->format($format);
}

function status_badge(string $status): string
{
    $map = [
        'active' => 'success', 'completed' => 'success', 'approved' => 'success', 'verified' => 'success',
        'pending' => 'warning', 'processing' => 'warning', 'restricted' => 'warning',
        'frozen' => 'info', 'reversed' => 'info',
        'locked' => 'danger', 'suspended' => 'danger', 'failed' => 'danger', 'rejected' => 'danger',
        'closed' => 'muted', 'cancelled' => 'muted',
        'open' => 'info', 'assigned' => 'info', 'escalated' => 'danger', 'resolved' => 'success', 'awaiting you' => 'warning',
        'paid' => 'success', 'minimum paid' => 'info', 'overdue' => 'danger', 'blocked' => 'danger', 'expired' => 'muted', 'inactive' => 'muted',
    ];
    return '<span class="badge badge-' . ($map[$status] ?? 'muted') . '">' . e(ucfirst($status)) . '</span>';
}

function mask_account(string $number): string
{
    return '•••• ' . substr($number, -4);
}

function paginate(int $total, int $perPage = 25): array
{
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $pages = max(1, (int) ceil($total / $perPage));
    $page = min($page, $pages);
    return ['page' => $page, 'pages' => $pages, 'offset' => ($page - 1) * $perPage, 'limit' => $perPage, 'total' => $total];
}

function page_url(int $page): string
{
    $q = $_GET;
    $q['page'] = $page;
    return '?' . http_build_query($q);
}

function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function wants_json(): bool
{
    return str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
}

/** Inline SVG line icons (stroke uses currentColor). */
function icon(string $name, int $size = 18): string
{
    static $paths = [
        'home' => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V20h14V9.5"/><path d="M10 20v-5h4v5"/>',
        'wallet' => '<path d="M3 7.5A2.5 2.5 0 0 1 5.5 5H18v4"/><rect x="3" y="7.5" width="18" height="12" rx="2.5"/><circle cx="16.5" cy="13.5" r="1.2"/>',
        'transfer' => '<path d="M7 7h13l-3.5-3.5"/><path d="M17 17H4l3.5 3.5"/>',
        'list' => '<path d="M8 6h13M8 12h13M8 18h13"/><circle cx="3.5" cy="6" r="1"/><circle cx="3.5" cy="12" r="1"/><circle cx="3.5" cy="18" r="1"/>',
        'card' => '<rect x="2.5" y="5" width="19" height="14" rx="2.5"/><path d="M2.5 10h19M6.5 15h4"/>',
        'coins' => '<ellipse cx="9" cy="7" rx="6" ry="2.8"/><path d="M3 7v4.5c0 1.5 2.7 2.8 6 2.8s6-1.3 6-2.8V7"/><path d="M9 14.3v2.2c0 1.5 2.7 2.8 6 2.8s6-1.3 6-2.8V12c0-1.5-2.7-2.8-6-2.8"/>',
        'withdraw' => '<path d="M12 4v11M7 10.5 12 15.5 17 10.5"/><path d="M4 20h16"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'chat' => '<path d="M4 5h16v11H9l-5 4z"/>',
        'bell' => '<path d="M6 16V11a6 6 0 1 1 12 0v5l1.5 2h-15z"/><path d="M10 20.5a2.2 2.2 0 0 0 4 0"/>',
        'shield' => '<path d="M12 3 4.5 6v5.5c0 4.6 3.2 8 7.5 9.5 4.3-1.5 7.5-4.9 7.5-9.5V6z"/><path d="m9 12 2.2 2.2L15.5 10"/>',
        'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.6-3.6 3.2-5.5 6.5-5.5s5.9 1.9 6.5 5.5"/><path d="M16 4.8a3.3 3.3 0 0 1 0 6.4M18 14.8c2 .7 3.2 2.5 3.5 5.2"/>',
        'bank' => '<path d="M3 9.5 12 4l9 5.5"/><path d="M5 10v8M9.5 10v8M14.5 10v8M19 10v8"/><path d="M3 20h18"/>',
        'check' => '<rect x="3.5" y="3.5" width="17" height="17" rx="3"/><path d="m8 12.5 2.8 2.8L16.5 9.5"/>',
        'file' => '<path d="M14 3H6.5A1.5 1.5 0 0 0 5 4.5v15A1.5 1.5 0 0 0 6.5 21h11a1.5 1.5 0 0 0 1.5-1.5V8z"/><path d="M14 3v5h5M8.5 13h7M8.5 17h5"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M12 2.8v2.4M12 18.8v2.4M4.2 7.5l2 1.2M17.8 15.3l2 1.2M4.2 16.5l2-1.2M17.8 8.7l2-1.2"/><circle cx="12" cy="12" r="7"/>',
        'plug' => '<path d="M9 3v5M15 3v5"/><path d="M6.5 8h11v3.5a5.5 5.5 0 0 1-11 0z"/><path d="M12 17v4"/>',
        'tag' => '<path d="M3 12V4h8l10 10-8 8z"/><circle cx="7.5" cy="8.5" r="1.3"/>',
        'layers' => '<path d="m12 3 9 5-9 5-9-5z"/><path d="m3 13 9 5 9-5"/>',
        'mail' => '<rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="m3.5 6.5 8.5 6.5 8.5-6.5"/>',
        'lock' => '<rect x="4.5" y="10.5" width="15" height="10" rx="2.5"/><path d="M8 10.5V8a4 4 0 0 1 8 0v2.5"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        'moon' => '<path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5z"/>',
        'eye' => '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'eye-off' => '<path d="M3 3l18 18"/><path d="M10.6 5.2A10 10 0 0 1 12 5c6.4 0 10 7 10 7a17 17 0 0 1-3 3.8M6.2 6.3C3.6 8 2 12 2 12s3.6 7 10 7a9.7 9.7 0 0 0 4.3-1"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/>',
        'in' => '<path d="M17 7 7 17M7 9v8h8"/>',
        'out' => '<path d="M7 17 17 7M9 7h8v8"/>',
        'logout' => '<path d="M15 4h3.5A1.5 1.5 0 0 1 20 5.5v13a1.5 1.5 0 0 1-1.5 1.5H15"/><path d="M10 16.5 5.5 12 10 7.5M5.5 12H16"/>',
        'chart' => '<path d="M4 20V4M4 20h16"/><path d="m7.5 14.5 4-4 3 3 5-6"/>',
        'clock' => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
        'refresh' => '<path d="M20 11a8 8 0 0 0-14.3-4.3L4 8.5M4 13a8 8 0 0 0 14.3 4.3l1.7-1.8"/><path d="M4 4v4.5h4.5M20 20v-4.5h-4.5"/>',
    ];
    $p = $paths[$name] ?? $paths['list'];
    return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
}

function greeting(): string
{
    $h = (int) (new DateTimeImmutable('now', new DateTimeZone((string) config('app.timezone', 'UTC'))))->format('G');
    return $h < 12 ? 'Good morning' : ($h < 18 ? 'Good afternoon' : 'Good evening');
}
