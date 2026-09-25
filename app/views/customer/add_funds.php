<div class="page-head"><h1>Add funds</h1></div>
<?php if ($cardTopUp && $accounts): ?>
<section class="card">
  <h2>Pay by debit or credit card</h2>
  <p class="muted">You'll be taken to our secure payment partner to enter your card details. Funds are added as soon as the payment is confirmed.</p>
  <form method="post" action="<?= e(url('add-funds/card')) ?>" class="form grid-3"><?= csrf_field() ?>
    <label>To account <select name="account_id"><?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>"><?= e(($a['nickname'] ?: $a['type_name']) . ' ' . mask_account($a['account_number'])) ?></option><?php endforeach; ?></select></label>
    <label>Amount <input name="amount" required inputmode="decimal" placeholder="0.00"><small class="muted"><?= e(money($cardLimits['min'])) ?> – <?= e(money($cardLimits['max'])) ?></small></label>
    <div class="align-end"><button class="btn btn-primary">Continue to payment</button></div>
  </form>
  <?php if ($cardPayments): ?>
    <h3 class="h3">Recent card payments</h3>
    <?php foreach ($cardPayments as $p): ?><div class="list-row"><div><strong><?= e(money((int) $p['amount'], $p['currency'])) ?></strong> <?= status_badge($p['status']) ?>
      <div class="muted small"><?= e($p['reference']) ?> · <?= e(mask_account($p['account_number'])) ?> · <?= e(fmt_date($p['created_at'])) ?></div></div></div><?php endforeach; ?>
  <?php endif; ?>
</section>
<?php endif; ?>
<?php if ($enabled || !$cardTopUp): ?>
<div class="two-col">
<section class="card">
  <?php if (!$enabled): ?>
    <p>To add funds to your account, please contact your account manager or the bank's support team.</p>
  <?php elseif (!$accounts): ?>
    <p class="empty">No accounts available.</p>
  <?php else: ?>
  <h2>Request to add funds</h2>
  <p class="muted">Submit a request and the bank will credit your account once the deposit is confirmed.</p>
  <form method="post" action="<?= e(url('add-funds')) ?>" class="form">
    <?= csrf_field() ?>
    <label>To account <select name="account_id"><?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>"><?= e(($a['nickname'] ?: $a['type_name']) . ' ' . mask_account($a['account_number'])) ?></option><?php endforeach; ?></select></label>
    <label>Amount <input name="amount" value="<?= e(old('amount')) ?>" required inputmode="decimal" placeholder="0.00"></label>
    <label>Note (optional) <input name="note" value="<?= e(old('note')) ?>" maxlength="200" placeholder="e.g. Cash deposit at branch"></label>
    <button class="btn btn-primary">Submit request</button>
  </form>
  <?php endif; ?>
</section>
<section class="card">
  <h2>Recent requests</h2>
  <?php if (!$requests): ?><p class="empty">No requests yet.</p><?php endif; ?>
  <?php foreach ($requests as $r): ?>
    <div class="list-row"><div><strong><?= e(money((int) $r['amount'], $r['currency'])) ?></strong> <?= status_badge($r['status']) ?>
      <div class="muted small"><?= e($r['reference']) ?> · <?= e(mask_account($r['account_number'])) ?> · <?= e(fmt_date($r['created_at'])) ?></div></div></div>
  <?php endforeach; ?>
</section>
</div>
<?php endif; ?>
