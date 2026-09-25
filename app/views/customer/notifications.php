<div class="page-head"><h1>Notifications</h1></div>
<section class="card">
  <?php if (!$items): ?><p class="empty">You're all caught up.</p><?php endif; ?>
  <?php foreach ($items as $n): ?>
    <div class="list-row <?= $n['read_at'] ? '' : 'unread' ?>">
      <div><strong><?= e($n['title']) ?></strong><?php if ($n['body']): ?><div><?= e($n['body']) ?></div><?php endif; ?>
        <div class="muted small"><?= e(fmt_date($n['created_at'])) ?></div></div>
      <?php if ($n['link']): ?><a class="btn btn-ghost btn-sm" href="<?= e(url($n['link'])) ?>">View</a><?php endif; ?>
    </div>
  <?php endforeach; ?>
</section>
