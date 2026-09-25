<section class="auth-card narrow-auth">
  <h1>Two-step verification</h1>
  <p class="muted">Enter the 6-digit code from your authenticator app. If you have lost access to it, enter one of your recovery codes.</p>
  <form method="post" action="<?= e(url('login/2fa')) ?>" class="form">
    <?= csrf_field() ?>
    <label>Verification code <input name="code" required autocomplete="one-time-code" inputmode="numeric" maxlength="9" autofocus></label>
    <button class="btn btn-primary btn-block">Verify</button>
  </form>
  <p class="auth-alt"><a href="<?= e(url('login')) ?>">Cancel</a></p>
</section>
