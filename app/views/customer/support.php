<div class="page-head"><div><div class="eyebrow">Support</div><h1>Support requests</h1></div>
  <?php if (setting('chat_enabled') === '1'): ?><a class="btn btn-primary" href="<?= e(url('chat')) ?>"><?= icon('chat', 17) ?> Live chat<?= $chatUnread ? ' (' . $chatUnread . ' new)' : '' ?></a><?php endif; ?></div>
<div class="two-col">
<section class="card">
  <h2>New support request</h2>
  <form method="post" action="<?= e(url('support')) ?>" class="form" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <label>Category <select name="category" required><option value="">Choose…</option><?php foreach ($categories as $c): ?><option <?= old('category') === $c ? 'selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?></select></label>
    <label>Subject <input name="subject" value="<?= e(old('subject')) ?>" required maxlength="190"></label>
    <label>Message <textarea name="message" rows="5" required maxlength="10000"><?= e(old('message')) ?></textarea></label>
    <label>Attachment (optional) <input type="file" name="attachment" accept=".png,.jpg,.jpeg,.webp,.pdf,.txt"><small class="muted">PNG, JPG, PDF or TXT up to 5 MB. Never send card numbers or passwords.</small></label>
    <button class="btn btn-primary">Submit request</button>
  </form>
</section>
<section class="card">
  <h2>Your requests</h2>
  <?php if (!$tickets): ?><p class="empty">You have no support requests.</p><?php endif; ?>
  <?php foreach ($tickets as $t): ?>
    <a class="list-row link-row" href="<?= e(url('support/' . $t['id'])) ?>">
      <div><strong><?= e($t['subject']) ?></strong><div class="muted small"><?= e($t['reference']) ?> · <?= e($t['category']) ?> · updated <?= e(fmt_date($t['updated_at'])) ?></div></div>
      <div><?= status_badge($t['status'] === 'pending' ? 'awaiting you' : $t['status']) ?><?= $t['last_reply_by'] === 'staff' && !in_array($t['status'], ['closed'], true) ? ' <span class="badge badge-info">New reply</span>' : '' ?></div>
    </a>
  <?php endforeach; ?>
</section>
</div>
