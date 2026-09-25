<?php use App\Services\CryptoService as C; ?>
<div class="page-head"><div><a class="back" href="<?= e(url('admin/crypto')) ?>">← Crypto</a><h1>Crypto trades</h1></div></div>
<section class="card">
  <form class="filters" method="get"><label>Search <input name="q" value="<?= e(input('q')) ?>" placeholder="Customer or reference"></label>
    <label>Symbol <input name="symbol" value="<?= e(input('symbol')) ?>" size="6"></label>
    <label>Side <select name="side"><option value="">All</option><option <?= input('side') === 'buy' ? 'selected' : '' ?>>buy</option><option <?= input('side') === 'sell' ? 'selected' : '' ?>>sell</option></select></label>
    <button class="btn btn-secondary btn-sm">Filter</button></form>
  <div class="table-wrap"><table class="table small">
    <thead><tr><th>Date</th><th>Reference</th><th>Customer</th><th>Trade</th><th class="num">Price</th><th class="num">Gross</th><th class="num">Fee</th><th class="num">Realised</th><th>Ledger</th></tr></thead>
    <tbody><?php foreach ($rows as $t): ?>
      <tr><td class="nowrap"><?= e(fmt_date($t['created_at'])) ?></td><td class="mono"><?= e($t['reference']) ?></td>
        <td><a href="<?= e(url('admin/customers/' . $t['customer_id'])) ?>"><?= e($t['customer_name']) ?></a></td>
        <td><?= e(ucfirst($t['side'])) ?> <?= e(C::formatQuantity((int) $t['quantity'], (int) $t['decimals'])) ?> <?= e($t['symbol']) ?></td>
        <td class="num"><?= e(money((int) $t['price'])) ?></td><td class="num"><?= e(money((int) $t['gross'])) ?></td><td class="num"><?= e(money((int) $t['fee'])) ?></td>
        <td class="num"><?= $t['realized_pnl'] !== null ? e(money((int) $t['realized_pnl'])) : '' ?></td>
        <td><a class="mono" href="<?= e(url('admin/transactions/' . $t['transaction_id'])) ?>">view</a></td></tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="9" class="empty">No trades.</td></tr><?php endif; ?></tbody>
  </table></div>
  <?php include APP_PATH . '/views/partials/pager.php'; ?>
</section>
