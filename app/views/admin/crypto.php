<?php
use App\Services\CryptoService as C;
$e0 = $edit ?? ['id' => '', 'symbol' => '', 'name' => '', 'feed_id' => '', 'decimals' => 8, 'price' => 0, 'trade_fee_bps' => 100, 'min_trade' => 100, 'volatility_bps' => 0, 'status' => 'active', 'sort_order' => 0];
?>
<div class="page-head"><h1>Crypto</h1>
  <div class="actions-inline"><a class="btn btn-secondary" href="<?= e(url('admin/crypto/trades')) ?>">Trades</a>
  <?php if (can('crypto.manage') && $feedOn): ?><form method="post" action="<?= e(url('admin/crypto/refresh')) ?>"><?= csrf_field() ?><button class="btn btn-primary">Update live prices now</button></form><?php endif; ?>
  <?php if (can('crypto.manage')): ?><form method="post" action="<?= e(url('admin/crypto/simulate')) ?>"><?= csrf_field() ?><button class="btn btn-ghost" title="Random-walk step for assets with volatility set">Simulate price move</button></form><?php endif; ?></div></div>
<p class="muted small">Customers hold cash-settled positions with the bank as counterparty. Assets linked to a live price feed are shown to customers at market price; others are labelled "Simulated price".</p>
<?php if (setting('crypto_enabled') !== '1'): ?><div class="alert alert-info">The crypto module is <strong>disabled</strong> for customers. Enable it in Settings → Crypto.</div><?php endif; ?>
<?php if ($breaks): ?><div class="alert alert-error"><strong>Holdings reconciliation break:</strong> <?php foreach ($breaks as $b): ?><div class="mono small">Customer <?= (int) $b['customer_id'] ?> <?= e($b['symbol']) ?>: holding <?= (int) $b['quantity'] ?> vs trades <?= (int) $b['traded'] ?></div><?php endforeach; ?></div><?php endif; ?>

<div class="stat-grid">
  <div class="stat"><span>Customer exposure</span><strong><?= e(money(array_sum(array_column($assets, 'exposure')))) ?></strong><small>Value of all customer holdings</small></div>
  <div class="stat"><span>Trading desk (SYS-CRYPTO)</span><strong><?= e(money($desk)) ?></strong><small>Net cash received from trades</small></div>
  <div class="stat"><span>30-day volume</span><strong><?= e(money((int) $volume['gross'])) ?></strong><small><?= (int) $volume['n'] ?> trades · fees <?= e(money((int) $volume['fees'])) ?></small></div>
</div>

<section class="card"><div class="table-wrap"><table class="table">
  <thead><tr><th>Asset</th><th class="num">Price</th><th class="num">24h</th><th class="num">Fee</th><th class="num">Held by customers</th><th>Status</th><?= can('crypto.manage') ? '<th>Set price</th><th></th>' : '' ?></tr></thead>
  <tbody><?php foreach ($assets as $a): $ch = C::changeBps($a); ?>
    <tr><td><strong><?= e($a['symbol']) ?></strong> <span class="muted"><?= e($a['name']) ?></span><div class="muted small"><?= (int) $a['decimals'] ?> decimals<?= $a['feed_id'] ? ' · <span class="badge badge-' . ($feedOn ? 'success' : 'muted') . '">Live: ' . e($a['feed_id']) . '</span>' : ((int) $a['volatility_bps'] ? ' · simulator ±' . number_format($a['volatility_bps'] / 100, 2) . '%' : '') ?>
        <?= C::priceIsStale($a) ? ' <span class="badge badge-danger">Price stale — trading paused</span>' : '' ?></div></td>
      <td class="num"><?= e(money((int) $a['price'])) ?><div class="muted small"><?= e(fmt_date($a['price_updated_at'], 'M j, g:i A')) ?></div></td>
      <td class="num <?= $ch >= 0 ? 'pos' : 'neg' ?>"><?= number_format($ch / 100, 2) ?>%</td><td class="num"><?= number_format($a['trade_fee_bps'] / 100, 2) ?>%</td>
      <td class="num mono"><?= e(C::formatQuantity((int) $a['held'], (int) $a['decimals'])) ?><div class="muted small"><?= (int) $a['holders'] ?> holders · <?= e(money($a['exposure'])) ?></div></td>
      <td><?= status_badge($a['status'] === 'halted' ? 'frozen' : $a['status']) ?></td>
      <?php if (can('crypto.manage')): ?>
      <td><form method="post" action="<?= e(url('admin/crypto/' . $a['id'] . '/price')) ?>" class="inline-form nowrap"><?= csrf_field() ?>
        <input name="price" inputmode="decimal" placeholder="<?= e(App\Services\Money::toDecimal((int) $a['price'])) ?>" class="w-sm" required><button class="btn btn-secondary btn-sm">Set</button></form></td>
      <td><a class="btn btn-ghost btn-sm" href="?edit=<?= (int) $a['id'] ?>#asset-form">Edit</a></td><?php endif; ?></tr>
  <?php endforeach; ?>
  <?php if (!$assets): ?><tr><td colspan="8" class="empty">No assets yet. Add BTC, ETH, USDT or a custom asset below.</td></tr><?php endif; ?></tbody>
