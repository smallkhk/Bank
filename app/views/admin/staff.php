<div class="page-head"><h1>Staff</h1></div>
<section class="card">
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Name</th><th>Role</th><th>Assigned customers</th><th>Status</th><th>Last sign-in</th><?= can('staff.manage') ? '<th>Update</th>' : '' ?></tr></thead>
    <tbody><?php foreach ($staff as $u): ?>
      <tr><td><strong><?= e($u['full_name']) ?></strong><div class="muted small"><?= e($u['username']) ?> · <?= e($u['email']) ?></div></td>
        <td><?= e($u['role_names'] ?? '—') ?></td><td><?= (int) $u['assigned'] ?></td><td><?= status_badge($u['status']) ?></td><td><?= e(fmt_date($u['last_login_at'])) ?></td>
        <?php if (can('staff.manage')): ?><td>
          <?php if ((int) $u['id'] !== App\Core\Auth::id()): ?>
          <form method="post" action="<?= e(url('admin/staff/' . $u['id'])) ?>" class="inline-form"><?= csrf_field() ?>
            <select name="role_id"><?php foreach ($roles as $r): ?><option value="<?= (int) $r['id'] ?>" <?= in_array((string) $r['id'], explode(',', (string) $u['role_ids']), true) ? 'selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?></select>
            <select name="status"><?php foreach (['active', 'suspended', 'locked'] as $s): ?><option <?= $u['status'] === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select>
            <input name="reason" placeholder="Reason" required maxlength="255">
            <button class="btn btn-secondary btn-sm">Save</button>
          </form>
          <?php else: ?><span class="muted small">You</span><?php endif; ?>
        </td><?php endif; ?></tr>
    <?php endforeach; ?></tbody>
  </table></div>
</section>
<?php if (can('staff.manage')): ?>
<section class="card">
  <h2>Add staff member</h2>
  <form method="post" action="<?= e(url('admin/staff')) ?>" class="form grid-2">
    <?= csrf_field() ?>
    <label>Full name <input name="full_name" value="<?= e(old('full_name')) ?>" required></label>
    <label>Username <input name="username" value="<?= e(old('username')) ?>" required></label>
    <label>Email <input type="email" name="email" value="<?= e(old('email')) ?>" required></label>
    <label>Phone <input name="phone" value="<?= e(old('phone')) ?>"></label>
    <label>Role <select name="role_id" required><?php foreach ($roles as $r): ?><option value="<?= (int) $r['id'] ?>"><?= e($r['name']) ?></option><?php endforeach; ?></select></label>
    <label>Temporary password (min 12) <input type="password" name="password" required minlength="12" autocomplete="new-password"></label>
    <div class="span-2"><button class="btn btn-primary">Create staff member</button></div>
  </form>
</section>
<?php endif; ?>
