<?php
use App\Core\Auth;
use App\Services\NotificationService;

$user = Auth::user();
$isStaff = Auth::isStaff();
$path = '/' . trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
$unread = $user ? NotificationService::unreadCount((int) $user['id']) : 0;

$nav = $isStaff ? array_filter([
    ['/admin', 'Overview', true],
    ['/admin/customers', 'Customers', can('customers.view')],
    ['/admin/accounts', 'Accounts', can('accounts.view')],
    ['/admin/transactions', 'Transactions', can('transactions.view')],
    ['/admin/withdrawals', 'Withdrawals', can('funds.approve') || can('funds.withdraw')],
    ['/admin/funds', 'Add funds', can('funds.approve') || can('funds.add') || can('funds.adjust')],
    ['/admin/staff', 'Staff', can('staff.view')],
    ['/admin/roles', 'Roles', can('roles.manage')],
    ['/admin/audit', 'Audit log', can('audit.view')],
    ['/admin/settings', 'Settings', can('settings.view')],
], fn ($i) => $i[2]) : [
    ['/dashboard', 'Dashboard'],
    ['/accounts', 'Accounts'],
    ['/transfer', 'Transfers'],
    ['/transactions', 'Transactions'],
    ['/withdrawals', 'Withdrawals'],
    ['/add-funds', 'Add funds'],
    ['/notifications', 'Notifications'],
    ['/profile', 'Security'],
];
$active = static function (string $href) use ($path): bool {
    return $href === $path || ($href !== '/admin' && str_starts_with($path, $href . '/'));
};
?>
<!doctype html>
<html lang="en">
<head><?php include APP_PATH . '/views/partials/head.php'; ?></head>
<body class="app <?= $isStaff ? 'is-staff' : 'is-customer' ?>">
  <?php include APP_PATH . '/views/partials/sandbox.php'; ?>
  <header class="topbar">
    <button class="nav-toggle" type="button" aria-label="Open menu" aria-controls="sidebar" aria-expanded="false" data-nav-toggle>
      <span></span><span></span><span></span>
    </button>
    <?php include APP_PATH . '/views/partials/brand.php'; ?>
    <?php if ($isStaff): ?><span class="env-tag">Back office</span><?php endif; ?>
    <div class="topbar-right">
      <a class="icon-link" href="<?= e(url('notifications')) ?>" aria-label="Notifications">
        <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="M12 22a2.5 2.5 0 0 0 2.45-2h-4.9A2.5 2.5 0 0 0 12 22Zm7-6V11a7 7 0 0 0-5.5-6.84V3.5a1.5 1.5 0 0 0-3 0v.66A7 7 0 0 0 5 11v5l-2 2v1h18v-1l-2-2Z"/></svg>
        <?php if ($unread): ?><span class="dot"><?= $unread > 9 ? '9+' : $unread ?></span><?php endif; ?>
      </a>
      <a class="user-chip" href="<?= e(url('profile')) ?>">
        <span class="avatar"><?= e(mb_strtoupper(mb_substr($user['full_name'], 0, 1))) ?></span>
        <span class="user-name"><?= e($user['full_name']) ?></span>
      </a>
      <form method="post" action="<?= e(url('logout')) ?>"><?= csrf_field() ?><button class="btn btn-ghost btn-sm">Sign out</button></form>
    </div>
  </header>
  <div class="shell">
    <nav class="sidebar" id="sidebar" aria-label="Main">
      <?php foreach ($nav as $item): ?>
        <a href="<?= e(url($item[0])) ?>" class="<?= $active($item[0]) ? 'active' : '' ?>"><?= e($item[1]) ?></a>
      <?php endforeach; ?>
      <?php if (!$isStaff): ?>
        <div class="sidebar-help">
          <strong>Need help?</strong>
          <?php if (setting('support_phone')): ?><span><?= e(setting('support_phone')) ?></span><?php endif; ?>
          <?php if (setting('contact_email')): ?><a href="mailto:<?= e(setting('contact_email')) ?>"><?= e(setting('contact_email')) ?></a><?php endif; ?>
        </div>
      <?php endif; ?>
    </nav>
    <main class="content">
      <?php include APP_PATH . '/views/partials/flash.php'; ?>
      <?= $content ?>
    </main>
  </div>
</body>
</html>
