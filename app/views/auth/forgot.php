<section class="auth-card narrow-auth">
  <h1>Reset your password</h1>
  <p class="muted">Enter the email address on your profile. If it matches an account, we will send you a secure link to choose a new password.</p>
  <form method="post" action="<?= e(url('forgot-password')) ?>" class="form">
    <?= csrf_field() ?>
    <label>Email <input type="email" name="email" required autocomplete="email" autofocus></label>
    <button class="btn btn-primary btn-block">Send reset link</button>
  </form>
  <p class="auth-alt"><a href="<?= e(url('login')) ?>">Back to sign in</a></p>
</section>
