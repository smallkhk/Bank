<?php $max = max(1, ...array_map(fn ($d) => max($d), array_values($series))); ?>
<div class="page-head"><h1>Overview</h1><p class="muted"><?= App\Services\StaffScope::seesAll() ? 'All customers' : 'Your assigned customers' ?></p></div>

<?php if ($breaks): ?>
  <div class="alert alert-error"><strong>Ledger reconciliation break:</strong> <?= count($breaks) ?> account(s) have a cached balance that differs from the ledger. Investigate immediately.
    <?php foreach ($breaks as $b): ?><div class="mono small"><?= e($b['account_number']) ?>: cached <?= e(money((int) $b['balance'])) ?> vs ledger <?= e(money((int) $b['ledger_balance'])) ?></div><?php endforeach; ?></div>
<?php endif; ?>

<div class="stat-grid">
  <div class="stat"><span>Customers</span><strong><?= number_format($stats['customers']) ?></strong><small><?= number_format($stats['active_customers']) ?> active · <?= number_format($stats['pending_customers']) ?> pending</small></div>
  <div class="stat"><span>Accounts</span><strong><?= number_format($stats['accounts']) ?></strong><small><?= number_format($stats['locked_accounts']) ?> locked/frozen</small></div>
  <div class="stat"><span>Customer deposits</span><strong><?= e(money($stats['deposits_total'])) ?></strong><small>Sum of balances</small></div>
  <div class="stat"><span>30-day volume</span><strong><?= e(money($stats['volume_30d'])) ?></strong><small>Completed transactions</small></div>
</div>
<div class="stat-grid">
  <a class="stat stat-link" href="<?= e(url('admin/withdrawals')) ?>"><span>Pending withdrawals</span><strong><?= $stats['pending_withdrawals'] ?></strong></a>
  <a class="stat stat-link" href="<?= e(url('admin/funds')) ?>"><span>Pending add-funds</span><strong><?= $stats['pending_funds'] ?></strong></a>
  <a class="stat stat-link" href="<?= e(url('admin/transactions?status=pending')) ?>"><span>Transfers awaiting approval</span><strong><?= $stats['pending_transfers'] ?></strong></a>
  <a class="stat stat-link" href="<?= e(url('admin/cards?tab=pending')) ?>"><span>Cards · pending issue</span><strong><?= $stats['active_cards'] ?> · <?= $stats['pending_cards'] ?></strong><small>active · pending</small></a>
  <div class="stat"><span>Failed logins (24h)</span><strong><?= $stats['failed_logins_24h'] ?></strong></div>
</div>

<section class="card">
  <div class="card-head"><h2>Transaction volume — last 14 days</h2>
    <div class="legend"><span class="lg lg-deposit">Deposits</span><span class="lg lg-transfer">Transfers</span><span class="lg lg-withdrawal">Withdrawals</span></div></div>
  <div class="bars" role="img" aria-label="Daily transaction volume chart">
    <?php foreach ($series as $day => $v): ?>
      <div class="bar-group" title="<?= e($day) ?> — deposits <?= e(money($v['deposit'])) ?>, transfers <?= e(money($v['transfer'])) ?>, withdrawals <?= e(money($v['withdrawal'])) ?>">
        <div class="bar-stack">
          <?php foreach (['deposit', 'transfer', 'withdrawal'] as $k): ?>
            <i class="bar bar-<?= $k ?>" style="height:<?= round($v[$k] / $max * 100, 1) ?>%"></i>
          <?php endforeach; ?>
        </div>
        <span><?= e(date('j', strtotime($day))) ?></span>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<div class="two-col">
  <section class="card">
    <div class="card-head"><h2>Recent activity</h2><?php if (can('audit.view')): ?><a href="<?= e(url('admin/audit')) ?>">Audit log</a><?php endif; ?></div>
    <?php foreach ($recent as $r): ?>
      <div class="list-row"><div><span class="mono small"><?= e($r['action']) ?></span> <span class="muted small">by <?= e($r['full_name'] ?? 'system') ?></span>
        <div class="muted small"><?= e(fmt_date($r['created_at'])) ?> · <?= e($r['ip_address']) ?></div></div></div>
    <?php endforeach; ?>
  </section>
  <section class="card">
    <h2>Security alerts (7 days)</h2>
    <?php if (!$alerts): ?><p class="empty">No alerts.</p><?php endif; ?>
    <?php foreach ($alerts as $r): ?>
      <div class="list-row"><div><span class="badge badge-danger"><?= e($r['action']) ?></span> <?= e($r['full_name'] ?? '') ?>
        <div class="muted small"><?= e(fmt_date($r['created_at'])) ?> · <?= e($r['ip_address']) ?> · <?= e(mb_strimwidth((string) $r['new_value'], 0, 80, '…')) ?></div></div></div>
    <?php endforeach; ?>
  </section>
</div>
