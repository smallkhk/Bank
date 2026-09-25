<div class="page-head"><h1>Transfer money</h1></div>
<div class="two-col">
<section class="card">
  <?php if (!$accounts): ?><p class="empty">You need an active account to make transfers.</p><?php else: ?>
  <form method="post" action="<?= e(url('transfer')) ?>" class="form" data-transfer-form data-fee-fixed="<?= (int) $feeFixed ?>" data-fee-bps="<?= (int) $feeBps ?>">
    <?= csrf_field() ?>
    <label>From account
      <select name="from_account" required>
        <?php $sel = old('from_account', (string) ($_GET['from'] ?? '')); foreach ($accounts as $a): ?>
          <option value="<?= (int) $a['id'] ?>" <?= $sel === (string) $a['id'] ? 'selected' : '' ?>><?= e(($a['nickname'] ?: $a['type_name']) . ' ' . mask_account($a['account_number'])) ?> — available <?= e(money(App\Services\AccountService::available($a), $a['currency'])) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Recipient account number
      <input name="to_account" value="<?= e(old('to_account')) ?>" required inputmode="numeric" autocomplete="off" pattern="[0-9 ]{6,34}">
    </label>
    <label>Amount (<?= e(setting('currency')) ?>)
      <input name="amount" value="<?= e(old('amount')) ?>" required inputmode="decimal" placeholder="0.00" pattern="[0-9,]+(\.[0-9]{1,2})?" data-amount>
      <small class="muted" data-fee-note></small>
    </label>
    <label>Description <input name="description" value="<?= e(old('description')) ?>" maxlength="140" placeholder="e.g. Rent for March"></label>
    <label>Your reference (optional) <input name="reference" value="<?= e(old('reference')) ?>" maxlength="60"></label>
    <?php if (setting('confirm_password_for_transfers') === '1'): ?>
      <label>Confirm with your password <input type="password" name="password" required autocomplete="current-password"></label>
    <?php endif; ?>
    <button class="btn btn-primary btn-block" data-confirm="Please confirm this transfer. Transfers cannot be undone once completed.">Send transfer</button>
  </form>
  <?php endif; ?>
</section>
<aside class="card muted-card">
  <h2>Before you send</h2>
  <ul class="checks">
    <li>Transfers go to other accounts held at <?= e(bank_name()) ?>.</li>
    <li>Check the recipient account number carefully — completed transfers cannot be recalled.</li>
    <li>Daily and monthly limits apply to each account.</li>
    <?php if ((int) setting('transfer_approval_threshold') > 0): ?><li>Transfers of <?= e(money((int) setting('transfer_approval_threshold'))) ?> or more are reviewed before release.</li><?php endif; ?>
    <li>We will never ask you to move money to a “safe account”.</li>
  </ul>
</aside>
</div>
