<div class="page-head"><h1>Accounts</h1></div>
<section class="card">
  <form class="filters" method="get">
    <label>Search <input name="q" value="<?= e(input('q')) ?>" placeholder="Account number or customer"></label>
    <label>Status <select name="status"><option value="">All</option><?php foreach (App\Services\AccountService::STATUSES as $s): ?><option <?= input('status') === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select></label>
    <button class="btn btn-secondary btn-sm">Search</button>
  </form>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Account number</th><th>Customer</th><th>Type</th><th class="num">Balance</th><th class="num">Available</th><th>Status</th></tr></thead>
    <tbody><?php foreach ($rows as $a): ?>
      <tr><td class="mono"><a href="<?= e(url('admin/accounts/' . $a['id'])) ?>"><?= e($a['account_number']) ?></a></td>
        <td><?= e($a['customer_name']) ?></td><td><?= e($a['type_name']) ?></td>
        <td class="num"><?= e(money((int) $a['balance'], $a['currency'])) ?></td><td class="num"><?= e(money(App\Services\AccountService::available($a), $a['currency'])) ?></td>
        <td><?= status_badge($a['status']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="6" class="empty">No accounts found.</td></tr><?php endif; ?></tbody>
  </table></div>
  <?php include APP_PATH . '/views/partials/pager.php'; ?>
</section>
<?php if ($system): ?>
<section class="card">
  <h2>Internal system (GL) accounts</h2>
  <p class="muted small">Counterparties for simulated money entering/leaving the platform. The sum of all balances across customer and system accounts is always zero.</p>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Code</th><th>Name</th><th class="num">Balance</th></tr></thead>
    <tbody><?php foreach ($system as $a): ?>
      <tr><td class="mono"><a href="<?= e(url('admin/accounts/' . $a['id'])) ?>"><?= e($a['account_number']) ?></a></td><td><?= e($a['nickname']) ?></td><td class="num"><?= e(money((int) $a['balance'], $a['currency'])) ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
</section>
<?php endif; ?>
