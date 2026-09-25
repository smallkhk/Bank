<?php $outgoing = in_array((int) $tx['from_account_id'], $myIds, true); ?>
<div class="page-head"><div><a class="back" href="<?= e(url('transactions')) ?>">← Transactions</a><h1>Transaction details</h1></div>
  <button class="btn btn-ghost no-print" type="button" data-print>Print receipt</button></div>
<section class="card narrow receipt">
  <div class="receipt-amount <?= $outgoing ? 'neg' : 'pos' ?>"><?= $outgoing ? '−' : '+' ?><?= e(money((int) $tx['amount'], $tx['currency'])) ?></div>
  <div class="center"><?= status_badge($tx['status']) ?></div>
  <dl class="kv">
    <dt>Reference</dt><dd class="mono"><?= e($tx['reference']) ?></dd>
    <dt>Type</dt><dd><?= e(ucfirst($tx['type'])) ?></dd>
    <dt>Date</dt><dd><?= e(fmt_date($tx['created_at'])) ?></dd>
    <?php if ($tx['from_number']): ?><dt>From</dt><dd><?= e($outgoing ? $tx['from_number'] : ($tx['from_name'] ?? '') . ' ' . mask_account($tx['from_number'])) ?></dd><?php endif; ?>
    <?php if ($tx['to_number']): ?><dt>To</dt><dd><?= e($outgoing ? ($tx['to_name'] ?? '') . ' ' . mask_account($tx['to_number']) : $tx['to_number']) ?></dd><?php endif; ?>
    <dt>Description</dt><dd><?= e($tx['description'] ?: '—') ?></dd>
    <?php if ($tx['customer_reference']): ?><dt>Your reference</dt><dd><?= e($tx['customer_reference']) ?></dd><?php endif; ?>
    <?php if ($outgoing && (int) $tx['fee_amount'] > 0): ?><dt>Fee</dt><dd><?= e(money((int) $tx['fee_amount'], $tx['currency'])) ?></dd><?php endif; ?>
  </dl>
  <p class="muted small center"><?= e(bank_name()) ?> · Internal transfer record</p>
</section>
