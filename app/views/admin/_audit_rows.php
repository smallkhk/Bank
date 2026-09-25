<?php if (!$audit): ?><p class="empty">No entries.</p><?php else: ?>
<div class="table-wrap"><table class="table small">
  <thead><tr><th>When</th><th>Who</th><th>Action</th><th>Change</th><th>Reason</th></tr></thead>
  <tbody><?php foreach ($audit as $r): ?>
    <tr><td class="nowrap"><?= e(fmt_date($r['created_at'])) ?></td><td><?= e($r['full_name'] ?? 'system') ?></td><td class="mono"><?= e($r['action']) ?></td>
      <td class="mono wrap"><?= $r['old_value'] !== null ? e(mb_strimwidth($r['old_value'], 0, 80, '…')) . ' → ' : '' ?><?= e(mb_strimwidth((string) $r['new_value'], 0, 120, '…')) ?></td>
      <td><?= e($r['reason'] ?? '') ?></td></tr>
  <?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>
