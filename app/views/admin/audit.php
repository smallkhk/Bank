<div class="page-head"><h1>Audit log</h1></div>
<section class="card">
  <form class="filters" method="get">
    <label>Action <input name="action" value="<?= e(input('action')) ?>" list="actions" placeholder="e.g. account."></label>
    <datalist id="actions"><?php foreach ($actions as $a): ?><option value="<?= e($a) ?>"><?php endforeach; ?></datalist>
    <label>User <input name="user" value="<?= e(input('user')) ?>"></label>
    <label>Target type <input name="target_type" value="<?= e(input('target_type')) ?>" size="10"></label>
    <label>Target ID <input name="target_id" value="<?= e(input('target_id')) ?>" size="6"></label>
    <label>IP <input name="ip" value="<?= e(input('ip')) ?>" size="12"></label>
    <label>From <input type="date" name="from" value="<?= e(input('from')) ?>"></label>
    <label>To <input type="date" name="to" value="<?= e(input('to')) ?>"></label>
    <button class="btn btn-secondary btn-sm">Filter</button>
  </form>
  <div class="table-wrap"><table class="table small">
    <thead><tr><th>When</th><th>User</th><th>Action</th><th>Target</th><th>Old → New</th><th>Reason</th><th>IP / agent</th></tr></thead>
    <tbody><?php foreach ($rows as $r): ?>
      <tr><td class="nowrap"><?= e(fmt_date($r['created_at'])) ?></td><td><?= e($r['full_name'] ?? 'system') ?><div class="muted"><?= e($r['username'] ?? '') ?></div></td>
        <td class="mono"><?= e($r['action']) ?></td><td class="mono"><?= e(($r['target_type'] ?? '') . ($r['target_id'] ? ' #' . $r['target_id'] : '')) ?></td>
        <td class="mono wrap"><?= $r['old_value'] !== null ? e(mb_strimwidth($r['old_value'], 0, 120, '…')) . ' → ' : '' ?><?= e(mb_strimwidth((string) $r['new_value'], 0, 160, '…')) ?></td>
        <td><?= e($r['reason'] ?? '') ?></td><td class="mono"><?= e($r['ip_address']) ?><div class="muted" title="<?= e($r['user_agent']) ?>"><?= e(mb_strimwidth((string) $r['user_agent'], 0, 30, '…')) ?></div></td></tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="7" class="empty">No entries.</td></tr><?php endif; ?></tbody>
  </table></div>
  <?php include APP_PATH . '/views/partials/pager.php'; ?>
</section>
