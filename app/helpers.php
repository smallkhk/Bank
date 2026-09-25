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
    $file = ROOT_PATH . '/public_html/assets/' . ltrim($path, '/');
    $v = is_file($file) ? filemtime($file) : 0;
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
