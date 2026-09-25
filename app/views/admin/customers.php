<div class="page-head"><h1>Customers</h1>
  <?php if (can('customers.create')): ?><a class="btn btn-primary" href="<?= e(url('admin/customers/new')) ?>">New customer</a><?php endif; ?></div>
<section class="card">
  <form class="filters" method="get">
    <label>Search <input name="q" value="<?= e(input('q')) ?>" placeholder="Name, email, customer or account number"></label>
    <label>Status <select name="status"><option value="">All</option><?php foreach ($statuses as $s): ?><option <?= input('status') === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select></label>
    <button class="btn btn-secondary btn-sm">Search</button>
  </form>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Customer</th><th>Number</th><th>Country</th><th>Accounts</th><th class="num">Total balance</th><th>Status</th><th>Joined</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td><a href="<?= e(url('admin/customers/' . $r['id'])) ?>"><?= e($r['full_name']) ?></a><div class="muted small"><?= e($r['email']) ?></div></td>
        <td class="mono"><?= e($r['customer_number']) ?></td><td><?= e($r['country'] ?? '—') ?></td><td><?= (int) $r['account_count'] ?></td>
        <td class="num"><?= e(money((int) $r['total_balance'])) ?></td><td><?= status_badge($r['status']) ?></td><td><?= e(fmt_date($r['created_at'], 'M j, Y')) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="7" class="empty">No customers found.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
  <?php include APP_PATH . '/views/partials/pager.php'; ?>
</section>
