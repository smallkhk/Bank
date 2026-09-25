<div class="auth-wrap">
  <section class="auth-card">
    <div class="eyebrow">Online banking</div>
    <h1>Sign in to your account</h1>
    <p class="muted"><?= e(setting('login_message')) ?></p>
    <form method="post" action="<?= e(url('login')) ?>" class="form" autocomplete="on">
      <?= csrf_field() ?>
      <label>Username or email
        <input id="login-username" name="username" value="<?= e(old('username')) ?>" required autocomplete="username" autofocus>
      </label>
      <label>Password
        <input id="login-password" type="password" name="password" required autocomplete="current-password">
      </label>
      <button class="btn btn-primary btn-block"><?= icon('lock', 17) ?> Sign in</button>
    </form>
    <p class="auth-alt"><a href="<?= e(url('forgot-password')) ?>">Forgot your password?</a></p>
    <?php if (!empty($_SESSION['show_resend'])): ?>
      <form method="post" action="<?= e(url('verify-email/resend')) ?>" class="center"><?= csrf_field() ?><button class="btn btn-ghost btn-sm">Resend verification email</button></form>
    <?php endif; ?>
    <?php if (setting('registration_enabled') === '1'): ?>
      <p class="auth-alt">New to <?= e(bank_name()) ?>? <a href="<?= e(url('register')) ?>">Open an account</a></p>
    <?php endif; ?>
  </section>
  <aside class="auth-side" aria-hidden="true">
    <h2>Your money, clearly in view.</h2>
    <ul class="checks">
      <li>Balances and activity the moment they happen</li>
      <li>Instant transfers between <?= e(bank_name()) ?> accounts</li>
      <li>Card controls, alerts and two-step sign-in</li>
    </ul>
    <div class="auth-mock">
      <div class="row"><span class="ico"><?= icon('in') ?></span><div><b>Salary received</b><small>Today · Checking</small></div><span class="amt">+<?= e(setting('currency_symbol', '$')) ?>3,250.00</span></div>
      <div class="row"><span class="ico"><?= icon('card') ?></span><div><b>Card payment</b><small>Groceries</small></div><span class="amt">−<?= e(setting('currency_symbol', '$')) ?>64.20</span></div>
      <div class="row"><span class="ico"><?= icon('shield') ?></span><div><b>New sign-in secured</b><small>Two-step verification</small></div></div>
    </div>
  </aside>
</div>
