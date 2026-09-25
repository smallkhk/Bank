<?php $live = in_array($card['status'], ['active', 'frozen'], true); ?>
<div class="page-head"><div><a class="back" href="<?= e(url('cards')) ?>">← Cards</a>
  <h1><?= e($card['product_name']) ?> <?= status_badge($card['status']) ?></h1>
  <p class="muted"><?= e(ucfirst($card['card_type'])) ?> card<?= $card['account_number'] && $card['card_type'] !== 'credit' ? ' · linked to ' . e(mask_account($card['account_number'])) : '' ?></p></div></div>

<?php if ($card['status'] === 'pending'): ?>
  <div class="alert alert-info">Your card request is being reviewed. We'll notify you when it's ready.</div>
<?php endif; ?>

<div class="card-layout">
  <section>
    <div class="card-visual-wrap" data-reveal-target><?php include APP_PATH . '/views/partials/card_visual.php'; ?></div>
    <?php if ($live): ?>
    <div class="card-actions">
      <form method="post" action="<?= e(url('cards/' . $card['id'] . '/freeze')) ?>"><?= csrf_field() ?>
        <button class="btn <?= $card['status'] === 'frozen' ? 'btn-primary' : 'btn-secondary' ?>"><?= $card['status'] === 'frozen' ? '▶ Unfreeze card' : '❄ Freeze card' ?></button></form>
      <?php if ($card['form_factor'] === 'virtual'): ?>
      <form method="post" action="<?= e(url('cards/' . $card['id'] . '/reveal')) ?>" class="inline-form" data-reveal-form><?= csrf_field() ?>
        <input type="password" name="password" placeholder="Password to show details" required autocomplete="current-password" aria-label="Password">
        <button class="btn btn-ghost">Show details</button></form>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </section>

  <section class="card">
    <?php if ($credit): ?>
      <h2>Credit account</h2>
      <div class="stat-grid tight">
        <div class="stat"><span>Balance owed</span><strong><?= e(money($credit['owed'])) ?></strong></div>
        <div class="stat"><span>Available credit</span><strong><?= e(money($credit['available'])) ?></strong><small>Limit <?= e(money((int) $credit['account']['credit_limit'])) ?></small></div>
      </div>
      <?php if ($st = $credit['statement']): ?>
        <dl class="kv">
          <dt>Last statement</dt><dd><?= e(fmt_date($st['period_end'], 'M j, Y')) ?> · <?= e(money((int) $st['statement_balance'])) ?></dd>
          <dt>Minimum payment</dt><dd><?= e(money((int) $st['minimum_payment'])) ?> due <?= e(fmt_date($st['due_date'], 'M j, Y')) ?> <?= status_badge(str_replace('_', ' ', $st['status'])) ?></dd>
          <dt>Paid since statement</dt><dd><?= e(money((int) $credit['paid_since'])) ?></dd>
          <dt>Interest rate</dt><dd><?= number_format((int) $credit['product']['interest_apr_bps'] / 100, 2) ?>% APR</dd>
        </dl>
      <?php else: ?><p class="muted small">Your first statement will be issued on day <?= (int) $credit['product']['statement_day'] ?> of the month. Interest is only charged if a statement balance is not paid in full.</p><?php endif; ?>
      <?php if ($credit['owed'] > 0 && $accounts): ?>
      <form method="post" action="<?= e(url('cards/' . $card['id'] . '/pay')) ?>" class="form">
        <?= csrf_field() ?>
        <h3 class="h3">Make a payment</h3>
        <label>From <select name="from_account"><?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>"><?= e(($a['nickname'] ?: $a['type_name']) . ' ' . mask_account($a['account_number'])) ?> — <?= e(money(App\Services\AccountService::available($a))) ?></option><?php endforeach; ?></select></label>
        <div class="radio-row">
          <label class="check"><input type="radio" name="option" value="full" checked> Full balance (<?= e(money($credit['owed'])) ?>)</label>
          <?php if ($credit['statement']): ?><label class="check"><input type="radio" name="option" value="minimum"> Minimum due</label><?php endif; ?>
          <label class="check"><input type="radio" name="option" value="other"> Other amount <input name="amount" inputmode="decimal" placeholder="0.00" class="w-sm"></label>
        </div>
        <button class="btn btn-primary">Pay now</button>
      </form>
      <?php endif; ?>
    <?php endif; ?>

    <?php if ($live): ?>
      <h2 class="<?= $credit ? 'mt' : '' ?>">Card controls</h2>
      <form method="post" action="<?= e(url('cards/' . $card['id'] . '/controls')) ?>" class="form">
        <?= csrf_field() ?>
        <label class="check switch"><input type="checkbox" name="online" value="1" <?= $card['online_enabled'] ? 'checked' : '' ?> <?= $card['product_online'] ? '' : 'disabled' ?>> Online payments</label>
        <label class="check switch"><input type="checkbox" name="atm" value="1" <?= $card['atm_enabled'] ? 'checked' : '' ?> <?= $card['product_atm'] ? '' : 'disabled' ?>> ATM withdrawals</label>
        <label class="check switch"><input type="checkbox" name="international" value="1" <?= $card['international_enabled'] ? 'checked' : '' ?> <?= $card['product_international'] ? '' : 'disabled' ?>> International use
          <?= $card['product_international'] ? '' : '<small class="muted">(not available on this card)</small>' ?></label>
        <label>Daily spending limit <input name="daily_limit" inputmode="decimal" value="<?= $card['daily_limit'] !== null ? e(App\Services\Money::toDecimal((int) $card['daily_limit'])) : '' ?>" placeholder="<?= (int) $card['product_daily_limit'] ? 'Up to ' . e(App\Services\Money::toDecimal((int) $card['product_daily_limit'])) : 'No limit' ?>">
          <small class="muted">Leave blank to use the card's standard limit.</small></label>
        <button class="btn btn-secondary btn-sm">Save controls</button>
      </form>
      <details class="mt"><summary class="btn btn-ghost btn-sm">Report lost, stolen or damaged</summary>
        <form method="post" action="<?= e(url('cards/' . $card['id'] . '/report')) ?>" class="form mt"><?= csrf_field() ?>
          <label>What happened? <select name="reason"><option>Lost</option><option>Stolen</option><option>Damaged</option></select></label>
          <label>Confirm with your password <input type="password" name="password" required autocomplete="current-password"></label>
          <p class="muted small">This card will be blocked immediately and cannot be reactivated. A replacement will be requested<?= (int) $card['replacement_fee'] ? ' (fee ' . e(money((int) $card['replacement_fee'])) . ')' : '' ?>.</p>
          <button class="btn btn-primary btn-sm" data-confirm="Block this card permanently and request a replacement?">Block and replace card</button></form></details>
    <?php endif; ?>
  </section>
