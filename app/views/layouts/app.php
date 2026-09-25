<?php
use App\Core\Auth;
use App\Services\NotificationService;

$user = Auth::user();
$isStaff = Auth::isStaff();
$path = '/' . trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
$unread = $user ? NotificationService::unreadCount((int) $user['id']) : 0;
$openTickets = $unreadChats = $chatUnread = $pendingCards = 0;
if ($isStaff && can('cards.issue')) {
    [$sc, $sp] = App\Services\StaffScope::customerFilter('c.customer_id');
    $pendingCards = (int) App\Core\Db::value("SELECT COUNT(*) FROM cards c WHERE c.status = 'pending' AND $sc", $sp);
}
if ($isStaff && can('support.view')) {
    [$sc, $sp] = App\Services\StaffScope::customerFilter('t.customer_id');
    $openTickets = (int) App\Core\Db::value("SELECT COUNT(*) FROM support_tickets t WHERE $sc AND t.status IN ('open','escalated')", $sp);
    [$sc, $sp] = App\Services\StaffScope::customerFilter('cc.customer_id');
    $unreadChats = (int) App\Core\Db::value("SELECT COUNT(DISTINCT cc.id) FROM chat_conversations cc JOIN chat_messages m ON m.conversation_id = cc.id
        WHERE $sc AND cc.status = 'open' AND m.sender = 'customer' AND m.id > cc.staff_last_read_id", $sp);
} elseif (!$isStaff && ($cid = App\Core\Auth::customerId())) {
    $chatUnread = App\Services\ChatService::unreadForCustomer($cid);
}

// [href, label, icon, visible, badge count, section]
$nav = $isStaff ? [
    ['/admin', 'Overview', 'home', true, 0, 'Operations'],
    ['/admin/customers', 'Customers', 'users', can('customers.view'), 0, 'Operations'],
    ['/admin/accounts', 'Accounts', 'wallet', can('accounts.view'), 0, 'Operations'],
    ['/admin/transactions', 'Transactions', 'list', can('transactions.view'), 0, 'Operations'],
    ['/admin/withdrawals', 'Withdrawals', 'withdraw', can('funds.approve') || can('funds.withdraw'), 0, 'Operations'],
    ['/admin/funds', 'Add funds', 'plus', can('funds.approve') || can('funds.add') || can('funds.adjust'), 0, 'Operations'],
    ['/admin/cards', 'Cards', 'card', can('cards.view'), $pendingCards, 'Products'],
    ['/admin/card-products', 'Card products', 'layers', can('cards.configure'), 0, 'Products'],
    ['/admin/crypto', 'Crypto', 'coins', can('crypto.view'), 0, 'Products'],
    ['/admin/fees', 'Fees', 'tag', can('reports.view'), 0, 'Products'],
    ['/admin/support', 'Support', 'chat', can('support.view'), $openTickets, 'Customer care'],
    ['/admin/chats', 'Live chat', 'mail', can('support.view'), $unreadChats, 'Customer care'],
    ['/admin/staff', 'Staff', 'users', can('staff.view'), 0, 'Administration'],
    ['/admin/roles', 'Roles', 'lock', can('roles.manage'), 0, 'Administration'],
    ['/admin/audit', 'Audit log', 'file', can('audit.view'), 0, 'Administration'],
    ['/admin/account-types', 'Account types', 'bank', can('settings.view'), 0, 'Administration'],
    ['/admin/templates', 'Templates', 'mail', can('settings.view'), 0, 'Administration'],
    ['/admin/integrations', 'Integrations', 'plug', can('integrations.manage'), 0, 'Administration'],
    ['/admin/settings', 'Settings', 'settings', can('settings.view'), 0, 'Administration'],
] : [
    ['/dashboard', 'Dashboard', 'home', true, 0, ''],
    ['/accounts', 'Accounts', 'wallet', true, 0, ''],
    ['/transfer', 'Transfers', 'transfer', setting('transfers_enabled') === '1', 0, ''],
    ['/transactions', 'Transactions', 'list', true, 0, ''],
    ['/cards', 'Cards', 'card', setting('cards_enabled') === '1', 0, ''],
    ['/crypto', 'Crypto', 'coins', setting('crypto_enabled') === '1', 0, ''],
    ['/withdrawals', 'Withdrawals', 'withdraw', setting('withdrawals_enabled') === '1', 0, ''],
    ['/add-funds', 'Add funds', 'plus', setting('customer_add_funds_requests') === '1' || App\Services\GatewayPaymentService::available(), 0, ''],
    ['/support', 'Support', 'chat', setting('support_enabled') === '1' || setting('chat_enabled') === '1', $chatUnread, ''],
    ['/notifications', 'Notifications', 'bell', true, $unread, ''],
    ['/profile', 'Security', 'shield', true, 0, ''],
];
$nav = array_filter($nav, fn ($i) => $i[3]);
$active = static function (string $href) use ($path): bool {
    return $href === $path || ($href !== '/admin' && str_starts_with($path, $href . '/'));
};
?>
<!doctype html>
<html lang="en">
<head><?php include APP_PATH . '/views/partials/head.php'; ?></head>
<body class="app <?= $isStaff ? 'is-staff' : 'is-customer' ?>" data-currency-symbol="<?= e(setting('currency_symbol', '$')) ?>">
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
        <?php if ($unread): ?><span class="dot"><?= $unread > 9 ? '9+' : $unread ?></span><?php endif; ?>
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
      <?php $section = null; foreach ($nav as [$href, $label, $ico, , $count, $sec]): ?>
        <?php if ($sec !== '' && $sec !== $section): $section = $sec; ?><div class="nav-label"><?= e($sec) ?></div><?php endif; ?>
        <a href="<?= e(url($href)) ?>" class="<?= $active($href) ? 'active' : '' ?>"<?= $active($href) ? ' aria-current="page"' : '' ?>><?= icon($ico) ?><span><?= e($label) ?></span><?php if ($count): ?><span class="count"><?= $count > 99 ? '99+' : (int) $count ?></span><?php endif; ?></a>
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