</table></div></section>

<?php if (can('crypto.manage')): ?>
<section class="card" id="asset-form">
  <h2><?= $edit ? 'Edit ' . e($e0['symbol']) : 'Add asset' ?></h2>
  <form method="post" action="<?= e(url('admin/crypto')) ?>" class="form grid-3"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($e0['id']) ?>">
    <label>Symbol <input name="symbol" value="<?= e($e0['symbol']) ?>" <?= $edit ? 'disabled' : 'required' ?> maxlength="12" placeholder="BTC"></label>
    <label>Name <input name="name" value="<?= e($e0['name']) ?>" required maxlength="80" placeholder="Bitcoin"></label>
    <label>CoinGecko coin ID <input name="feed_id" value="<?= e($e0['feed_id'] ?? '') ?>" maxlength="80" placeholder="e.g. bitcoin, ethereum, tether">
      <small class="muted">For live prices<?= $feedOn ? '' : ' (enable CoinGecko under Integrations)' ?>. The ID is in the coin's URL on coingecko.com.</small></label>
    <label>Decimal precision <input type="number" name="decimals" value="<?= (int) $e0['decimals'] ?>" min="0" max="8" <?= $edit ? 'disabled' : '' ?>><small class="muted">Fixed after creation</small></label>
    <?php if (!$edit): ?><label>Initial price <input name="price" inputmode="decimal" placeholder="0.00"><small class="muted">Not needed when a CoinGecko ID is set.</small></label><?php endif; ?>
    <label>Trading fee (bps) <input type="number" name="trade_fee_bps" value="<?= (int) $e0['trade_fee_bps'] ?>" min="0" max="1000"><small class="muted">100 = 1%</small></label>
    <label>Minimum trade <input name="min_trade" value="<?= e(App\Services\Money::toDecimal((int) $e0['min_trade'])) ?>" inputmode="decimal"></label>
    <label>Simulator volatility (bps per step) <input type="number" name="volatility_bps" value="<?= (int) $e0['volatility_bps'] ?>" min="0" max="2000"><small class="muted">0 = manual prices only</small></label>
    <label>Status <select name="status"><?php foreach (['active', 'halted', 'inactive'] as $s): ?><option <?= $e0['status'] === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select><small class="muted">Halted: visible, no trading</small></label>
    <label>Sort order <input type="number" name="sort_order" value="<?= (int) $e0['sort_order'] ?>"></label>
    <div class="span-3"><button class="btn btn-primary">Save asset</button> <?php if ($edit): ?><a class="btn btn-ghost" href="<?= e(url('admin/crypto')) ?>">Cancel</a><?php endif; ?></div>
  </form>
</section>
<?php endif; ?>
