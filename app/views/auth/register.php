<section class="card register-card">
  <h1>Open an account</h1>
  <p class="muted">Complete your details below. Your profile will be reviewed before activation.</p>
  <form method="post" action="<?= e(url('register')) ?>" class="form grid-2">
    <?= csrf_field() ?>
    <label class="span-2">Full legal name <input name="full_name" value="<?= e(old('full_name')) ?>" required autocomplete="name"></label>
    <label>Username <input name="username" value="<?= e(old('username')) ?>" required pattern="[A-Za-z0-9_.\-]{3,60}" autocomplete="username"></label>
    <label>Email <input type="email" name="email" value="<?= e(old('email')) ?>" required autocomplete="email"></label>
    <label>Phone <input type="tel" name="phone" value="<?= e(old('phone')) ?>" autocomplete="tel"></label>
    <label>Date of birth <input type="date" name="date_of_birth" value="<?= e(old('date_of_birth')) ?>" required></label>
    <label class="span-2">Address <input name="address" value="<?= e(old('address')) ?>" autocomplete="street-address"></label>
    <label>City <input name="city" value="<?= e(old('city')) ?>" autocomplete="address-level2"></label>
    <label>Country <input name="country" value="<?= e(old('country')) ?>" required autocomplete="country-name"></label>
    <label>Password <input type="password" name="password" required minlength="<?= (int) setting('password_min_length') ?>" autocomplete="new-password">
      <small class="muted">At least <?= (int) setting('password_min_length') ?> characters, with letters and numbers.</small></label>
    <label>Confirm password <input type="password" name="password_confirmation" required autocomplete="new-password"></label>
    <label class="check span-2"><input type="checkbox" name="terms" value="1" required> I have read and accept the <a href="<?= e(url('terms')) ?>" target="_blank">terms and conditions</a> and <a href="<?= e(url('privacy')) ?>" target="_blank">privacy policy</a>.</label>
    <div class="span-2"><button class="btn btn-primary">Submit application</button> <a class="btn btn-ghost" href="<?= e(url('login')) ?>">Back to sign in</a></div>
  </form>
</section>
