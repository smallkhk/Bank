<div class="page-head"><h1>Transactions</h1>
  <a class="btn btn-ghost" href="<?= e('?' . http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>">Export CSV</a></div>
<section class="card">
  <form class="filters" method="get">
    <label>Search <input name="q" value="<?= e(input('q')) ?>" placeholder="Reference, description, account"></label>
    <label>Type <select name="type"><option value="">All</option><?php foreach ($types as $t): ?><option <?= input('type') === $t ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select></label>
    <label>Status <select name="status"><option value="">All</option><?php foreach ($statuses as $t): ?><option <?= input('status') === $t ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select></label>
    <label>From <input type="date" name="from" value="<?= e(input('from')) ?>"></label>
    <label>To <input type="date" name="to" value="<?= e(input('to')) ?>"></label>
    <label>Min <input name="min" value="<?= e(input('min')) ?>" inputmode="decimal" size="8"></label>
    <label>Max <input name="max" value="<?= e(input('max')) ?>" inputmode="decimal" size="8"></label>
    <button class="btn btn-secondary btn-sm">Filter</button>
  </form>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Date</th><th>Reference</th><th>Type</th><th>From</th><th>To</th><th class="num">Amount</th><th>Status</th></tr></thead>
    <tbody><?php foreach ($rows as $t): ?>
      <tr><td class="nowrap"><?= e(fmt_date($t['created_at'])) ?></td><td class="mono"><a href="<?= e(url('admin/transactions/' . $t['id'])) ?>"><?= e($t['reference']) ?></a></td>
        <td><?= e($t['type']) ?></td><td class="mono"><?= e($t['from_number'] ?? '—') ?></td><td class="mono"><?= e($t['to_number'] ?? '—') ?></td>
        <td class="num"><?= e(money((int) $t['amount'], $t['currency'])) ?></td><td><?= status_badge($t['status']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="7" class="empty">No transactions found.</td></tr><?php endif; ?></tbody>
  </table></div>
  <?php include APP_PATH . '/views/partials/pager.php'; ?>
</section>
