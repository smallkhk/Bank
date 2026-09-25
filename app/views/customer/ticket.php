<div class="page-head"><div><a class="back" href="<?= e(url('support')) ?>">← Support</a>
  <h1><?= e($t['subject']) ?> <?= status_badge($t['status']) ?></h1><p class="muted"><?= e($t['reference']) ?> · <?= e($t['category']) ?> · opened <?= e(fmt_date($t['created_at'])) ?></p></div>
  <?php if (!in_array($t['status'], ['closed'], true)): ?>
  <form method="post" action="<?= e(url('support/' . $t['id'] . '/close')) ?>"><?= csrf_field() ?><button class="btn btn-ghost" data-confirm="Close this request?">Close request</button></form>
  <?php endif; ?></div>
<section class="card"><?php $staffView = false; include APP_PATH . '/views/partials/thread.php'; ?></section>
<?php if ($t['status'] !== 'closed'): ?>
<section class="card">
  <form method="post" action="<?= e(url('support/' . $t['id'] . '/reply')) ?>" class="form" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <label>Your reply <textarea name="message" rows="4" maxlength="10000"><?= e(old('message')) ?></textarea></label>
    <label>Attachment (optional) <input type="file" name="attachment" accept=".png,.jpg,.jpeg,.webp,.pdf,.txt"></label>
    <button class="btn btn-primary">Send reply</button>
  </form>
</section>
<?php endif; ?>
