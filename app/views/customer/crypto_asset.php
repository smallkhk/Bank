<?php
use App\Services\CryptoService as C;
$ch = C::changeBps($asset);
$held = (int) ($holding['quantity'] ?? 0);
$tradable = $asset['status'] === 'active' && $acknowledged;
// Sparkline from recorded prices
$pts = array_map(fn ($h) => (int) $h['price'], $history);
$spark = '';
if (count($pts) > 1) {
    [$min, $max] = [min($pts), max($pts)];
    $range = max(1, $max - $min);
    $n = count($pts) - 1;
    $spark = implode(' ', array_map(fn ($i, $p) => round($i / $n * 600, 1) . ',' . round(150 - ($p - $min) / $range * 140 - 5, 1), array_keys($pts), $pts));
}
?>
<div class="page-head"><div><a class="back" href="<?= e(url('crypto')) ?>">← Crypto</a>
  <h1><?= e($asset['name']) ?> <span class="muted"><?= e($asset['symbol']) ?></span> <?= C::isLive($asset) ? '' : '<span class="badge badge-warning">Simulated price</span>' ?></h1></div></div>
<?php $live = C::isLive($asset); $mixed = false; include APP_PATH . '/views/partials/sim_banner.php'; ?>

<div class="two-col wide-left">
<section class="card">
  <div class="price-head"><strong class="price-big"><?= e(money((int) $asset['price'])) ?></strong>
    <span class="<?= $ch >= 0 ? 'pos' : 'neg' ?>"><?= $ch >= 0 ? '▲' : '▼' ?> <?= number_format(abs($ch) / 100, 2) ?>% (24h)</span>
    <span class="muted small"><?= C::isLive($asset) && !C::priceIsStale($asset) ? '<span class="live-dot"></span> Live market price · ' : '' ?>Updated <?= e(fmt_date($asset['price_updated_at'])) ?></span></div>
  <?php if (C::priceIsStale($asset)): ?><div class="alert alert-info">Live prices are temporarily unavailable, so trading in <?= e($asset['symbol']) ?> is paused.</div><?php endif; ?>
  <?php if ($spark): ?>
    <svg class="spark <?= $pts[count($pts) - 1] >= $pts[0] ? 'up' : 'down' ?>" viewBox="0 0 600 150" preserveAspectRatio="none" role="img" aria-label="Recent price history">
      <polyline points="<?= e($spark) ?>" fill="none" stroke-width="2.5" vector-effect="non-scaling-stroke"/></svg>
    <p class="muted small">Last <?= count($pts) ?> price updates · low <?= e(money(min($pts))) ?> · high <?= e(money(max($pts))) ?></p>
  <?php endif; ?>
  <dl class="kv">
    <dt>You hold</dt><dd class="mono"><?= e(C::formatQuantity($held, (int) $asset['decimals'])) ?> <?= e($asset['symbol']) ?> (<?= e(money(C::valueOf($held, $asset))) ?>)</dd>
    <?php if ($held): ?><dt>Average cost</dt><dd><?= e(money(C::mulDiv((int) $holding['cost_basis'], C::scale($asset), $held))) ?> per <?= e($asset['symbol']) ?></dd><?php endif; ?>
    <dt>Trading fee</dt><dd><?= number_format((int) $asset['trade_fee_bps'] / 100, 2) ?>%</dd>
    <dt>Minimum trade</dt><dd><?= e(money((int) $asset['min_trade'])) ?></dd>
  </dl>
</section>

<section class="card">
  <?php if ($asset['status'] === 'halted'): ?><div class="alert alert-info">Trading in <?= e($asset['symbol']) ?> is temporarily halted.</div>
  <?php elseif (!$acknowledged): ?><div class="alert alert-info">Please <a href="<?= e(url('crypto')) ?>">read and accept the notice</a> before trading.</div>
  <?php elseif (!$accounts): ?><p class="empty">You need an active <?= e(setting('currency')) ?> account to trade.</p>
  <?php else: ?>
  <nav class="tabs seg" data-seg><a href="#" class="active" data-side="buy">Buy</a><a href="#" data-side="sell">Sell</a></nav>
  <form method="post" action="<?= e(url('crypto/' . $asset['symbol'])) ?>" class="form" data-trade-form data-quote="<?= e(url('crypto/' . $asset['symbol'] . '/quote')) ?>">
    <?= csrf_field() ?><input type="hidden" name="side" value="buy"><input type="hidden" name="expected_price" value="">
    <label><span data-when="buy">Pay from</span><span data-when="sell" hidden>Credit to</span>
      <select name="account_id"><?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>"><?= e(($a['nickname'] ?: $a['type_name']) . ' ' . mask_account($a['account_number'])) ?> — <?= e(money(App\Services\AccountService::available($a))) ?></option><?php endforeach; ?></select></label>
    <label data-when="buy">Amount to spend (<?= e(setting('currency')) ?>) <input name="amount" inputmode="decimal" placeholder="0.00"><small class="muted">The fee is added on top.</small></label>
    <label data-when="sell" hidden>Quantity of <?= e($asset['symbol']) ?> <input name="quantity" inputmode="decimal" placeholder="0.<?= str_repeat('0', max(0, (int) $asset['decimals'] - 1)) ?>1">
      <small class="muted">You hold <?= e(C::formatQuantity($held, (int) $asset['decimals'])) ?>.
      <?php if ($held): ?><label class="check inline-check"><input type="checkbox" name="sell_all" value="1"> Sell all</label><?php endif; ?></small></label>
    <div class="quote" data-quote-box hidden></div>
    <div class="actions-inline"><button type="button" class="btn btn-secondary" data-review>Review order</button>
      <button class="btn btn-primary" data-confirm-btn hidden>Confirm</button></div>
    <noscript><button class="btn btn-primary">Place order at current price</button></noscript>
  </form>
  <?php endif; ?>
</section>
</div>

<?php if ($trades): ?><section class="card"><h2>Your <?= e($asset['symbol']) ?> trades</h2><?php $recent = null; include APP_PATH . '/views/partials/crypto_trades.php'; ?></section><?php endif; ?>
