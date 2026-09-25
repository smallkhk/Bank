<?php $views = ['active' => 'Active', 'mine' => 'Assigned to me', 'unassigned' => 'Unassigned', 'overdue' => 'Overdue (SLA)', 'escalated' => 'Escalated', 'resolved' => 'Resolved', 'closed' => 'Closed']; ?>
<div class="page-head"><h1>Support tickets</h1><a class="btn btn-secondary" href="<?= e(url('admin/chats')) ?>">Live chat</a></div>
<nav class="tabs"><?php foreach ($views as $k => $l): ?><a href="?view=<?= $k ?>" class="<?= $view === $k ? 'active' : '' ?>"><?= $l ?></a><?php endforeach; ?></nav>
<section class="card">
  <form class="filters" method="get"><input type="hidden" name="view" value="<?= e($view) ?>">
    <label>Search <input name="q" value="<?= e(input('q')) ?>" placeholder="Reference, subject, customer"></label>
    <label>Category <select name="category"><option value="">All</option><?php foreach ($categories as $c): ?><option <?= input('category') === $c ? 'selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?></select></label>
    <button class="btn btn-secondary btn-sm">Filter</button></form>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Ticket</th><th>Customer</th><th>Category</th><th>Priority</th><th>Status</th><th>Assignee</th><th>Last reply</th></tr></thead>
    <tbody><?php foreach ($rows as $t): $overdue = $t['last_reply_by'] === 'customer' && !in_array($t['status'], ['resolved', 'closed', 'pending'], true) && strtotime($t['due_at'] . ' UTC') < time(); ?>
      <tr><td><a href="<?= e(url('admin/support/' . $t['id'])) ?>"><?= e($t['subject']) ?></a><div class="muted small mono"><?= e($t['reference']) ?></div></td>
        <td><?= e($t['customer_name']) ?></td><td><?= e($t['category']) ?></td>
        <td><span class="badge badge-<?= ['urgent' => 'danger', 'high' => 'warning', 'normal' => 'muted', 'low' => 'muted'][$t['priority']] ?>"><?= e($t['priority']) ?></span></td>
        <td><?= status_badge($t['status']) ?><?= $overdue ? ' <span class="badge badge-danger">Overdue</span>' : '' ?></td>
        <td><?= e($t['assignee'] ?? '—') ?></td>
        <td class="nowrap"><?= e(fmt_date($t['last_reply_at'])) ?><div class="muted small">by <?= e($t['last_reply_by']) ?></div></td></tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="7" class="empty">No tickets.</td></tr><?php endif; ?></tbody>
  </table></div>
  <?php include APP_PATH . '/views/partials/pager.php'; ?>
</section>
