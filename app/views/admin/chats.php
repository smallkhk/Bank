<div class="page-head"><h1>Live chat</h1><a class="btn btn-secondary" href="<?= e(url('admin/support')) ?>">Tickets</a></div>
<nav class="tabs"><a href="?status=open" class="<?= $status === 'open' ? 'active' : '' ?>">Open</a><a href="?status=closed" class="<?= $status === 'closed' ? 'active' : '' ?>">Closed</a></nav>
<section class="card">
  <form class="filters" method="get"><input type="hidden" name="status" value="<?= e($status) ?>"><label>Search conversations <input name="q" value="<?= e(input('q')) ?>" placeholder="Customer or message text"></label><button class="btn btn-secondary btn-sm">Search</button></form>
  <?php if (!$rows): ?><p class="empty">No conversations.</p><?php endif; ?>
  <?php foreach ($rows as $c): ?>
    <a class="list-row link-row <?= $c['unread'] ? 'unread' : '' ?>" href="<?= e(url('admin/chats/' . $c['id'])) ?>">
      <div><strong><?= e($c['customer_name']) ?></strong> <?= $c['unread'] ? '<span class="badge badge-danger">' . (int) $c['unread'] . ' unread</span>' : '' ?>
        <div class="muted small"><?= e(mb_strimwidth((string) $c['last_body'], 0, 90, '…')) ?></div></div>
      <div class="right small muted"><?= e(fmt_date($c['last_message_at'])) ?><br><?= e($c['assignee'] ?? 'Unassigned') ?></div>
    </a>
  <?php endforeach; ?>
</section>
