<div class="page-head"><h1>Profile &amp; security</h1></div>
<div class="two-col">
<section class="card">
  <h2>Profile</h2>
  <dl class="kv">
    <dt>Name</dt><dd><?= e($user['full_name']) ?></dd>
    <dt>Username</dt><dd><?= e($user['username']) ?></dd>
    <dt>Email</dt><dd><?= e($user['email']) ?>
      <?php if ($user['email_verified_at']): ?><span class="badge badge-success">Verified</span>
      <?php else: ?><span class="badge badge-warning">Not verified</span>
        <form method="post" action="<?= e(url('profile/verify-email')) ?>" class="inline"><?= csrf_field() ?><button class="linklike">Send verification link</button></form><?php endif; ?></dd>
    <dt>Phone</dt><dd><?= e($user['phone'] ?: '—') ?></dd>
    <?php if ($customer): ?>
      <dt>Customer number</dt><dd class="mono"><?= e($customer['customer_number']) ?></dd>
      <dt>Address</dt><dd><?= e(trim(($customer['address'] ?? '') . ', ' . ($customer['city'] ?? '') . ', ' . ($customer['country'] ?? ''), ', ') ?: '—') ?></dd>
    <?php endif; ?>
    <dt>Last sign-in</dt><dd><?= e(fmt_date($user['last_login_at'])) ?> from <?= e($user['last_login_ip'] ?? '—') ?></dd>
  </dl>
  <p class="muted small">To update your personal details, please contact support.</p>
</section>
<section class="card">
  <h2>Change password</h2>
  <form method="post" action="<?= e(url('profile/password')) ?>" class="form">
    <?= csrf_field() ?>
    <label>Current password <input type="password" name="current_password" required autocomplete="current-password"></label>
    <label>New password <input type="password" name="password" required minlength="<?= (int) setting('password_min_length') ?>" autocomplete="new-password"></label>
    <label>Confirm new password <input type="password" name="password_confirmation" required autocomplete="new-password"></label>
    <button class="btn btn-primary">Update password</button>
  </form>
</section>
</div>
<section class="card">
  <div class="card-head"><h2>Two-step verification</h2><a class="btn btn-secondary btn-sm" href="<?= e(url('profile/2fa')) ?>"><?= $user['twofa_enabled_at'] ? 'Manage' : 'Set up' ?></a></div>
  <p class="muted"><?= $user['twofa_enabled_at']
    ? '<span class="badge badge-success">On</span> A code from your authenticator app is required each time you sign in.'
    : '<span class="badge badge-warning">Off</span> Protect your account with a one-time code from an authenticator app.' ?></p>
</section>
<section class="card">
  <div class="card-head"><h2>Active sessions</h2>
    <form method="post" action="<?= e(url('profile/sessions/revoke')) ?>"><?= csrf_field() ?><button class="btn btn-secondary btn-sm" data-confirm="Sign out of all other devices?">Sign out other devices</button></form></div>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Device</th><th>IP address</th><th>Signed in</th><th>Last active</th></tr></thead>
    <tbody><?php foreach ($sessions as $s): ?>
      <tr><td><?= e(mb_strimwidth($s['user_agent'] ?? 'Unknown', 0, 70, '…')) ?> <?= (int) $s['id'] === (int) $currentSession ? '<span class="badge badge-success">This device</span>' : '' ?></td>
        <td class="mono"><?= e($s['ip_address']) ?></td><td><?= e(fmt_date($s['created_at'])) ?></td><td><?= e(fmt_date($s['last_seen_at'])) ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
</section>
