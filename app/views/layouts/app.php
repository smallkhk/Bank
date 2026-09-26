<?php
use App\Core\Auth;
use App\Services\NotificationService;

$user = Auth::user();
$isStaff = Auth::isStaff();
$path = '/' . trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
$badges = App\Services\AttentionService::counts();
$unread = $badges['notifications'] ?? 0;
$b = static fn (string $k): int => (int) ($badges[$k] ?? 0);

// [href, label, icon, visible, badge count, section]
$nav = $isStaff ? [
    ['/admin', 'Overview', 'home', true, '', 'Operations'],
    ['/admin/customers', 'Customers', 'users', can('customers.view'), 'customers', 'Operations'],
    ['/admin/accounts', 'Accounts', 'wallet', can('accounts.view'), '', 'Operations'],
    ['/admin/transactions', 'Transactions', 'list', can('transactions.view'), 'transactions', 'Operations'],
    ['/admin/withdrawals', 'Withdrawals', 'withdraw', can('funds.approve') || can('funds.withdraw'), 'withdrawals', 'Operations'],
    ['/admin/funds', 'Add funds', 'plus', can('funds.approve') || can('funds.add') || can('funds.adjust'), 'funds', 'Operations'],
    ['/admin/cards', 'Cards', 'card', can('cards.view'), 'cards', 'Products'],
    ['/admin/card-products', 'Card products', 'layers', can('cards.configure'), '', 'Products'],
    ['/admin/crypto', 'Crypto', 'coins', can('crypto.view'), '', 'Products'],
    ['/admin/fees', 'Fees', 'tag', can('reports.view'), '', 'Products'],
    ['/admin/support', 'Support', 'chat', can('support.view'), 'support', 'Customer care'],
    ['/admin/chats', 'Live chat', 'mail', can('support.view'), 'chats', 'Customer care'],
    ['/admin/staff', 'Staff', 'users', can('staff.view'), '', 'Administration'],
    ['/admin/roles', 'Roles', 'lock', can('roles.manage'), '', 'Administration'],
    ['/admin/audit', 'Audit log', 'file', can('audit.view'), '', 'Administration'],
    ['/admin/account-types', 'Account types', 'bank', can('settings.view'), '', 'Administration'],
    ['/admin/templates', 'Templates', 'mail', can('settings.view'), '', 'Administration'],
    ['/admin/integrations', 'Integrations', 'plug', can('integrations.manage'), '', 'Administration'],
    ['/admin/settings', 'Settings', 'settings', can('settings.view'), '', 'Administration'],
] : [
    ['/dashboard', 'Dashboard', 'home', true, '', ''],
    ['/accounts', 'Accounts', 'wallet', true, '', ''],
    ['/transfer', 'Transfers', 'transfer', setting('transfers_enabled') === '1', '', ''],
    ['/transactions', 'Transactions', 'list', true, '', ''],
    ['/cards', 'Cards', 'card', setting('cards_enabled') === '1', '', ''],
    ['/crypto', 'Crypto', 'coins', setting('crypto_enabled') === '1', '', ''],
    ['/withdrawals', 'Withdrawals', 'withdraw', setting('withdrawals_enabled') === '1', '', ''],
    ['/add-funds', 'Add funds', 'plus', setting('customer_add_funds_requests') === '1' || App\Services\GatewayPaymentService::available(), '', ''],
    [setting('chat_enabled') === '1' ? '/chat' : '/support', 'Support', 'chat', setting('support_enabled') === '1' || setting('chat_enabled') === '1', 'support', ''],
    ['/notifications', 'Notifications', 'bell', true, 'notifications', ''],
    ['/profile', 'Security', 'shield', true, '', ''],
];
$nav = array_filter($nav, fn ($i) => $i[3]);
$active = static function (string $href) use ($path): bool {
    if (in_array($href, ['/chat', '/support'], true) && (str_starts_with($path, '/chat') || str_starts_with($path, '/support'))) {
        return true;
    }
    return $href === $path || ($href !== '/admin' && str_starts_with($path, $href . '/'));
};
?>
<!doctype html>
<html lang="en">
<head><?php include APP_PATH . '/views/partials/head.php'; ?></head>
<body class="app bg-<?= e(setting('bg_style', 'aurora')) ?> <?= $isStaff ? 'is-staff' : 'is-customer' ?>" data-badges-url="<?= e(url('badges')) ?>" data-currency-symbol="<?= e(setting('currency_symbol', '$')) ?>">
  <?php include APP_PATH . '/views/partials/sandbox.php'; ?>
  <header class="topbar">
    <button class="nav-toggle" type="button" aria-label="Open menu" aria-controls="sidebar" aria-expanded="false" data-nav-toggle>
      <span></span><span></span><span></span>
    </button>
    <?php include APP_PATH . '/views/partials/brand.php'; ?>
    <?php if ($isStaff): ?><span class="env-tag">Back office</span><?php endif; ?>
    <div class="topbar-right">
      <?php include APP_PATH . '/views/partials/theme_toggle.php'; ?>
      <a class="icon-link" href="<?= e(url('notifications')) ?>" aria-label="Notifications">
        <?= icon('bell', 20) ?>
        <span class="dot" data-badge="notifications"<?= $unread ? '' : ' hidden' ?>><?= $unread > 9 ? '9+' : $unread ?></span>
      </a>
      <a class="user-chip" href="<?= e(url('profile')) ?>">
        <span class="avatar"><?= e(mb_strtoupper(mb_substr($user['full_name'], 0, 1) . mb_substr((string) (explode(' ', trim($user['full_name']))[1] ?? ''), 0, 1))) ?></span>
        <span class="user-name"><?= e($user['full_name']) ?></span>
      </a>
      <form method="post" action="<?= e(url('logout')) ?>"><?= csrf_field() ?><button class="icon-link" aria-label="Sign out" title="Sign out"><?= icon('logout', 20) ?></button></form>
    </div>
  </header>
  <div class="shell">
    <nav class="sidebar" id="sidebar" aria-label="Main">
      <?php $section = null; foreach ($nav as [$href, $label, $ico, , $badgeKey, $sec]): $count = $badgeKey !== '' ? $b($badgeKey) : 0; ?>
        <?php if ($sec !== '' && $sec !== $section): $section = $sec; ?><div class="nav-label"><?= e($sec) ?></div><?php endif; ?>
        <a href="<?= e(url($href)) ?>" class="<?= $active($href) ? 'active' : '' ?>"<?= $active($href) ? ' aria-current="page"' : '' ?>><?= icon($ico) ?><span><?= e($label) ?></span><?php if ($badgeKey !== ''): ?><span class="count" data-badge="<?= e($badgeKey) ?>"<?= $count ? '' : ' hidden' ?>><?= $count > 99 ? '99+' : (int) $count ?></span><?php endif; ?></a>
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
