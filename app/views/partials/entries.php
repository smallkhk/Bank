<?php /** @var array $entries */ $compact = $compact ?? false; ?>
<?php if (!$entries): ?>
  <p class="empty">No transactions yet.</p>
<?php else: ?>
<div class="table-wrap">
<table class="table tx-table">
  <thead><tr><th>Description</th><th class="hide-sm">Date</th><?php if (!$compact): ?><th class="hide-sm">Account</th><?php endif; ?><th class="num">Amount</th><?php if (!$compact): ?><th class="num hide-sm">Balance</th><?php endif; ?></tr></thead>
  <tbody>
  <?php foreach ($entries as $en): $credit = $en['entry_type'] === 'credit';
      $ico = ['transfer' => 'transfer', 'card' => 'card', 'crypto' => 'coins', 'fee' => 'tag', 'withdrawal' => 'withdraw', 'deposit' => 'plus'][$en['type']] ?? ($credit ? 'in' : 'out'); ?>
    <tr>
      <td><div class="tx-cell"><span class="tx-ico <?= $credit ? 'in' : 'out' ?>"><?= icon($ico) ?></span>
        <div><a href="<?= e(url('transactions/' . $en['reference'])) ?>"><?= e($en['description'] ?: ucfirst($en['type'])) ?></a>
        <div class="muted small"><?= e(ucfirst($en['type'])) ?><span class="show-sm"> · <?= e(fmt_date($en['created_at'], 'M j')) ?></span><?php if (!$compact): ?><span class="hide-sm"> · <?= e($en['reference']) ?></span><?php endif; ?></div></div></div></td>
      <td class="nowrap hide-sm"><?= e(fmt_date($en['created_at'], 'M j, Y')) ?><div class="muted small"><?= e(fmt_date($en['created_at'], 'g:i A')) ?></div></td>
      <?php if (!$compact): ?><td class="hide-sm mono small"><?= e(mask_account($en['account_number'])) ?></td><?php endif; ?>
      <td class="num <?= $credit ? 'pos' : '' ?>" data-private><?= $credit ? '+' : '−' ?><?= e(money((int) $en['amount'])) ?></td>
      <?php if (!$compact): ?><td class="num hide-sm muted" data-private><?= e(money((int) $en['balance_after'])) ?></td><?php endif; ?>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
