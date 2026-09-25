<div class="page-head">
  <div><a class="back" href="<?= e(url('admin/customers')) ?>">← Customers</a>
    <h1><?= e($c['full_name']) ?> <?= status_badge($c['status']) ?></h1>
    <p class="muted"><span class="mono"><?= e($c['customer_number']) ?></span> · <?= e($c['email']) ?> · @<?= e($c['username']) ?></p></div>
</div>
<div class="two-col">
  <section class="card">
    <h2>Profile</h2>
    <dl class="kv">
      <dt>Phone</dt><dd><?= e($c['phone'] ?: '—') ?></dd>
      <dt>Date of birth</dt><dd><?= e($c['date_of_birth'] ?: '—') ?></dd>
      <dt>Address</dt><dd><?= e(trim(($c['address'] ?? '') . ', ' . ($c['city'] ?? '') . ', ' . ($c['country'] ?? ''), ', ') ?: '—') ?></dd>
      <dt>KYC status</dt><dd><?= e(str_replace('_', ' ', $c['kyc_status'])) ?></dd>
      <dt>Registered</dt><dd><?= e(fmt_date($c['registered_at'])) ?></dd>
      <dt>Last sign-in</dt><dd><?= e(fmt_date($c['last_login_at'])) ?> <?= e($c['last_login_ip'] ?? '') ?></dd>
      <?php if ($c['status_reason']): ?><dt>Status reason</dt><dd><?= e($c['status_reason']) ?></dd><?php endif; ?>
    </dl>
    <?php if (can('customers.lock')): ?>
    <form method="post" action="<?= e(url('admin/customers/' . $c['id'] . '/status')) ?>" class="form inline-form">
      <?= csrf_field() ?>
      <label>Change status <select name="status"><?php foreach ($statuses as $s): ?><option <?= $c['status'] === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select></label>
      <label>Reason <input name="reason" required maxlength="255"></label>
      <button class="btn btn-secondary btn-sm">Update</button>
    </form>
    <?php endif; ?>
  </section>
  <section class="card">
    <h2>Account managers</h2>
    <?php if (!$managers): ?><p class="empty">No manager assigned.</p><?php endif; ?>
    <?php foreach ($managers as $m): ?>
      <div class="list-row"><div><strong><?= e($m['full_name']) ?></strong><div class="muted small"><?= e($m['email']) ?> · since <?= e(fmt_date($m['assigned_at'], 'M j, Y')) ?></div></div>
        <?php if (can('accounts.assign_manager')): ?><form method="post" action="<?= e(url('admin/customers/' . $c['id'] . '/managers/' . $m['id'] . '/remove')) ?>"><?= csrf_field() ?><button class="btn btn-ghost btn-sm">Remove</button></form><?php endif; ?></div>
    <?php endforeach; ?>
    <?php if (can('accounts.assign_manager')): ?>
    <form method="post" action="<?= e(url('admin/customers/' . $c['id'] . '/managers')) ?>" class="form inline-form">
      <?= csrf_field() ?>
      <label>Assign staff member <select name="manager_id" required><option value="">Select…</option><?php foreach ($staff as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['full_name']) ?></option><?php endforeach; ?></select></label>
      <button class="btn btn-secondary btn-sm">Assign</button>
    </form>
    <?php endif; ?>
  </section>
</div>

<section class="card">
  <div class="card-head"><h2>Accounts</h2></div>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Account number</th><th>Type</th><th class="num">Balance</th><th class="num">On hold</th><th>Status</th><th>Opened</th></tr></thead>
    <tbody><?php foreach ($accounts as $a): ?>
      <tr><td class="mono"><a href="<?= e(url('admin/accounts/' . $a['id'])) ?>"><?= e($a['account_number']) ?></a></td><td><?= e($a['type_name']) ?></td>
        <td class="num"><?= e(money((int) $a['balance'], $a['currency'])) ?></td><td class="num"><?= e(money((int) $a['held_amount'], $a['currency'])) ?></td>
        <td><?= status_badge($a['status']) ?></td><td><?= e(fmt_date($a['created_at'], 'M j, Y')) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$accounts): ?><tr><td colspan="6" class="empty">No accounts.</td></tr><?php endif; ?></tbody>
  </table></div>
  <?php if (can('accounts.create')): ?>
  <form method="post" action="<?= e(url('admin/customers/' . $c['id'] . '/accounts')) ?>" class="form inline-form">
    <?= csrf_field() ?>
    <label>Open new account <select name="account_type"><?php foreach ($types as $t): ?><option value="<?= e($t['slug']) ?>"><?= e($t['name']) ?></option><?php endforeach; ?></select></label>
    <label>Nickname (optional) <input name="nickname" maxlength="80"></label>
    <button class="btn btn-primary btn-sm">Open account</button>
  </form>
  <?php endif; ?>
</section>

<section class="card">
  <h2>Recent audit history</h2>
  <?php include APP_PATH . '/views/admin/_audit_rows.php'; ?>
</section>
