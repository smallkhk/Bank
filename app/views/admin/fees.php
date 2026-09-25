<div class="page-head"><h1>Fees</h1><p class="muted">Every fee is a ledger transaction credited to the internal fee income account.</p></div>
<div class="stat-grid">
  <div class="stat stat-primary"><span>Fee income (all time)</span><strong><?= e(money($income)) ?></strong><small>SYS-FEES balance</small></div>
  <div class="stat"><span>Transfer fee</span><strong class="small-strong"><?= e(money((int) setting('transfer_fee_fixed'))) ?> + <?= number_format((int) setting('transfer_fee_bps') / 100, 2) ?>%</strong></div>
  <div class="stat"><span>Withdrawal fee</span><strong class="small-strong"><?= e(money((int) setting('withdrawal_fee_fixed'))) ?></strong></div>
  <div class="stat"><span>Default monthly fee</span><strong class="small-strong"><?= e(money((int) setting('monthly_account_fee'))) ?></strong><small><a href="<?= e(url('admin/account-types')) ?>">Per account type</a></small></div>
</div>
<div class="two-col">
<section class="card">
  <div class="card-head"><h2>Fees report</h2></div>
  <form class="filters" method="get"><label>From <input type="date" name="from" value="<?= e($from) ?>"></label><label>To <input type="date" name="to" value="<?= e($to) ?>"></label><button class="btn btn-secondary btn-sm">Apply</button></form>
  <h3 class="h3">By type</h3>
  <div class="table-wrap"><table class="table"><thead><tr><th>Fee</th><th class="num">Count</th><th class="num">Total</th></tr></thead><tbody>
    <?php foreach ($byKind as $r): ?><tr><td><?= e($r['kind']) ?></td><td class="num"><?= (int) $r['n'] ?></td><td class="num"><?= e(money((int) $r['total'])) ?></td></tr><?php endforeach; ?>
    <?php if (!$byKind): ?><tr><td colspan="3" class="empty">No fees in this period.</td></tr><?php endif; ?></tbody></table></div>
  <h3 class="h3">By month</h3>
  <div class="table-wrap"><table class="table"><thead><tr><th>Month</th><th class="num">Count</th><th class="num">Total</th></tr></thead><tbody>
    <?php foreach ($byMonth as $r): ?><tr><td><?= e($r['month']) ?></td><td class="num"><?= (int) $r['n'] ?></td><td class="num"><?= e(money((int) $r['total'])) ?></td></tr><?php endforeach; ?></tbody></table></div>
</section>
<section class="card">
  <h2>Monthly account fees</h2>
  <p class="muted small">Normally run automatically by the cron job <code>cron/monthly-fees.php</code> on the 1st of each month. Running it again for the same month never double-charges.</p>
  <?php if (can('settings.manage')): ?>
  <form method="post" action="<?= e(url('admin/fees/run')) ?>" class="inline-form"><?= csrf_field() ?>
    <label>Month <input type="month" name="period" value="<?= gmdate('Y-m', strtotime('first day of last month')) ?>" max="<?= gmdate('Y-m') ?>" required></label>
    <button class="btn btn-primary btn-sm" data-confirm="Charge monthly fees for this month now?">Run now</button></form>
  <?php endif; ?>
  <div class="table-wrap"><table class="table"><thead><tr><th>Period</th><th class="num">Charged</th><th class="num">Skipped</th><th class="num">Total</th><th>Ran</th></tr></thead><tbody>
    <?php foreach ($runs as $r): ?><tr><td><?= e($r['period']) ?></td><td class="num"><?= (int) $r['charged'] ?></td><td class="num"><?= (int) $r['skipped'] ?></td><td class="num"><?= e(money((int) $r['total'])) ?></td><td><?= e(fmt_date($r['ran_at'])) ?></td></tr><?php endforeach; ?>
    <?php if (!$runs): ?><tr><td colspan="5" class="empty">No runs yet.</td></tr><?php endif; ?></tbody></table></div>
</section>
</div>
