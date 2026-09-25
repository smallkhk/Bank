<div class="page-head"><h1>Withdrawals</h1></div>
<div class="two-col">
<section class="card">
  <h2>Request a withdrawal</h2>
  <?php if (!$accounts): ?><p class="empty">No accounts available.</p><?php else: ?>
  <form method="post" action="<?= e(url('withdrawals')) ?>" class="form">
    <?= csrf_field() ?>
    <label>From account <select name="account_id" required>
      <?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>" <?= old('account_id') === (string) $a['id'] ? 'selected' : '' ?>><?= e(($a['nickname'] ?: $a['type_name']) . ' ' . mask_account($a['account_number'])) ?> — available <?= e(money(App\Services\AccountService::available($a))) ?></option><?php endforeach; ?>
    </select></label>
    <label>Amount <input name="amount" value="<?= e(old('amount')) ?>" required inputmode="decimal" placeholder="0.00"></label>
    <label>Method <select name="method"><?php foreach (['Bank transfer', 'Cash pickup', 'Cheque'] as $m): ?><option <?= old('method') === $m ? 'selected' : '' ?>><?= $m ?></option><?php endforeach; ?></select></label>
    <label>Payout details <textarea name="details" rows="3" maxlength="500" placeholder="Destination details for the bank to process"><?= e(old('details')) ?></textarea></label>
    <?php if ($fee > 0): ?><p class="muted small">A withdrawal fee of <?= e(money($fee)) ?> applies.</p><?php endif; ?>
    <button class="btn btn-primary">Submit request</button>
  </form>
  <p class="muted small">The amount is placed on hold immediately and released to you if the request is rejected or cancelled.</p>
  <?php endif; ?>
</section>
<section class="card">
  <h2>Your requests</h2>
  <?php if (!$requests): ?><p class="empty">No withdrawal requests.</p><?php endif; ?>
  <?php foreach ($requests as $r): ?>
    <div class="list-row">
      <div><strong><?= e(money((int) $r['amount'], $r['currency'])) ?></strong> <?= status_badge($r['status']) ?>
        <div class="muted small"><?= e($r['reference']) ?> · <?= e(mask_account($r['account_number'])) ?> · <?= e(fmt_date($r['created_at'])) ?></div>
        <?php if ($r['review_note'] && $r['status'] === 'rejected'): ?><div class="small">Note: <?= e($r['review_note']) ?></div><?php endif; ?></div>
      <?php if ($r['status'] === 'pending'): ?>
        <form method="post" action="<?= e(url('withdrawals/' . $r['id'] . '/cancel')) ?>"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" data-confirm="Cancel this withdrawal request?">Cancel</button></form>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</section>
</div>
