<div class="auth-wrap">
  <section class="auth-card">
    <h1>Sign in to online banking</h1>
    <p class="muted"><?= e(setting('login_message')) ?></p>
    <form method="post" action="<?= e(url('login')) ?>" class="form" autocomplete="on">
      <?= csrf_field() ?>
      <label>Username or email
        <input name="username" value="<?= e(old('username')) ?>" required autocomplete="username" autofocus>
      </label>
      <label>Password
        <input type="password" name="password" required autocomplete="current-password">
      </label>
      <button class="btn btn-primary btn-block">Sign in</button>
    </form>
    <p class="auth-alt"><a href="<?= e(url('forgot-password')) ?>">Forgot your password?</a></p>
    <?php if (!empty($_SESSION['show_resend'])): ?>
      <form method="post" action="<?= e(url('verify-email/resend')) ?>" class="center"><?= csrf_field() ?><button class="btn btn-ghost btn-sm">Resend verification email</button></form>
    <?php endif; ?>
    <?php if (setting('registration_enabled') === '1'): ?>
      <p class="auth-alt">New customer? <a href="<?= e(url('register')) ?>">Open an account</a></p>
    <?php endif; ?>
  </section>
  <aside class="auth-side">
    <h2>Banking, securely.</h2>
    <ul class="checks">
      <li>Encrypted sessions and automatic sign-out</li>
      <li>Every transaction recorded in an auditable ledger</li>
      <li>We will never ask for your password by phone or email</li>
    </ul>
  </aside>
</div>
