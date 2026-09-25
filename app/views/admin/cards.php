<?php $tabs = ['pending' => 'Pending issue' . ($pendingCount ? " ($pendingCount)" : ''), 'active' => 'Active', 'frozen' => 'Frozen', 'blocked' => 'Blocked', 'all' => 'All']; ?>
<div class="page-head"><h1>Cards</h1><?php if (can('cards.configure')): ?><a class="btn btn-secondary" href="<?= e(url('admin/card-products')) ?>">Card products</a><?php endif; ?></div>
<nav class="tabs"><?php foreach ($tabs as $k => $l): ?><a href="?tab=<?= $k ?>" class="<?= $tab === $k ? 'active' : '' ?>"><?= e($l) ?></a><?php endforeach; ?></nav>
<section class="card">
  <form class="filters" method="get"><input type="hidden" name="tab" value="<?= e($tab) ?>">
    <label>Search <input name="q" value="<?= e(input('q')) ?>" placeholder="Customer, last 4 digits, account"></label><button class="btn btn-secondary btn-sm">Search</button></form>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Card</th><th>Customer</th><th>Product</th><th>Account</th><th>Expires</th><th>Status</th><th>Requested</th></tr></thead>
    <tbody><?php foreach ($rows as $c): ?>
      <tr><td class="mono"><a href="<?= e(url('admin/cards/' . $c['id'])) ?>"><?= $c['pan_last4'] ? '•••• ' . e($c['pan_last4']) : 'Card #' . (int) $c['id'] ?></a></td>
        <td><?= e($c['customer_name']) ?></td><td><?= e($c['product_name']) ?><div class="muted small"><?= e($c['card_type']) ?><?= $c['replaces_card_id'] ? ' · replacement' : '' ?></div></td>
        <td class="mono"><?= e($c['account_number'] ?? '—') ?></td><td><?= e(App\Services\CardService::expiry($c)) ?></td>
        <td><?= status_badge($c['status']) ?></td><td><?= e(fmt_date($c['created_at'], 'M j, Y')) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="7" class="empty">No cards.</td></tr><?php endif; ?></tbody>
  </table></div>
  <?php include APP_PATH . '/views/partials/pager.php'; ?>
</section>
