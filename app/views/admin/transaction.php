<div class="page-head"><div><a class="back" href="<?= e(url('admin/transactions')) ?>">← Transactions</a>
  <h1 class="mono"><?= e($tx['reference']) ?> <?= status_badge($tx['status']) ?></h1></div></div>
<div class="two-col">
<section class="card">
  <dl class="kv">
    <dt>Type</dt><dd><?= e($tx['type']) ?></dd>
    <dt>Amount</dt><dd><strong><?= e(money((int) $tx['amount'], $tx['currency'])) ?></strong><?= (int) $tx['fee_amount'] ? ' + fee ' . e(money((int) $tx['fee_amount'])) : '' ?></dd>
    <dt>From</dt><dd class="mono"><?= $tx['from_account_id'] ? '<a href="' . e(url('admin/accounts/' . $tx['from_account_id'])) . '">' . e($tx['from_number']) . '</a>' : '—' ?></dd>
    <dt>To</dt><dd class="mono"><?= $tx['to_account_id'] ? '<a href="' . e(url('admin/accounts/' . $tx['to_account_id'])) . '">' . e($tx['to_number']) . '</a>' : '—' ?></dd>
    <dt>Description</dt><dd><?= e($tx['description'] ?? '—') ?></dd>
    <dt>Customer reference</dt><dd><?= e($tx['customer_reference'] ?? '—') ?></dd>
    <dt>Initiated by</dt><dd><?= e($tx['initiated_name'] ?? '—') ?></dd>
    <dt>Approved by</dt><dd><?= e($tx['approved_name'] ?? '—') ?></dd>
    <dt>Created</dt><dd><?= e(fmt_date($tx['created_at'])) ?></dd>
    <dt>Completed</dt><dd><?= e(fmt_date($tx['completed_at'])) ?></dd>
    <dt>IP address</dt><dd class="mono"><?= e($tx['ip_address'] ?? '—') ?></dd>
  </dl>
</section>
<section class="card">
  <?php if ($tx['status'] === 'pending' && $tx['type'] === 'transfer' && can('transactions.approve')): ?>
    <h2>Approval required</h2>
    <p class="muted">Funds of <?= e(money((int) $tx['amount'] + (int) $tx['fee_amount'])) ?> are on hold on the sender's account.</p>
    <form method="post" action="<?= e(url('admin/transactions/' . $tx['id'] . '/approve')) ?>" class="form"><?= csrf_field() ?>
      <label>Note <input name="note" maxlength="255"></label><button class="btn btn-primary" data-confirm="Approve and post this transfer?">Approve transfer</button></form>
    <form method="post" action="<?= e(url('admin/transactions/' . $tx['id'] . '/reject')) ?>" class="form"><?= csrf_field() ?>
      <label>Rejection reason <input name="note" required maxlength="255"></label><button class="btn btn-ghost">Reject</button></form>
  <?php endif; ?>
  <?php if ($related): ?><h2>Related</h2>
    <?php foreach ($related as $r): if ((int) $r['id'] === (int) $tx['id']) continue; ?>
      <div class="list-row"><a class="mono" href="<?= e(url('admin/transactions/' . $r['id'])) ?>"><?= e($r['reference']) ?></a> <?= e($r['type']) ?> <?= e(money((int) $r['amount'])) ?></div>
    <?php endforeach; ?>
  <?php endif; ?>
  <?php if ($approvals): ?><h2>Decisions</h2>
    <?php foreach ($approvals as $ap): ?><div class="list-row"><div><?= status_badge($ap['decision']) ?> by <?= e($ap['full_name']) ?> <span class="muted small"><?= e(fmt_date($ap['created_at'])) ?></span><div class="small"><?= e($ap['note'] ?? '') ?></div></div></div><?php endforeach; ?>
  <?php endif; ?>
</section>
</div>
<section class="card">
  <h2>Ledger entries</h2>
  <?php if (!$entries): ?><p class="empty">No ledger entries (the transaction has not been posted).</p><?php else: ?>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Account</th><th>Entry</th><th class="num">Amount</th><th class="num">Balance before</th><th class="num">Balance after</th></tr></thead>
    <tbody><?php foreach ($entries as $en): ?>
      <tr><td class="mono"><?= e($en['account_number']) ?></td><td><?= e($en['entry_type']) ?></td><td class="num"><?= e(money((int) $en['amount'])) ?></td>
        <td class="num"><?= e(money((int) $en['balance_before'])) ?></td><td class="num"><?= e(money((int) $en['balance_after'])) ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>
</section>
