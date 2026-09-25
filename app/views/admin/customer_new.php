<div class="page-head"><div><a class="back" href="<?= e(url('admin/customers')) ?>">← Customers</a><h1>New customer</h1></div></div>
<section class="card">
  <form method="post" action="<?= e(url('admin/customers')) ?>" class="form grid-2">
    <?= csrf_field() ?>
    <label class="span-2">Full name <input name="full_name" value="<?= e(old('full_name')) ?>" required></label>
    <label>Username <input name="username" value="<?= e(old('username')) ?>" required></label>
    <label>Email <input type="email" name="email" value="<?= e(old('email')) ?>" required></label>
    <label>Phone <input name="phone" value="<?= e(old('phone')) ?>"></label>
    <label>Date of birth <input type="date" name="date_of_birth" value="<?= e(old('date_of_birth')) ?>"></label>
    <label>Address <input name="address" value="<?= e(old('address')) ?>"></label>
    <label>Country <input name="country" value="<?= e(old('country')) ?>"></label>
    <label>Temporary password <input type="password" name="password" required autocomplete="new-password"></label>
    <label>Open account <select name="account_type"><option value="">Do not open an account</option><?php foreach ($types as $t): ?><option value="<?= e($t['slug']) ?>" <?= $t['slug'] === setting('default_account_type') ? 'selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?></select></label>
    <div class="span-2"><button class="btn btn-primary">Create customer</button></div>
  </form>
</section>
