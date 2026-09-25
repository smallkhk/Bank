<?php use App\Services\CryptoService as C; $t = $portfolio['totals'];
$liveFlags = array_map(fn ($a) => C::isLive($a), $assets);
$live = $liveFlags && !in_array(false, $liveFlags, true);
$mixed = in_array(true, $liveFlags, true) && !$live; ?>
<div class="page-head"><h1>Crypto<?= $live || $mixed ? '' : ' <span class="badge badge-warning">Simulated prices</span>' ?></h1></div>
<?php include APP_PATH . '/views/partials/sim_banner.php'; ?>

<?php if (!$acknowledged): ?>
<section class="card narrow-wide">
  <h2>Before you start</h2>
  <p><?= nl2br(e(setting('crypto_risk_text'))) ?></p>
  <form method="post" action="<?= e(url('crypto/acknowledge')) ?>" class="form"><?= csrf_field() ?>
    <label class="check"><input type="checkbox" name="accept" value="1" required> I have read and understood this notice, and I understand that I can lose money.</label>
    <div><button class="btn btn-primary">Continue</button></div></form>
</section>
<?php endif; ?>

<div class="stat-grid">
  <div class="stat stat-primary"><span>Portfolio value</span><strong><?= e(money($t['value'])) ?></strong><small>At current <?= $live ? 'market ' : '' ?>prices</small></div>
  <div class="stat"><span>Unrealised gain/loss</span><strong class="<?= $t['unrealized'] >= 0 ? 'pos' : 'neg' ?>"><?= $t['unrealized'] >= 0 ? '+' : '' ?><?= e(money($t['unrealized'])) ?></strong><small>Cost <?= e(money($t['cost'])) ?></small></div>
  <div class="stat"><span>Realised gain/loss</span><strong class="<?= $t['realized'] >= 0 ? 'pos' : 'neg' ?>"><?= $t['realized'] >= 0 ? '+' : '' ?><?= e(money($t['realized'])) ?></strong><small>From completed sales, after fees</small></div>
</div>

<div class="two-col">
<section class="card">
  <h2>Market</h2>
  <?php if (!$assets): ?><p class="empty">No assets are available yet.</p><?php endif; ?>
  <?php foreach ($assets as $a): $ch = C::changeBps($a); ?>
    <a class="list-row link-row" href="<?= e(url('crypto/' . $a['symbol'])) ?>">
      <div class="asset-id"><span class="asset-icon"><?= e(substr($a['symbol'], 0, 1)) ?></span><div><strong><?= e($a['symbol']) ?></strong><div class="muted small"><?= e($a['name']) ?></div></div></div>
      <div class="right"><strong><?= C::isLive($a) && !C::priceIsStale($a) ? '<span class="live-dot" title="Live market price"></span> ' : '' ?><?= e(money((int) $a['price'])) ?></strong>
        <div class="small <?= $ch >= 0 ? 'pos' : 'neg' ?>"><?= $ch >= 0 ? '▲' : '▼' ?> <?= number_format(abs($ch) / 100, 2) ?>%<?= $a['status'] === 'halted' ? ' · <span class="badge badge-warning">Halted</span>' : '' ?><?= !C::isLive($a) && !$live && $mixed ? ' · <span class="badge badge-muted">Simulated price</span>' : '' ?></div></div>
    </a>
  <?php endforeach; ?>
</section>
<section class="card">
  <h2>Your holdings</h2>
  <?php if (!$portfolio['rows']): ?><p class="empty">You don't hold any crypto yet.</p><?php else: ?>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Asset</th><th class="num">Quantity</th><th class="num">Value</th><th class="num hide-sm">Avg. cost</th><th class="num">Gain/loss</th></tr></thead>
    <tbody><?php foreach ($portfolio['rows'] as $r): ?>
      <tr><td><a href="<?= e(url('crypto/' . $r['symbol'])) ?>"><strong><?= e($r['symbol']) ?></strong></a></td>
        <td class="num mono"><?= e(C::formatQuantity((int) $r['quantity'], (int) $r['decimals'])) ?></td>
        <td class="num"><?= e(money($r['value'])) ?></td>
        <td class="num hide-sm"><?= (int) $r['quantity'] ? e(money($r['avg_cost'])) : '—' ?></td>
        <td class="num <?= $r['unrealized'] >= 0 ? 'pos' : 'neg' ?>"><?= $r['unrealized'] >= 0 ? '+' : '' ?><?= e(money($r['unrealized'])) ?><div class="small"><?= number_format($r['pnl_bps'] / 100, 2) ?>%</div></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>
</section>
</div>

<?php if ($recent): ?>
<section class="card">
  <h2>Recent trades</h2>
  <?php include APP_PATH . '/views/partials/crypto_trades.php'; ?>
</section>
<?php endif; ?>
