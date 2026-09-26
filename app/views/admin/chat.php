<div class="page-head"><div><a class="back" href="<?= e(url('admin/chats')) ?>">← Live chat</a>
  <h1><?= e($conv['customer_name']) ?> <?= status_badge($conv['status'] === 'open' ? 'active' : 'closed') ?></h1>
  <?php if ($conv['subject']): ?><p class="muted"><?= e($conv['subject']) ?></p><?php endif; ?>
  <p class="muted"><a href="<?= e(url('admin/customers/' . $conv['customer_id'])) ?>">Customer <?= e($conv['customer_number']) ?></a></p></div>
  <form method="post" action="<?= e(url('admin/chats/' . $conv['id'] . '/update')) ?>" class="inline-form"><?= csrf_field() ?>
    <select name="assigned_to"><option value="">Unassigned</option><?php foreach ($staff as $s): ?><option value="<?= (int) $s['id'] ?>" <?= (int) $conv['assigned_to'] === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['full_name']) ?></option><?php endforeach; ?></select>
    <select name="status"><option value="open" <?= $conv['status'] === 'open' ? 'selected' : '' ?>>Open</option><option value="closed" <?= $conv['status'] === 'closed' ? 'selected' : '' ?>>Closed</option></select>
    <button class="btn btn-secondary btn-sm">Save</button></form></div>
<section class="card chat" data-chat data-att="<?= e(url('attachments')) ?>" data-poll="<?= e(url('admin/chats/' . $conv['id'] . '/messages')) ?>" data-send="<?= e(url('admin/chats/' . $conv['id'])) ?>" data-me="staff">
  <div class="chat-log" data-log aria-live="polite"><p class="empty" data-empty>No messages yet.</p></div>
  <form method="post" action="<?= e(url('admin/chats/' . $conv['id'])) ?>" class="chat-form" enctype="multipart/form-data" data-chat-form>
    <?= csrf_field() ?>
    <textarea name="message" rows="2" maxlength="4000" placeholder="Reply to customer…" aria-label="Message"></textarea>
    <div class="chat-actions">
      <label class="check small"><input type="checkbox" name="internal" value="1"> Internal note</label>
      <label class="att-btn" title="Attach a file">📎<input type="file" name="attachment" accept=".png,.jpg,.jpeg,.webp,.pdf,.txt" hidden></label>
      <button class="btn btn-primary">Send</button>
    </div>
  </form>
</section>
