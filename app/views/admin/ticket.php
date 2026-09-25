<div class="page-head"><div><a class="back" href="<?= e(url('admin/support')) ?>">← Tickets</a>
  <h1><?= e($t['subject']) ?> <?= status_badge($t['status']) ?></h1>
  <p class="muted"><span class="mono"><?= e($t['reference']) ?></span> · <?= e($t['category']) ?> · opened <?= e(fmt_date($t['created_at'])) ?> · SLA due <?= e(fmt_date($t['due_at'])) ?></p></div></div>
<div class="ticket-layout">
  <div>
    <section class="card"><?php $staffView = true; include APP_PATH . '/views/partials/thread.php'; ?></section>
    <?php if (can('support.manage') || (int) $t['assigned_to'] === App\Core\Auth::id()): ?>
    <section class="card">
      <form method="post" action="<?= e(url('admin/support/' . $t['id'] . '/reply')) ?>" class="form" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <label>Message <textarea name="message" rows="5" maxlength="10000"></textarea></label>
        <label>Attachment <input type="file" name="attachment" accept=".png,.jpg,.jpeg,.webp,.pdf,.txt"></label>
        <label class="check"><input type="checkbox" name="internal" value="1"> Internal note (not visible to the customer)</label>
        <label>After sending <select name="then_status"><option value="pending">Set to pending (awaiting customer)</option><option value="resolved">Mark resolved</option><option value="">Keep status</option></select></label>
        <button class="btn btn-primary">Send</button>
      </form>
    </section>
    <?php endif; ?>
  </div>
  <aside>
    <section class="card">
      <h2>Customer</h2>
      <dl class="kv"><dt>Name</dt><dd><a href="<?= e(url('admin/customers/' . $t['customer_id'])) ?>"><?= e($t['customer_name']) ?></a></dd>
        <dt>Number</dt><dd class="mono"><?= e($t['customer_number']) ?></dd><dt>Email</dt><dd><?= e($t['customer_email']) ?></dd></dl>
      <?php foreach ($accounts as $a): ?><div class="list-row"><a class="mono" href="<?= e(url('admin/accounts/' . $a['id'])) ?>"><?= e($a['account_number']) ?></a><span><?= status_badge($a['status']) ?></span></div><?php endforeach; ?>
    </section>
    <?php if (can('support.manage') || (int) $t['assigned_to'] === App\Core\Auth::id()): ?>
    <section class="card">
      <h2>Manage</h2>
      <form method="post" action="<?= e(url('admin/support/' . $t['id'])) ?>" class="form">
        <?= csrf_field() ?>
        <label>Assigned to <select name="assigned_to"><option value="">Unassigned</option><?php foreach ($staff as $s): ?><option value="<?= (int) $s['id'] ?>" <?= (int) $t['assigned_to'] === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['full_name']) ?></option><?php endforeach; ?></select></label>
        <label>Status <select name="status"><?php foreach (App\Services\SupportService::STATUSES as $s): ?><option <?= $t['status'] === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select></label>
        <label>Priority <select name="priority"><?php foreach (App\Services\SupportService::PRIORITIES as $p): ?><option <?= $t['priority'] === $p ? 'selected' : '' ?>><?= $p ?></option><?php endforeach; ?></select></label>
        <label>Note for audit (optional) <input name="reason" maxlength="255"></label>
        <button class="btn btn-secondary btn-sm">Update ticket</button>
      </form>
    </section>
    <?php endif; ?>
  </aside>
</div>