</div>

<section class="card">
  <h2>Card transactions</h2>
  <?php if (!$txs): ?><p class="empty">No card transactions yet.</p><?php else: ?>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Date</th><th>Merchant</th><th class="hide-sm">Type</th><th class="num">Amount</th><th>Status</th></tr></thead>
    <tbody><?php foreach ($txs as $t): ?>
      <tr><td class="nowrap"><?= e(fmt_date($t['created_at'], 'M j, Y')) ?><div class="muted small"><?= e(fmt_date($t['created_at'], 'g:i A')) ?></div></td>
        <td><?= e($t['merchant_name']) ?><div class="muted small"><?= e($t['merchant_category'] ?? '') ?> <?= e($t['country']) ?><?= $t['decline_reason'] ? ' · ' . e($t['decline_reason']) : '' ?></div></td>
        <td class="hide-sm"><?= e(App\Services\CardService::CHANNELS[$t['channel']]) ?></td>
        <td class="num <?= $t['status'] === 'approved' ? 'neg' : 'muted' ?>">−<?= e(money((int) $t['amount'], $t['currency'])) ?><?= (int) $t['fee_amount'] ? '<div class="muted small">+ fee ' . e(money((int) $t['fee_amount'])) . '</div>' : '' ?></td>
        <td><?= status_badge($t['status'] === 'declined' ? 'rejected' : ($t['status'] === 'approved' ? 'completed' : 'reversed')) ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>
</section>

<?php if ($credit && $credit['statements']): ?>
<section class="card">
  <h2>Statements</h2>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Period</th><th class="num">Balance</th><th class="num">Minimum</th><th>Due</th><th class="num hide-sm">Interest</th><th>Status</th></tr></thead>
    <tbody><?php foreach ($credit['statements'] as $s): ?>
      <tr><td><?= e(fmt_date($s['period_start'], 'M j')) ?> – <?= e(fmt_date($s['period_end'], 'M j, Y')) ?></td><td class="num"><?= e(money((int) $s['statement_balance'])) ?></td>
        <td class="num"><?= e(money((int) $s['minimum_payment'])) ?></td><td><?= e(fmt_date($s['due_date'], 'M j, Y')) ?></td>
        <td class="num hide-sm"><?= e(money((int) $s['interest_charged'])) ?></td><td><?= status_badge(str_replace('_', ' ', $s['status'])) ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
</section>
<?php endif; ?>
