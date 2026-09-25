<div class="page-head"><h1>Transactions</h1></div>
<?php if ($pending): ?>
<section class="card">
  <h2>Awaiting approval</h2>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Date</th><th>Reference</th><th>Description</th><th class="num">Amount</th><th>Status</th></tr></thead>
    <tbody><?php foreach ($pending as $t): ?>
      <tr><td><?= e(fmt_date($t['created_at'], 'M j, Y')) ?></td><td class="mono"><a href="<?= e(url('transactions/' . $t['reference'])) ?>"><?= e($t['reference']) ?></a></td>
        <td><?= e($t['description']) ?></td><td class="num"><?= e(money((int) $t['amount'])) ?></td><td><?= status_badge($t['status']) ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
</section>
<?php endif; ?>
<section class="card">
  <form class="filters" method="get">
    <label>Search <input name="q" value="<?= e($filters['q'] ?? '') ?>" placeholder="Reference or description"></label>
    <label>From <input type="date" name="from" value="<?= e($filters['from'] ?? '') ?>"></label>
    <label>To <input type="date" name="to" value="<?= e($filters['to'] ?? '') ?>"></label>
    <label>Type <select name="type"><option value="">All</option>
      <?php foreach (['deposit', 'withdrawal', 'transfer', 'fee', 'adjustment'] as $t): ?><option value="<?= $t ?>" <?= ($filters['type'] ?? '') === $t ? 'selected' : '' ?>><?= ucfirst($t) ?></option><?php endforeach; ?>
    </select></label>
    <button class="btn btn-secondary btn-sm">Filter</button>
  </form>
  <?php include APP_PATH . '/views/partials/entries.php'; ?>
  <?php include APP_PATH . '/views/partials/pager.php'; ?>
</section>
