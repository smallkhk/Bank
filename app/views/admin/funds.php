<div class="page-head"><h1>Add funds &amp; adjustments</h1></div>
<nav class="tabs"><?php foreach (['pending', 'approved', 'rejected'] as $s): ?><a href="?status=<?= $s ?>" class="<?= $status === $s ? 'active' : '' ?>"><?= ucfirst($s) ?></a><?php endforeach; ?></nav>
<section class="card">
  <p class="muted small">Funds are only credited after approval, which posts a balanced ledger entry. The approver must be a different person from the requester.</p>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Requested</th><th>Reference</th><th>Account</th><th>Type</th><th class="num">Amount</th><th>Reason</th><th>Requested by</th><?= $status === 'pending' ? '<th>Decision</th>' : '<th>Reviewed by</th>' ?></tr></thead>
    <tbody><?php foreach ($rows as $r): ?>
      <tr><td class="nowrap"><?= e(fmt_date($r['created_at'])) ?></td><td class="mono"><?= e($r['reference']) ?></td>
        <td><a class="mono" href="<?= e(url('admin/accounts/' . $r['account_id'])) ?>"><?= e($r['account_number']) ?></a><div class="muted small"><?= e($r['customer_name']) ?></div></td>
        <td><?= e(str_replace('_', ' ', $r['kind'])) ?></td><td class="num"><?= e(money((int) $r['amount'], $r['currency'])) ?></td>
        <td><?= e($r['reason']) ?><?= $r['external_reference'] ? '<div class="muted small">Ext: ' . e($r['external_reference']) . '</div>' : '' ?></td>
        <td><?= e($r['requested_name']) ?></td>
        <?php if ($status === 'pending'): ?>
          <td><?php if (can('funds.approve')): ?>
            <form method="post" action="<?= e(url('admin/funds/' . $r['id'] . '/approve')) ?>" class="inline-form"><?= csrf_field() ?><input name="note" placeholder="Note" maxlength="255"><button class="btn btn-primary btn-sm" data-confirm="Approve and post <?= e(money((int) $r['amount'])) ?>?">Approve</button></form>
            <form method="post" action="<?= e(url('admin/funds/' . $r['id'] . '/reject')) ?>" class="inline-form"><?= csrf_field() ?><input name="note" placeholder="Rejection reason" required maxlength="255"><button class="btn btn-ghost btn-sm">Reject</button></form>
          <?php else: ?><span class="muted small">Awaiting approver</span><?php endif; ?></td>
        <?php else: ?><td><?= e($r['reviewed_name'] ?? '—') ?><div class="muted small"><?= e($r['review_note'] ?? '') ?></div></td><?php endif; ?>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="8" class="empty">Nothing here.</td></tr><?php endif; ?></tbody>
  </table></div>
</section>
