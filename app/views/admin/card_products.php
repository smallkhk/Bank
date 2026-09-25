<?php
$p = $edit ?? ['id' => '', 'name' => '', 'card_type' => 'debit', 'form_factor' => 'virtual', 'bin_prefix' => '4', 'daily_limit' => 200000, 'monthly_limit' => 1000000,
  'atm_daily_limit' => 50000, 'online_enabled' => 1, 'atm_enabled' => 1, 'international_enabled' => 0, 'issuance_fee' => 0, 'replacement_fee' => 0,
  'international_fee_bps' => 0, 'expiry_months' => 36, 'credit_limit' => 0, 'interest_apr_bps' => 1999, 'min_payment_bps' => 500, 'min_payment_floor' => 2500,
  'statement_day' => 1, 'grace_days' => 21, 'late_fee' => 0, 'customer_requestable' => 1, 'status' => 'active'];
$m = fn ($k) => e(App\Services\Money::toDecimal((int) $p[$k]));
?>
<div class="page-head"><h1>Card products</h1><p class="muted">Simulated card products. Numbers are generated internally and are not valid on any real card network.</p></div>
<section class="card"><div class="table-wrap"><table class="table">
  <thead><tr><th>Product</th><th>Type</th><th>Prefix</th><th class="num">Daily / monthly limit</th><th class="num">Fees</th><th>Features</th><th>Active cards</th><th>Status</th><th></th></tr></thead>
  <tbody><?php foreach ($products as $r): ?>
    <tr><td><strong><?= e($r['name']) ?></strong></td><td><?= e($r['card_type']) ?> · <?= e($r['form_factor']) ?><?= $r['card_type'] === 'credit' ? '<div class="muted small">limit ' . e(money((int) $r['credit_limit'])) . ' · ' . number_format($r['interest_apr_bps'] / 100, 2) . '% APR</div>' : '' ?></td>
      <td class="mono"><?= e($r['bin_prefix']) ?></td><td class="num"><?= e(money((int) $r['daily_limit'])) ?> / <?= e(money((int) $r['monthly_limit'])) ?></td>
      <td class="num">Issue <?= e(money((int) $r['issuance_fee'])) ?><div class="muted small">Replace <?= e(money((int) $r['replacement_fee'])) ?></div></td>
      <td class="small"><?= $r['online_enabled'] ? 'Online ' : '' ?><?= $r['atm_enabled'] ? 'ATM ' : '' ?><?= $r['international_enabled'] ? 'Intl' : '' ?></td>
      <td><?= (int) $r['active_cards'] ?></td><td><?= status_badge($r['status']) ?></td><td><a class="btn btn-ghost btn-sm" href="?edit=<?= (int) $r['id'] ?>">Edit</a></td></tr>
  <?php endforeach; ?>
  <?php if (!$products): ?><tr><td colspan="9" class="empty">No card products yet — create one below.</td></tr><?php endif; ?></tbody>
</table></div></section>

<section class="card" id="product-form">
  <h2><?= $edit ? 'Edit ' . e($p['name']) : 'New card product' ?></h2>
  <form method="post" action="<?= e(url('admin/card-products')) ?>" class="form grid-3"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($p['id']) ?>">
    <label>Name <input name="name" value="<?= e($p['name']) ?>" required maxlength="100"></label>
    <label>Type <select name="card_type" <?= $edit ? 'disabled' : '' ?>><?php foreach (['debit', 'credit', 'prepaid'] as $t): ?><option <?= $p['card_type'] === $t ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select></label>
    <label>Form <select name="form_factor" <?= $edit ? 'disabled' : '' ?>><?php foreach (['virtual', 'physical'] as $t): ?><option <?= $p['form_factor'] === $t ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select></label>
    <label>Card number prefix <input name="bin_prefix" value="<?= e($p['bin_prefix']) ?>" required pattern="\d{1,8}" maxlength="8"></label>
    <label>Expiry (months) <input type="number" name="expiry_months" value="<?= (int) $p['expiry_months'] ?>" min="6" max="120"></label>
    <label>Status <select name="status"><option <?= $p['status'] === 'active' ? 'selected' : '' ?>>active</option><option <?= $p['status'] === 'inactive' ? 'selected' : '' ?>>inactive</option></select></label>
    <label>Daily spending limit <input name="daily_limit" value="<?= $m('daily_limit') ?>" inputmode="decimal"></label>
    <label>Monthly spending limit <input name="monthly_limit" value="<?= $m('monthly_limit') ?>" inputmode="decimal"></label>
    <label>Daily ATM limit <input name="atm_daily_limit" value="<?= $m('atm_daily_limit') ?>" inputmode="decimal"></label>
    <label>Issuance fee <input name="issuance_fee" value="<?= $m('issuance_fee') ?>" inputmode="decimal"></label>
    <label>Replacement fee <input name="replacement_fee" value="<?= $m('replacement_fee') ?>" inputmode="decimal"></label>
    <label>International fee (bps) <input type="number" name="international_fee_bps" value="<?= (int) $p['international_fee_bps'] ?>" min="0" max="1000"></label>
    <div class="span-3 radio-row">
      <?php foreach (['online_enabled' => 'Online payments', 'atm_enabled' => 'ATM', 'international_enabled' => 'International use', 'customer_requestable' => 'Customers can request online'] as $k => $l): ?>
        <label class="check"><input type="checkbox" name="<?= $k ?>" value="1" <?= $p[$k] ? 'checked' : '' ?>> <?= $l ?></label><?php endforeach; ?></div>
    <fieldset class="span-3 fieldset"><legend>Credit cards only</legend><div class="form grid-3">
      <label>Credit limit <input name="credit_limit" value="<?= $m('credit_limit') ?>" inputmode="decimal"></label>
      <label>Interest APR (bps) <input type="number" name="interest_apr_bps" value="<?= (int) $p['interest_apr_bps'] ?>" min="0" max="6000"><small class="muted">1999 = 19.99%</small></label>
      <label>Late fee <input name="late_fee" value="<?= $m('late_fee') ?>" inputmode="decimal"></label>
      <label>Minimum payment (bps of balance) <input type="number" name="min_payment_bps" value="<?= (int) $p['min_payment_bps'] ?>" min="0" max="10000"></label>
      <label>Minimum payment floor <input name="min_payment_floor" value="<?= $m('min_payment_floor') ?>" inputmode="decimal"></label>
      <label>Statement day (1–28) <input type="number" name="statement_day" value="<?= (int) $p['statement_day'] ?>" min="1" max="28"></label>
      <label>Days to pay after statement <input type="number" name="grace_days" value="<?= (int) $p['grace_days'] ?>" min="1" max="60"></label>
    </div></fieldset>
    <div class="span-3"><button class="btn btn-primary">Save product</button> <?php if ($edit): ?><a class="btn btn-ghost" href="<?= e(url('admin/card-products')) ?>">Cancel</a><?php endif; ?></div>
  </form>
</section>
