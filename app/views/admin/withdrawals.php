<div class="page-head"><h1>Withdrawals</h1></div>
<nav class="tabs"><?php foreach ($statuses as $s): ?><a href="?status=<?= $s ?>" class="<?= $status === $s ? 'active' : '' ?>"><?= ucfirst($s) ?></a><?php endforeach; ?></nav>
<section class="card">
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Requested</th><th>Reference</th><th>Account</th><th class="num">Amount</th><th>Method / details</th><th>Requested by</th><?= $status === 'pending' ? '<th>Decision</th>' : '<th>Reviewed by</th>' ?></tr></thead>
    <tbody><?php foreach ($rows as $r): ?>
      <tr><td class="nowrap"><?= e(fmt_date($r['created_at'])) ?></td><td class="mono"><?= e($r['reference']) ?></td>
        <td><a class="mono" href="<?= e(url('admin/accounts/' . $r['account_id'])) ?>"><?= e($r['account_number']) ?></a><div class="muted small"><?= e($r['customer_name']) ?> · bal <?= e(money((int) $r['balance'])) ?></div></td>
        <td class="num"><?= e(money((int) $r['amount'], $r['currency'])) ?></td>
        <td><?= e($r['method']) ?><div class="muted small"><?= e($r['details']) ?></div></td><td><?= e($r['requested_name']) ?></td>
        <?php if ($status === 'pending'): ?>
          <td><?php if (can('funds.approve')): ?>
            <form method="post" action="<?= e(url('admin/withdrawals/' . $r['id'] . '/approve')) ?>" class="inline-form"><?= csrf_field() ?><input name="note" placeholder="Note" maxlength="255"><button class="btn btn-primary btn-sm" data-confirm="Approve and pay out <?= e(money((int) $r['amount'])) ?>?">Approve</button></form>
            <form method="post" action="<?= e(url('admin/withdrawals/' . $r['id'] . '/reject')) ?>" class="inline-form"><?= csrf_field() ?><input name="note" placeholder="Rejection reason" required maxlength="255"><button class="btn btn-ghost btn-sm">Reject</button></form>
          <?php else: ?><span class="muted small">Awaiting approver</span><?php endif; ?></td>
        <?php else: ?><td><?= e($r['reviewed_name'] ?? '—') ?><div class="muted small"><?= e($r['review_note'] ?? '') ?></div></td><?php endif; ?>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="7" class="empty">Nothing here.</td></tr><?php endif; ?></tbody>
  </table></div>
</section>
