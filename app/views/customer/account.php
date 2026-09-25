<?php $a = $account; ?>
<div class="page-head">
  <div><a class="back" href="<?= e(url('accounts')) ?>">← Accounts</a>
    <h1><?= e($a['nickname'] ?: $a['type_name']) ?> <?= status_badge($a['status']) ?></h1>
    <p class="muted mono"><?= e($a['account_number']) ?> · <?= e($a['currency']) ?></p></div>
  <div class="actions no-print"><a class="btn btn-primary" href="<?= e(url('transfer?from=' . $a['id'])) ?>">Transfer</a></div>
</div>

<div class="stat-grid">
  <div class="stat stat-primary"><span>Balance</span><strong><?= e(money((int) $a['balance'], $a['currency'])) ?></strong></div>
  <div class="stat"><span>Available</span><strong><?= e(money(App\Services\AccountService::available($a), $a['currency'])) ?></strong></div>
  <div class="stat"><span>On hold</span><strong><?= e(money((int) $a['held_amount'], $a['currency'])) ?></strong></div>
  <div class="stat"><span>Daily transfer limit</span><strong><?= e(money($limits['daily_transfer'])) ?></strong><small>Withdrawals: <?= e(money($limits['daily_withdrawal'])) ?>/day</small></div>
</div>

<?php if ($restrictions): ?>
  <div class="alert alert-info">Some services on this account are restricted:
    <?= e(implode(', ', array_map(fn ($r) => $r['restriction'], $restrictions))) ?>. Please contact support for details.</div>
<?php endif; ?>

<section class="card">
  <div class="card-head"><h2>Transactions &amp; statement</h2>
    <button class="btn btn-ghost btn-sm no-print" type="button" data-print>Print</button></div>
  <form class="filters no-print" method="get">
    <label>From <input type="date" name="from" value="<?= e($filters['from'] ?? '') ?>"></label>
    <label>To <input type="date" name="to" value="<?= e($filters['to'] ?? '') ?>"></label>
    <label>Type <select name="type"><option value="">All</option>
      <?php foreach (['deposit', 'withdrawal', 'transfer', 'fee', 'adjustment'] as $t): ?><option value="<?= $t ?>" <?= ($filters['type'] ?? '') === $t ? 'selected' : '' ?>><?= ucfirst($t) ?></option><?php endforeach; ?>
    </select></label>
    <button class="btn btn-secondary btn-sm">Apply</button>
  </form>
  <?php if ($statement): ?>
    <div class="statement-head">
      <div><strong><?= e(bank_name()) ?></strong><br><?= e($a['customer_name']) ?><br><span class="mono"><?= e($a['account_number']) ?></span></div>
      <div>Period: <?= e(($filters['from'] ?? 'Opening') . ' — ' . ($filters['to'] ?? gmdate('Y-m-d'))) ?></div>
      <dl class="kv">
        <dt>Opening balance</dt><dd><?= e(money($statement['opening'])) ?></dd>
        <dt>Total credits</dt><dd class="pos">+<?= e(money($statement['credits'])) ?></dd>
        <dt>Total debits</dt><dd class="neg">−<?= e(money($statement['debits'])) ?></dd>
        <dt>Closing balance</dt><dd><strong><?= e(money($statement['closing'])) ?></strong></dd>
      </dl>
    </div>
  <?php endif; ?>
  <?php include APP_PATH . '/views/partials/entries.php'; ?>
  <?php include APP_PATH . '/views/partials/pager.php'; ?>
</section>
