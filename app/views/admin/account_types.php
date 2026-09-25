<div class="page-head"><h1>Account types</h1><p class="muted">Limits apply in order: individual account override → account type (here) → global default in Settings. Leave blank to inherit.</p></div>
<section class="card"><div class="table-wrap"><table class="table">
  <thead><tr><th>Type</th><th>Accounts</th><th>Daily transfer</th><th>Daily withdrawal</th><th>Monthly outgoing</th><th>Monthly fee</th><th>Active</th><th></th></tr></thead>
  <tbody><?php foreach ($types as $t): $f = fn ($k) => $t[$k] !== null && ($k !== 'monthly_fee' || (int) $t[$k] > 0) ? e(App\Services\Money::toDecimal((int) $t[$k])) : ''; ?>
    <tr>
      <td><input form="at<?= (int) $t['id'] ?>" name="name" value="<?= e($t['name']) ?>" maxlength="80"><div class="muted small mono"><?= e($t['slug']) ?></div></td>
      <td><?= (int) $t['accounts'] ?></td>
      <td><input form="at<?= (int) $t['id'] ?>" name="daily_transfer_limit" value="<?= $f('daily_transfer_limit') ?>" placeholder="<?= e(App\Services\Money::toDecimal((int) setting('default_daily_transfer_limit'))) ?>" inputmode="decimal"></td>
      <td><input form="at<?= (int) $t['id'] ?>" name="daily_withdrawal_limit" value="<?= $f('daily_withdrawal_limit') ?>" placeholder="<?= e(App\Services\Money::toDecimal((int) setting('default_daily_withdrawal_limit'))) ?>" inputmode="decimal"></td>
      <td><input form="at<?= (int) $t['id'] ?>" name="monthly_limit" value="<?= $f('monthly_limit') ?>" placeholder="<?= e(App\Services\Money::toDecimal((int) setting('default_monthly_limit'))) ?>" inputmode="decimal"></td>
      <td><input form="at<?= (int) $t['id'] ?>" name="monthly_fee" value="<?= $f('monthly_fee') ?>" placeholder="<?= e(App\Services\Money::toDecimal((int) setting('monthly_account_fee'))) ?>" inputmode="decimal"></td>
      <td><input form="at<?= (int) $t['id'] ?>" type="checkbox" name="is_active" value="1" <?= $t['is_active'] ? 'checked' : '' ?> aria-label="Active"></td>
      <td><form method="post" id="at<?= (int) $t['id'] ?>" action="<?= e(url('admin/account-types/' . $t['id'])) ?>"><?= csrf_field() ?><button class="btn btn-secondary btn-sm">Save</button></form></td>
    </tr>
  <?php endforeach; ?></tbody>
</table></div></section>
