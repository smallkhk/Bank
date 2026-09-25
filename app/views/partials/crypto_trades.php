<?php use App\Services\CryptoService as C; $rowsList = $recent ?? $trades; ?>
<div class="table-wrap"><table class="table">
  <thead><tr><th>Date</th><th>Trade</th><th class="num hide-sm">Price</th><th class="num hide-sm">Fee</th><th class="num">Total</th><th class="num hide-sm">Realised</th></tr></thead>
  <tbody><?php foreach ($rowsList as $t): $sym = $t['symbol'] ?? $asset['symbol']; $dec = (int) ($t['decimals'] ?? $asset['decimals']); ?>
    <tr><td class="nowrap"><?= e(fmt_date($t['created_at'], 'M j, Y')) ?><div class="muted small mono hide-sm"><?= e($t['reference']) ?></div></td>
      <td><span class="badge badge-<?= $t['side'] === 'buy' ? 'info' : 'success' ?>"><?= e(ucfirst($t['side'])) ?></span> <?= e(C::formatQuantity((int) $t['quantity'], $dec)) ?> <?= e($sym) ?></td>
      <td class="num hide-sm"><?= e(money((int) $t['price'])) ?></td><td class="num hide-sm"><?= e(money((int) $t['fee'])) ?></td>
      <td class="num <?= $t['side'] === 'buy' ? 'neg' : 'pos' ?>"><?= $t['side'] === 'buy' ? '−' : '+' ?><?= e(money((int) $t['net'])) ?></td>
      <td class="num hide-sm <?= (int) $t['realized_pnl'] >= 0 ? 'pos' : 'neg' ?>"><?= $t['realized_pnl'] !== null ? e(money((int) $t['realized_pnl'])) : '' ?></td></tr>
  <?php endforeach; ?></tbody>
</table></div>
