<div class="page-head"><h1>Notification templates</h1>
  <p class="muted">Placeholders like <code>{{name}}</code>, <code>{{amount}}</code>, <code>{{account}}</code>, <code>{{reference}}</code>, <code>{{bank_name}}</code> are filled in automatically.
  Email delivery: <?= $mailEnabled ? '<span class="badge badge-success">enabled (' . e($mailDriver) . ')</span>' : '<span class="badge badge-muted">disabled — see Settings → Email</span>' ?></p></div>
<?php foreach ($templates as $t): preg_match_all('/\{\{\s*(\w+)\s*\}\}/', App\Services\NotificationService::DEFAULTS[$t['event']][2] ?? '', $ph); ?>
<section class="card" id="t<?= (int) $t['id'] ?>">
  <div class="card-head"><h2><?= e($t['name']) ?> <span class="muted small mono"><?= e($t['event']) ?></span></h2>
    <form method="post" action="<?= e(url('admin/templates/' . $t['id'] . '/reset')) ?>"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" data-confirm="Restore the default text?">Restore default</button></form></div>
  <form method="post" action="<?= e(url('admin/templates/' . $t['id'])) ?>" class="form">
    <?= csrf_field() ?>
    <label>Subject / title <input name="subject" value="<?= e($t['subject']) ?>" maxlength="190"></label>
    <label>Body <textarea name="body" rows="4" maxlength="5000"><?= e($t['body']) ?></textarea>
      <?php if ($ph[1]): ?><small class="muted">Available: <?= e(implode(', ', array_map(fn ($p) => '{{' . $p . '}}', array_unique([...$ph[1], 'name', 'bank_name'])))) ?></small><?php endif; ?></label>
    <?php if (!in_array($t['event'], ['password_reset', 'email_verify'], true)): ?>
    <div class="inline-form"><label class="check"><input type="checkbox" name="send_inapp" value="1" <?= $t['send_inapp'] ? 'checked' : '' ?>> In-app</label>
      <label class="check"><input type="checkbox" name="send_email" value="1" <?= $t['send_email'] ? 'checked' : '' ?>> Email</label></div>
    <?php else: ?><p class="muted small">Always sent by email only.</p><?php endif; ?>
    <div><button class="btn btn-secondary btn-sm">Save</button></div>
  </form>
</section>
<?php endforeach; ?>
