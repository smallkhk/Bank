<?php $live = in_array($card['status'], ['active', 'frozen'], true); ?>
<div class="page-head"><div><a class="back" href="<?= e(url('admin/cards')) ?>">← Cards</a>
  <h1><?= e($card['product_name']) ?> <?= status_badge($card['status']) ?></h1>
  <p class="muted"><a href="<?= e(url('admin/customers/' . $card['customer_id'])) ?>"><?= e($card['customer_name']) ?></a> ·
    <?= e(ucfirst($card['card_type'])) ?> · <?= e($card['form_factor']) ?><?= $card['account_number'] ? ' · account <a class="mono" href="' . e(url('admin/accounts/' . $card['account_id'])) . '">' . e($card['account_number']) . '</a>' : '' ?>
    <?= $card['status_reason'] ? ' · ' . e($card['status_reason']) : '' ?><?= $card['replaces_card_id'] ? ' · replaces <a href="' . e(url('admin/cards/' . $card['replaces_card_id'])) . '">card #' . (int) $card['replaces_card_id'] . '</a>' : '' ?></p></div></div>

<div class="card-layout">
  <section><?php include APP_PATH . '/views/partials/card_visual.php'; ?>
    <?php if ($acc): ?><div class="stat-grid tight mt">
      <?php if ($owed !== null): ?><div class="stat"><span>Owed</span><strong><?= e(money($owed)) ?></strong><small>Limit <?= e(money((int) $acc['credit_limit'])) ?></small></div><?php endif; ?>
      <div class="stat"><span><?= $owed !== null ? 'Available credit' : 'Available balance' ?></span><strong><?= e(money(App\Services\AccountService::available($acc))) ?></strong></div>
    </div><?php endif; ?>
  </section>
  <div>
    <?php if ($card['status'] === 'pending' && can('cards.issue')): ?>
    <section class="card">
      <h2>Awaiting issue</h2>
      <p class="muted">Requested <?= e(fmt_date($card['created_at'])) ?>. <?= $card['card_type'] === 'credit' ? 'Issuing opens a credit account with a limit of ' . e(money((int) $card['product_credit_limit'])) . '. ' : '' ?>
        <?= (int) ($card['replaces_card_id'] ? $card['replacement_fee'] : $card['issuance_fee']) ? 'A fee of ' . e(money((int) ($card['replaces_card_id'] ? $card['replacement_fee'] : $card['issuance_fee']))) . ' will be charged.' : '' ?></p>
      <form method="post" action="<?= e(url('admin/cards/' . $card['id'] . '/issue')) ?>"><?= csrf_field() ?><button class="btn btn-primary" data-confirm="Issue and activate this card?">Issue card</button></form>
      <form method="post" action="<?= e(url('admin/cards/' . $card['id'] . '/reject')) ?>" class="inline-form"><?= csrf_field() ?><input name="reason" required placeholder="Reason for rejection" maxlength="255"><button class="btn btn-ghost btn-sm">Reject</button></form>
    </section>
    <?php endif; ?>
    <?php if ($live && can('cards.freeze')): ?>
    <section class="card">
      <h2>Status</h2>
      <form method="post" action="<?= e(url('admin/cards/' . $card['id'] . '/status')) ?>" class="inline-form"><?= csrf_field() ?>
        <select name="status"><?php foreach ($card['status'] === 'active' ? ['frozen' => 'Freeze', 'blocked' => 'Block (permanent)', 'cancelled' => 'Cancel'] : ['active' => 'Unfreeze', 'blocked' => 'Block (permanent)', 'cancelled' => 'Cancel'] as $v => $l): ?><option value="<?= $v ?>"><?= $l ?></option><?php endforeach; ?></select>
        <input name="reason" required placeholder="Reason" maxlength="255"><button class="btn btn-secondary btn-sm" data-confirm="Change this card's status?">Apply</button></form>
      <?php if (can('cards.issue')): ?>
      <form method="post" action="<?= e(url('admin/cards/' . $card['id'] . '/replace')) ?>" class="inline-form"><?= csrf_field() ?>
        <input name="reason" required placeholder="Replacement reason (lost, stolen, damaged…)" maxlength="255"><button class="btn btn-ghost btn-sm" data-confirm="Block this card and create a replacement?">Block &amp; replace</button></form>
      <?php endif; ?>
    </section>
    <section class="card">
      <h2>Controls</h2>
      <form method="post" action="<?= e(url('admin/cards/' . $card['id'] . '/controls')) ?>" class="form"><?= csrf_field() ?>
        <div class="radio-row">
          <label class="check"><input type="checkbox" name="online" value="1" <?= $card['online_enabled'] ? 'checked' : '' ?>> Online</label>
          <label class="check"><input type="checkbox" name="atm" value="1" <?= $card['atm_enabled'] ? 'checked' : '' ?>> ATM</label>
          <label class="check"><input type="checkbox" name="international" value="1" <?= $card['international_enabled'] ? 'checked' : '' ?>> International</label></div>
        <label>Daily limit override <input name="daily_limit" inputmode="decimal" value="<?= $card['daily_limit'] !== null ? e(App\Services\Money::toDecimal((int) $card['daily_limit'])) : '' ?>" placeholder="Product: <?= e(App\Services\Money::toDecimal((int) $card['product_daily_limit'])) ?>"></label>
        <button class="btn btn-secondary btn-sm">Save</button></form>
    </section>
    <?php endif; ?>
    <?php if ($card['account_id'] && can('cards.configure') && $card['status'] !== 'pending'): ?>
    <section class="card">
      <h2>Simulate card transaction</h2>
      <p class="muted small">Stands in for the card network until a processor is integrated. Runs the full authorisation checks.</p>
      <form method="post" action="<?= e(url('admin/cards/' . $card['id'] . '/simulate')) ?>" class="form grid-2"><?= csrf_field() ?>
        <label>Merchant <input name="merchant" required maxlength="120" placeholder="e.g. Grocery Store"></label>
        <label>Category <input name="category" maxlength="60" placeholder="e.g. Groceries"></label>
        <label>Amount <input name="amount" required inputmode="decimal" placeholder="0.00"></label>
        <label>Channel <select name="channel"><?php foreach (App\Services\CardService::CHANNELS as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select></label>
        <label>Country (ISO) <input name="country" value="<?= e(setting('bank_country', 'US')) ?>" maxlength="2" required pattern="[A-Za-z]{2}"></label>
        <div class="span-2"><button class="btn btn-primary btn-sm">Authorise</button></div></form>
    </section>
    <?php endif; ?>
  </div>
</div>

<section class="card">
  <h2>Card transactions</h2>
  <div class="table-wrap"><table class="table small">
    <thead><tr><th>Date</th><th>Merchant</th><th>Channel</th><th>Country</th><th class="num">Amount</th><th class="num">Fee</th><th>Status</th><th></th></tr></thead>
    <tbody><?php foreach ($txs as $t): ?>
      <tr><td class="nowrap"><?= e(fmt_date($t['created_at'])) ?></td><td><?= e($t['merchant_name']) ?><div class="muted"><?= e($t['merchant_category'] ?? '') ?></div></td>
        <td><?= e($t['channel']) ?></td><td><?= e($t['country']) ?></td><td class="num"><?= e(money((int) $t['amount'], $t['currency'])) ?></td><td class="num"><?= e(money((int) $t['fee_amount'])) ?></td>
        <td><?= status_badge($t['status'] === 'declined' ? 'rejected' : ($t['status'] === 'approved' ? 'completed' : 'reversed')) ?><?= $t['decline_reason'] ? '<div class="muted">' . e($t['decline_reason']) . '</div>' : '' ?>
          <?= $t['transaction_id'] ? '<div><a class="mono" href="' . e(url('admin/transactions/' . $t['transaction_id'])) . '">ledger</a></div>' : '' ?></td>
        <td><?php if ($t['status'] === 'approved' && can('cards.configure')): ?>
          <form method="post" action="<?= e(url('admin/cards/' . $card['id'] . '/transactions/' . $t['id'] . '/reverse')) ?>" class="inline-form"><?= csrf_field() ?><input name="reason" required placeholder="Reason" maxlength="255"><button class="btn btn-ghost btn-sm">Reverse</button></form>
        <?php endif; ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$txs): ?><tr><td colspan="8" class="empty">No card transactions.</td></tr><?php endif; ?></tbody>
  </table></div>
</section>

<?php if ($statements): ?>
<section class="card"><h2>Credit statements</h2><div class="table-wrap"><table class="table small">
  <thead><tr><th>Period</th><th class="num">Balance</th><th class="num">Minimum</th><th>Due</th><th class="num">Interest</th><th class="num">Late fee</th><th>Status</th></tr></thead>
  <tbody><?php foreach ($statements as $s): ?><tr><td><?= e($s['period_start']) ?> – <?= e($s['period_end']) ?></td><td class="num"><?= e(money((int) $s['statement_balance'])) ?></td>
    <td class="num"><?= e(money((int) $s['minimum_payment'])) ?></td><td><?= e($s['due_date']) ?></td><td class="num"><?= e(money((int) $s['interest_charged'])) ?></td>
    <td class="num"><?= e(money((int) $s['late_fee_charged'])) ?></td><td><?= status_badge(str_replace('_', ' ', $s['status'])) ?></td></tr><?php endforeach; ?></tbody></table></div></section>
<?php endif; ?>

<section class="card"><h2>Audit history</h2><?php include APP_PATH . '/views/admin/_audit_rows.php'; ?></section>
