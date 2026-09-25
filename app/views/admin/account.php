<?php $sys = (bool) $a['is_system']; ?>
<div class="page-head">
  <div><a class="back" href="<?= e(url($sys ? 'admin/accounts' : 'admin/customers/' . $a['customer_id'])) ?>">← <?= $sys ? 'Accounts' : e($a['customer_name']) ?></a>
    <h1 class="mono"><?= e($a['account_number']) ?> <?= status_badge($a['status']) ?></h1>
    <p class="muted"><?= e($sys ? 'System account · ' . $a['nickname'] : $a['type_name'] . ' · ' . $a['currency'] . ' · opened ' . fmt_date($a['created_at'], 'M j, Y')) ?><?= $a['status_reason'] ? ' · ' . e($a['status_reason']) : '' ?></p></div>
</div>

<div class="stat-grid">
  <div class="stat stat-primary"><span>Balance</span><strong><?= e(money((int) $a['balance'], $a['currency'])) ?></strong>
    <small><?= $ledgerBalance === (int) $a['balance'] ? '✓ Reconciled with ledger' : '⚠ Ledger shows ' . e(money($ledgerBalance)) ?></small></div>
  <div class="stat"><span>Available</span><strong><?= e(money(App\Services\AccountService::available($a), $a['currency'])) ?></strong></div>
  <div class="stat"><span>On hold</span><strong><?= e(money((int) $a['held_amount'], $a['currency'])) ?></strong></div>
  <?php if (!$sys): ?><div class="stat"><span>Limits (effective)</span><strong class="small-strong"><?= e(money($limits['daily_transfer'])) ?>/day</strong>
    <small>Withdrawals <?= e(money($limits['daily_withdrawal'])) ?>/day · <?= e(money($limits['monthly'])) ?>/month</small></div><?php endif; ?>
</div>

<?php if (!$sys): ?>
<div class="action-grid">
  <?php if (can('funds.add') || can('funds.adjust')): ?>
  <section class="card">
    <h2>Add funds / adjustment</h2>
    <form method="post" action="<?= e(url('admin/accounts/' . $a['id'] . '/funds')) ?>" class="form">
      <?= csrf_field() ?>
      <label>Type <select name="kind">
        <?php if (can('funds.add')): ?><option value="deposit">Deposit (add funds)</option><?php endif; ?>
        <?php if (can('funds.adjust')): ?><option value="adjustment_credit">Adjustment — credit</option><option value="adjustment_debit">Adjustment — debit</option><?php endif; ?>
      </select></label>
      <label>Amount <input name="amount" required inputmode="decimal" placeholder="0.00"></label>
      <label>Reason <input name="reason" required maxlength="255"></label>
      <label>External reference <input name="external_reference" maxlength="100"></label>
      <button class="btn btn-primary btn-sm">Submit</button>
      <p class="muted small"><?= setting('add_funds_requires_approval') === '1' ? 'Requires approval by a second authorized staff member.' : 'Deposits post immediately for approvers; adjustments always need a second approver.' ?></p>
    </form>
  </section>
  <?php endif; ?>
  <?php if (can('funds.withdraw')): ?>
  <section class="card">
    <h2>Initiate withdrawal</h2>
    <form method="post" action="<?= e(url('admin/accounts/' . $a['id'] . '/withdrawals')) ?>" class="form">
      <?= csrf_field() ?>
      <label>Amount <input name="amount" required inputmode="decimal" placeholder="0.00"></label>
      <label>Reason / payout details <input name="reason" required maxlength="255"></label>
      <button class="btn btn-secondary btn-sm">Create request</button>
    </form>
  </section>
  <?php endif; ?>
  <?php if (can('accounts.lock') || can('accounts.freeze')): ?>
  <section class="card">
    <h2>Account status</h2>
    <form method="post" action="<?= e(url('admin/accounts/' . $a['id'] . '/status')) ?>" class="form">
      <?= csrf_field() ?>
      <label>Status <select name="status"><?php foreach (App\Services\AccountService::STATUSES as $s): ?><option <?= $a['status'] === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select></label>
      <label>Reason <input name="reason" required maxlength="255"></label>
      <button class="btn btn-secondary btn-sm" data-confirm="Change this account's status?">Change status</button>
    </form>
  </section>
  <?php endif; ?>
  <?php if (can('accounts.limit')): ?>
  <section class="card">
    <h2>Account limits</h2>
    <form method="post" action="<?= e(url('admin/accounts/' . $a['id'] . '/limits')) ?>" class="form">
      <?= csrf_field() ?>
      <?php foreach (['daily_transfer_limit' => 'Daily transfer', 'daily_withdrawal_limit' => 'Daily withdrawal', 'monthly_limit' => 'Monthly'] as $k => $label): ?>
        <label><?= $label ?> <input name="<?= $k ?>" value="<?= $a[$k] !== null ? e(App\Services\Money::toDecimal((int) $a[$k])) : '' ?>" placeholder="Inherit default" inputmode="decimal"></label>
      <?php endforeach; ?>
      <label>Reason <input name="reason" required maxlength="255"></label>
      <button class="btn btn-secondary btn-sm">Save limits</button>
    </form>
  </section>
  <?php endif; ?>
