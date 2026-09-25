<section class="auth-card narrow-auth">
  <h1>Choose a new password</h1>
  <form method="post" action="<?= e(url('reset-password/' . $token)) ?>" class="form">
    <?= csrf_field() ?>
    <label>New password <input type="password" name="password" required minlength="<?= (int) setting('password_min_length') ?>" autocomplete="new-password">
      <small class="muted">At least <?= (int) setting('password_min_length') ?> characters, with letters and numbers.</small></label>
    <label>Confirm new password <input type="password" name="password_confirmation" required autocomplete="new-password"></label>
    <button class="btn btn-primary btn-block">Update password</button>
  </form>
  <p class="muted small">All other signed-in devices will be signed out.</p>
</section>
