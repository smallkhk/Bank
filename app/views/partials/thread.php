<?php /** @var array $messages @var bool $staffView */ ?>
<div class="thread">
<?php foreach ($messages as $m): $mine = ($m['user_type'] === 'staff') === $staffView; ?>
  <article class="msg <?= $m['user_type'] === 'staff' ? 'msg-staff' : 'msg-customer' ?> <?= $m['is_internal'] ? 'msg-internal' : '' ?>">
    <header><strong><?= e($staffView || $m['user_type'] === 'customer' ? $m['full_name'] : explode(' ', $m['full_name'])[0] . ' · ' . bank_name() . ' Support') ?></strong>
      <?php if ($m['is_internal']): ?><span class="badge badge-warning">Internal note</span><?php endif; ?>
      <span class="muted small"><?= e(fmt_date($m['created_at'])) ?></span></header>
    <div class="msg-body"><?= nl2br(e($m['body'])) ?></div>
    <?php if ($m['att_id']): ?><a class="att" href="<?= e(url('attachments/' . $m['att_id'])) ?>" target="_blank" rel="noopener">📎 <?= e($m['original_name']) ?> <span class="muted small">(<?= max(1, (int) round($m['att_size'] / 1024)) ?> KB)</span></a><?php endif; ?>
  </article>
<?php endforeach; ?>
</div>