</div>

<section class="card">
  <h2>Restrictions</h2>
  <?php if (!$restrictions): ?><p class="empty">No active restrictions.</p><?php endif; ?>
  <?php foreach ($restrictions as $r): ?>
    <div class="list-row"><div><span class="badge badge-warning"><?= e($r['restriction']) ?></span> <?= e($r['reason']) ?>
      <div class="muted small">By <?= e($r['created_by_name']) ?> on <?= e(fmt_date($r['created_at'])) ?><?= $r['expires_at'] ? ' · expires ' . e(fmt_date($r['expires_at'], 'M j, Y')) : '' ?></div></div>
      <?php if (can('accounts.freeze')): ?>
      <form method="post" action="<?= e(url('admin/restrictions/' . $r['id'] . '/lift')) ?>" class="inline-form"><?= csrf_field() ?>
        <input name="reason" required placeholder="Reason to lift" maxlength="255"><button class="btn btn-ghost btn-sm">Lift</button></form>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  <?php if (can('accounts.freeze')): ?>
  <form method="post" action="<?= e(url('admin/accounts/' . $a['id'] . '/restrictions')) ?>" class="form inline-form">
    <?= csrf_field() ?>
    <label>Restrict <select name="restriction"><?php foreach (App\Services\AccountService::RESTRICTIONS as $r): ?><option><?= $r ?></option><?php endforeach; ?></select></label>
    <label>Reason <input name="reason" required maxlength="255"></label>
    <label>Expires (optional) <input type="date" name="expires_at"></label>
    <button class="btn btn-secondary btn-sm">Add restriction</button>
  </form>
  <?php endif; ?>
</section>
<?php endif; ?>

<section class="card">
  <h2>Ledger entries</h2>
  <div class="table-wrap"><table class="table small">
    <thead><tr><th>Date</th><th>Reference</th><th>Type</th><th>Description</th><th>Initiated by</th><th class="num">Debit</th><th class="num">Credit</th><th class="num">Balance after</th></tr></thead>
    <tbody><?php foreach ($entries as $en): ?>
      <tr><td class="nowrap"><?= e(fmt_date($en['created_at'])) ?></td>
        <td class="mono"><a href="<?= e(url('admin/transactions/' . $en['tx_id'])) ?>"><?= e($en['reference']) ?></a></td><td><?= e($en['type']) ?></td>
        <td><?= e($en['description']) ?></td><td><?= e($en['initiated_name'] ?? '—') ?></td>
        <td class="num neg"><?= $en['entry_type'] === 'debit' ? e(money((int) $en['amount'])) : '' ?></td>
        <td class="num pos"><?= $en['entry_type'] === 'credit' ? e(money((int) $en['amount'])) : '' ?></td>
        <td class="num"><?= e(money((int) $en['balance_after'])) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$entries): ?><tr><td colspan="8" class="empty">No ledger entries.</td></tr><?php endif; ?></tbody>
  </table></div>
  <?php include APP_PATH . '/views/partials/pager.php'; ?>
</section>

<section class="card"><h2>Audit history</h2><?php include APP_PATH . '/views/admin/_audit_rows.php'; ?></section>
