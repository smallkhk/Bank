<?php $first = explode(' ', App\Core\Auth::user()['full_name'])[0]; ?>
<div class="page-head">
  <div><h1>Welcome back, <?= e($first) ?></h1><p class="muted"><?= e(fmt_date(now(), 'l, F j, Y')) ?></p></div>
</div>

<div class="stat-grid">
  <div class="stat stat-primary"><span>Total balance</span><strong><?= e(money($total)) ?></strong><small><?= count($accounts) ?> account<?= count($accounts) === 1 ? '' : 's' ?></small></div>
  <div class="stat"><span>Available balance</span><strong><?= e(money($available)) ?></strong><small><?= $total !== $available ? e(money($total - $available)) . ' on hold' : 'Nothing on hold' ?></small></div>
  <div class="stat"><span>Pending withdrawals</span><strong><?= (int) $pendingWithdrawals ?></strong><small><a href="<?= e(url('withdrawals')) ?>">View requests</a></small></div>
</div>

<section class="quick-actions" aria-label="Quick actions">
  <a href="<?= e(url('transfer')) ?>" class="qa"><span class="qa-icon">⇄</span>Transfer</a>
  <a href="<?= e(url('add-funds')) ?>" class="qa"><span class="qa-icon">＋</span>Add funds</a>
  <a href="<?= e(url('withdrawals')) ?>" class="qa"><span class="qa-icon">↓</span>Withdraw</a>
  <a href="<?= e(url('transactions')) ?>" class="qa"><span class="qa-icon">≡</span>Statements</a>
</section>

<div class="two-col wide-right">
  <section class="card">
    <div class="card-head"><h2>Your accounts</h2><a href="<?= e(url('accounts')) ?>">View all</a></div>
    <?php if (!$accounts): ?>
      <p class="empty">You do not have any accounts yet. Your account manager will open one for you.</p>
    <?php endif; ?>
    <?php foreach ($accounts as $a): ?>
      <a class="account-row" href="<?= e(url('accounts/' . $a['id'])) ?>">
        <div><strong><?= e($a['nickname'] ?: $a['type_name']) ?></strong><div class="muted small mono"><?= e($a['account_number']) ?></div></div>
        <div class="right"><strong><?= (int) $a['credit_limit'] > 0 ? 'Owed ' . e(money(max(0, -(int) $a['balance']), $a['currency'])) : e(money((int) $a['balance'], $a['currency'])) ?></strong>
          <div class="small"><?= $a['status'] !== 'active' ? status_badge($a['status']) : '<span class="muted">Available ' . e(money(App\Services\AccountService::available($a), $a['currency'])) . '</span>' ?></div></div>
      </a>
    <?php endforeach; ?>
  </section>
  <section class="card">
    <div class="card-head"><h2>Recent transactions</h2><a href="<?= e(url('transactions')) ?>">View all</a></div>
    <?php $entries = $recent; $compact = true; include APP_PATH . '/views/partials/entries.php'; ?>
  </section>
</div>
