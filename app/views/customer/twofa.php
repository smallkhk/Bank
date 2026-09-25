<div class="page-head"><div><a class="back" href="<?= e(url('profile')) ?>">← Profile &amp; security</a><h1>Two-step verification</h1></div></div>
<?php if ($codes): ?>
<section class="card">
  <h2>Your recovery codes</h2>
  <p>Each code can be used once to sign in if you lose your phone. Store them somewhere safe — they will not be shown again.</p>
  <div class="codes mono"><?php foreach ($codes as $c): ?><span><?= e($c) ?></span><?php endforeach; ?></div>
  <button type="button" class="btn btn-ghost btn-sm" data-print>Print codes</button>
</section>
<?php endif; ?>
<?php if ($user['twofa_enabled_at']): ?>
<section class="card">
  <h2><span class="badge badge-success">On</span> Two-step verification is enabled</h2>
  <p class="muted">Enabled <?= e(fmt_date($user['twofa_enabled_at'])) ?>. You have <?= (int) $remaining ?> unused recovery code(s).</p>
  <?php if (!(App\Core\Auth::isStaff() && setting('require_2fa_staff') === '1')): ?>
  <form method="post" action="<?= e(url('profile/2fa/disable')) ?>" class="form inline-form">
    <?= csrf_field() ?>
    <label>Password <input type="password" name="password" required autocomplete="current-password"></label>
    <label>Current code <input name="code" required inputmode="numeric" maxlength="6" autocomplete="one-time-code"></label>
    <button class="btn btn-secondary btn-sm" data-confirm="Turn off two-step verification? Your account will be less secure.">Turn off</button>
  </form>
  <?php endif; ?>
</section>
<?php else: ?>
<div class="two-col">
<section class="card">
  <h2>1. Add to your authenticator app</h2>
  <p class="muted">Open Google Authenticator, Microsoft Authenticator, Authy or 1Password, choose <em>add account → enter a setup key</em>, and enter:</p>
  <dl class="kv"><dt>Account</dt><dd><?= e($user['email']) ?></dd><dt>Key</dt><dd class="mono secret"><?= e(trim(chunk_split((string) $secret, 4, ' '))) ?></dd><dt>Type</dt><dd>Time-based, 6 digits</dd></dl>
  <p class="small muted">On a phone? <a href="<?= e($uri) ?>">Open in authenticator app</a></p>
</section>
<section class="card">
  <h2>2. Confirm</h2>
  <form method="post" action="<?= e(url('profile/2fa/enable')) ?>" class="form">
    <?= csrf_field() ?>
    <label>6-digit code from the app <input name="code" required inputmode="numeric" maxlength="6" autocomplete="one-time-code"></label>
    <label>Your password <input type="password" name="password" required autocomplete="current-password"></label>
    <button class="btn btn-primary">Turn on two-step verification</button>
  </form>
</section>
</div>
<?php endif; ?>
