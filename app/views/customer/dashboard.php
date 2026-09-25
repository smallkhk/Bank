<?php
use App\Services\AccountService;
$first = explode(' ', trim(App\Core\Auth::user()['full_name']))[0];
// Balance sparkline geometry (viewBox 0 0 300 64)
$vals = array_values($history);
[$lo, $hi] = [min($vals), max($vals)];
$span = max(1, $hi - $lo);
$pts = [];
foreach ($vals as $i => $v) {
    $pts[] = [round($i / (count($vals) - 1) * 300, 1), round(58 - ($v - $lo) / $span * 50, 1)];
}
$line = 'M' . implode(' L', array_map(fn ($p) => $p[0] . ',' . $p[1], $pts));
$area = $line . ' L300,64 L0,64 Z';
$last = end($pts);
$change = $vals[count($vals) - 1] - $vals[0];
?>
<div class="page-head">
  <div><div class="eyebrow"><?= e(fmt_date(now(), 'l, F j')) ?></div>
    <h1><?= e(greeting()) ?>, <?= e($first) ?></h1></div>
</div>

<div class="hero-grid">
  <section class="balance-hero" aria-label="Balance summary">
    <div class="bh-top"><span>Total balance · <?= count(array_filter($accounts, fn ($a) => (int) $a['credit_limit'] === 0)) ?> account<?= count($accounts) === 1 ? '' : 's' ?></span>
      <button type="button" class="icon-btn" data-privacy-toggle aria-label="Hide or show balances" title="Hide / show balances"><?= icon('eye') ?></button></div>
    <div class="bh-amount" data-private data-countup="<?= (int) $total ?>"><?= e(money($total)) ?></div>
    <div class="bh-sub">
      <span>Available <strong data-private><?= e(money($available)) ?></strong></span>
      <?php if ($total !== $available): ?><span>On hold <strong data-private><?= e(money($total - $available)) ?></strong></span><?php endif; ?>
      <span>30 days <strong data-private><?= $change >= 0 ? '+' : '−' ?><?= e(money(abs($change))) ?></strong></span>
    </div>
    <svg class="bh-spark" viewBox="0 0 300 64" preserveAspectRatio="none" role="img" aria-label="Balance over the last 30 days" data-private>
      <defs><linearGradient id="bhGrad" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#fff" stop-opacity=".35"/><stop offset="1" stop-color="#fff" stop-opacity="0"/></linearGradient></defs>
      <path class="area" d="<?= e($area) ?>"/><path class="line" d="<?= e($line) ?>"/>
      <circle cx="<?= $last[0] ?>" cy="<?= $last[1] ?>" r="3.5"/>
    </svg>
    <div class="bh-foot"><span><?= e(fmt_date(array_key_first($history), 'M j')) ?></span><span>Today</span></div>
  </section>

  <div class="side-stack">
    <div class="mini-stat"><span class="ico" style="background:var(--pos-soft);color:var(--pos)"><?= icon('in', 20) ?></span>
      <div><span>Money in · 30 days</span><strong data-private><?= e(money($monthIn)) ?></strong></div></div>
    <div class="mini-stat"><span class="ico" style="background:var(--neg-soft);color:var(--neg)"><?= icon('out', 20) ?></span>
      <div><span>Money out · 30 days</span><strong data-private><?= e(money($monthOut)) ?></strong></div></div>
    <?php if (setting('withdrawals_enabled') === '1'): ?>
      <a class="mini-stat" href="<?= e(url('withdrawals')) ?>"><span class="ico"><?= icon('clock', 20) ?></span>
        <div><span>Pending withdrawals</span><strong><?= (int) $pendingWithdrawals ?></strong></div></a>
    <?php elseif (setting('cards_enabled') === '1'): ?>
      <a class="mini-stat" href="<?= e(url('cards')) ?>"><span class="ico"><?= icon('card', 20) ?></span><div><span>Active cards</span><strong><?= (int) $cardCount ?></strong></div></a>
    <?php endif; ?>
  </div>
</div>

<section class="quick-actions" aria-label="Quick actions">
  <?php if (setting('transfers_enabled') === '1'): ?><a href="<?= e(url('transfer')) ?>" class="qa"><span class="qa-icon"><?= icon('transfer', 22) ?></span>Transfer</a><?php endif; ?>
  <?php if (setting('customer_add_funds_requests') === '1' || App\Services\GatewayPaymentService::available()): ?><a href="<?= e(url('add-funds')) ?>" class="qa"><span class="qa-icon"><?= icon('plus', 22) ?></span>Add funds</a><?php endif; ?>
  <?php if (setting('withdrawals_enabled') === '1'): ?><a href="<?= e(url('withdrawals')) ?>" class="qa"><span class="qa-icon"><?= icon('withdraw', 22) ?></span>Withdraw</a><?php endif; ?>
  <?php if (setting('cards_enabled') === '1'): ?><a href="<?= e(url('cards')) ?>" class="qa"><span class="qa-icon"><?= icon('card', 22) ?></span>Cards</a><?php endif; ?>
  <a href="<?= e(url('transactions')) ?>" class="qa"><span class="qa-icon"><?= icon('file', 22) ?></span>Statements</a>
</section>

<div class="two-col wide-right">
  <section class="card">
    <div class="card-head"><h2>Your accounts</h2><a href="<?= e(url('accounts')) ?>">View all</a></div>
    <?php if (!$accounts): ?>
      <p class="empty">You do not have any accounts yet. Your account manager will open one for you.</p>
    <?php endif; ?>
    <?php foreach ($accounts as $a): ?>
      <a class="account-row" href="<?= e(url('accounts/' . $a['id'])) ?>">
        <div class="tx-cell"><span class="tx-ico"><?= icon((int) $a['credit_limit'] > 0 ? 'card' : 'wallet') ?></span>
          <div><strong><?= e($a['nickname'] ?: $a['type_name']) ?></strong><div class="muted small mono"><?= e($a['account_number']) ?></div></div></div>
        <div class="right"><strong data-private><?= (int) $a['credit_limit'] > 0 ? 'Owed ' . e(money(max(0, -(int) $a['balance']), $a['currency'])) : e(money((int) $a['balance'], $a['currency'])) ?></strong>
          <div class="small"><?= $a['status'] !== 'active' ? status_badge($a['status']) : '<span class="muted" data-private>Available ' . e(money(AccountService::available($a), $a['currency'])) . '</span>' ?></div></div>
      </a>
    <?php endforeach; ?>
  </section>
  <section class="card">
    <div class="card-head"><h2>Recent activity</h2><a href="<?= e(url('transactions')) ?>">View all</a></div>
    <?php $entries = $recent; $compact = true; include APP_PATH . '/views/partials/entries.php'; ?>
  </section>
</div>
