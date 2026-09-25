<?php
$tabs = ['general' => 'General & branding', 'accounts' => 'Accounts', 'transactions' => 'Transactions', 'security' => 'Security', 'support' => 'Support', 'modules' => 'Cards & crypto', 'legal' => 'Legal & messages'];
$ro = !can('settings.manage');
$money = fn (string $k) => App\Services\Money::toDecimal((int) $s[$k]);
$text = function (string $k, string $label, string $type = 'text', string $hint = '') use ($s, $ro) {
    echo '<label>' . e($label) . ' <input type="' . $type . '" name="s[' . $k . ']" value="' . e($s[$k]) . '"' . ($ro ? ' disabled' : '') . '>' . ($hint ? '<small class="muted">' . e($hint) . '</small>' : '') . '</label>';
};
$bool = function (string $k, string $label) use ($s, $ro) {
    echo '<input type="hidden" name="bools[]" value="' . $k . '"><label class="check"><input type="checkbox" name="s[' . $k . ']" value="1"' . ($s[$k] === '1' ? ' checked' : '') . ($ro ? ' disabled' : '') . '> ' . e($label) . '</label>';
};
?>
<div class="page-head"><h1>Settings</h1></div>
<nav class="tabs"><?php foreach ($tabs as $k => $label): ?><a href="?tab=<?= $k ?>" class="<?= $tab === $k ? 'active' : '' ?>"><?= e($label) ?></a><?php endforeach; ?></nav>
<section class="card">
<form method="post" action="<?= e(url('admin/settings')) ?>" class="form grid-2" enctype="multipart/form-data">
  <?= csrf_field() ?><input type="hidden" name="tab" value="<?= e($tab) ?>">
  <?php if ($tab === 'general'): ?>
    <?php $text('bank_name', 'Bank name'); $text('bank_short_name', 'Short name'); ?>
    <label>Logo (PNG/JPG/WEBP, max 1 MB) <input type="file" name="logo" accept="image/png,image/jpeg,image/webp" <?= $ro ? 'disabled' : '' ?>></label>
    <label>Favicon (PNG/ICO) <input type="file" name="favicon" accept="image/png,image/x-icon" <?= $ro ? 'disabled' : '' ?>></label>
    <?php $text('color_primary', 'Primary color', 'color'); $text('color_secondary', 'Secondary color', 'color'); $text('color_accent', 'Accent color', 'color'); ?>
    <?php $text('currency', 'Currency code', 'text', 'ISO 4217, e.g. USD. Changing it does not convert existing accounts.'); $text('currency_symbol', 'Currency symbol'); ?>
    <?php $text('contact_email', 'Contact email', 'email'); $text('support_phone', 'Support phone'); $text('address', 'Address'); $text('website_url', 'Website URL', 'url'); ?>
    <?php $bool('sandbox_notice', 'Show "simulated balances" notice banner'); $bool('maintenance_mode', 'Maintenance mode (customers cannot sign in)'); ?>
  <?php elseif ($tab === 'accounts'): ?>
    <?php $text('account_number_prefix', 'Account number prefix (digits)'); $text('account_number_branch', 'Branch / country code (digits)'); ?>
    <?php $text('account_number_length', 'Account number total length', 'number', 'Includes prefix, branch code and a check digit. 8–20.'); ?>
    <label>Default account type for new customers <select name="s[default_account_type]" <?= $ro ? 'disabled' : '' ?>>
      <?php foreach (App\Core\Db::all('SELECT slug, name FROM account_types WHERE is_active = 1 AND slug <> \'credit\'') as $t): ?><option value="<?= e($t['slug']) ?>" <?= $s['default_account_type'] === $t['slug'] ? 'selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?></select></label>
    <label>Default daily transfer limit <input name="s[default_daily_transfer_limit]" value="<?= e($money('default_daily_transfer_limit')) ?>" <?= $ro ? 'disabled' : '' ?>></label>
    <label>Default daily withdrawal limit <input name="s[default_daily_withdrawal_limit]" value="<?= e($money('default_daily_withdrawal_limit')) ?>" <?= $ro ? 'disabled' : '' ?>></label>
    <label>Default monthly outgoing limit <input name="s[default_monthly_limit]" value="<?= e($money('default_monthly_limit')) ?>" <?= $ro ? 'disabled' : '' ?>></label>
    <?php $bool('registration_enabled', 'Allow online registration'); $bool('registration_auto_activate', 'Activate new registrations automatically (skip review)'); ?>
  <?php elseif ($tab === 'transactions'): ?>
    <label>Transfer fee (fixed) <input name="s[transfer_fee_fixed]" value="<?= e($money('transfer_fee_fixed')) ?>" <?= $ro ? 'disabled' : '' ?>></label>
    <?php $text('transfer_fee_bps', 'Transfer fee (basis points)', 'number', '100 = 1.00% of the amount'); ?>
    <label>Withdrawal fee (fixed) <input name="s[withdrawal_fee_fixed]" value="<?= e($money('withdrawal_fee_fixed')) ?>" <?= $ro ? 'disabled' : '' ?>></label>
    <label>Transfers at or above this amount need approval <input name="s[transfer_approval_threshold]" value="<?= e($money('transfer_approval_threshold')) ?>" <?= $ro ? 'disabled' : '' ?>><small class="muted">0 = never</small></label>
    <?php $bool('add_funds_requires_approval', 'Add-funds requires a second approver'); $bool('customer_add_funds_requests', 'Customers may submit add-funds requests'); ?>
    <?php $bool('allow_self_approval', 'Allow staff to approve their own requests (not recommended)'); ?>
    <label>Default monthly account fee <input name="s[monthly_account_fee]" value="<?= e($money('monthly_account_fee')) ?>" <?= $ro ? 'disabled' : '' ?>><small class="muted">Used when the account type has no fee of its own. 0 = none.</small></label>
    <label>Waive monthly fee when balance is at least <input name="s[monthly_fee_min_balance_waiver]" value="<?= e($money('monthly_fee_min_balance_waiver')) ?>" <?= $ro ? 'disabled' : '' ?>><small class="muted">0 = never waive</small></label>
  <?php elseif ($tab === 'security'): ?>
    <?php $text('password_min_length', 'Minimum password length', 'number'); $text('login_max_attempts', 'Failed sign-ins before lockout', 'number'); ?>
    <?php $text('login_lockout_minutes', 'Lockout duration (minutes)', 'number'); $text('session_idle_minutes', 'Idle session timeout (minutes)', 'number'); ?>
    <?php $bool('confirm_password_for_transfers', 'Require password confirmation for transfers'); ?>
    <?php $bool('require_2fa_staff', 'Require two-step verification for all staff'); ?>
    <?php $bool('require_email_verification', 'Customers must verify their email before signing in (needs email delivery enabled)'); ?>
  <?php elseif ($tab === 'support'): ?>
    <?php $bool('chat_enabled', 'Enable live chat for customers'); ?>
    <?php $text('support_sla_hours', 'Response target (SLA, hours)', 'number', 'Tickets awaiting a staff reply longer than this are flagged overdue.'); ?>
    <label class="span-2">Ticket categories (one per line) <textarea name="s[support_categories]" rows="7" <?= $ro ? 'disabled' : '' ?>><?= e($s['support_categories']) ?></textarea></label>
  <?php elseif ($tab === 'modules'): ?>
    <?php $bool('cards_enabled', 'Cards module enabled'); $bool('credit_cards_enabled', 'Credit cards available'); ?>
    <?php $text('max_cards_per_customer', 'Maximum open cards per customer', 'number'); $text('bank_country', 'Home country (ISO code, for international card use)'); ?>
    <?php $bool('crypto_enabled', 'Crypto module (simulated assets) enabled for customers'); ?>
    <label>Maximum value per crypto trade <input name="s[crypto_max_trade]" value="<?= e($money('crypto_max_trade')) ?>" <?= $ro ? 'disabled' : '' ?>></label>
    <label class="span-2">Crypto risk notice (customers must accept before trading) <textarea name="s[crypto_risk_text]" rows="5" <?= $ro ? 'disabled' : '' ?>><?= e($s['crypto_risk_text']) ?></textarea></label>
  <?php else: ?>
    <?php $text('login_message', 'Sign-in page message'); $text('footer_text', 'Footer text'); ?>
    <label class="span-2">Maintenance message <input name="s[maintenance_message]" value="<?= e($s['maintenance_message']) ?>" <?= $ro ? 'disabled' : '' ?>></label>
    <label class="span-2">Terms and conditions <textarea name="s[terms_text]" rows="10" <?= $ro ? 'disabled' : '' ?>><?= e($s['terms_text']) ?></textarea></label>
    <label class="span-2">Privacy policy <textarea name="s[privacy_text]" rows="10" <?= $ro ? 'disabled' : '' ?>><?= e($s['privacy_text']) ?></textarea></label>
  <?php endif; ?>
  <?php if (!$ro): ?><div class="span-2"><button class="btn btn-primary">Save settings</button></div><?php endif; ?>
</form>
</section>
