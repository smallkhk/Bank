<?php /** @var array $conversations @var ?array $conv @var bool $online */ ?>
<div class="page-head">
  <div><div class="eyebrow">Support</div><h1>Chat with us</h1></div>
  <div class="actions-inline">
    <span class="presence <?= $online ? 'on' : '' ?>" data-presence><span class="live-dot"></span><span data-presence-text><?= $online ? 'Support is online' : 'We reply as soon as we can' ?></span></span>
    <?php if ($ticketsEnabled): ?><a class="btn btn-ghost btn-sm" href="<?= e(url('support')) ?>">Support requests</a><?php endif; ?>
  </div>
</div>

<div class="chat-app">
  <aside class="chat-list" aria-label="Your chats">
    <form method="post" action="<?= e(url('chat/new')) ?>" class="chat-new"><?= csrf_field() ?>
      <input id="chat-subject" name="subject" maxlength="190" placeholder="What do you need help with?" aria-label="Topic of a new chat">
      <button class="btn btn-primary btn-sm"><?= icon('plus', 16) ?> New chat</button>
    </form>
    <?php if (!$conversations): ?><p class="empty small">No chats yet. Start one above.</p><?php endif; ?>
    <?php foreach ($conversations as $c): $sel = $conv && (int) $conv['id'] === (int) $c['id']; ?>
      <a href="<?= e(url('chat/' . $c['id'])) ?>" class="chat-item <?= $sel ? 'active' : '' ?>" data-conv="<?= (int) $c['id'] ?>">
        <span class="chat-item-top"><strong><?= e($c['subject'] ?: 'Chat #' . $c['id']) ?></strong>
          <span class="count" data-conv-unread<?= (int) $c['unread'] && !$sel ? '' : ' hidden' ?>><?= (int) $c['unread'] ?></span></span>
        <span class="muted small chat-item-last"><?= e(mb_strimwidth((string) ($c['last_body'] ?? 'No messages yet'), 0, 60, '…')) ?></span>
        <span class="muted small"><?= $c['status'] === 'closed' ? 'Ended · ' : '' ?><?= e(fmt_date($c['last_message_at'] ?? $c['created_at'], 'M j, g:i A')) ?></span>
      </a>
    <?php endforeach; ?>
  </aside>

  <section class="chat-pane">
    <?php if (!$conv): ?>
      <div class="chat-empty">
        <span class="chat-empty-ico"><?= icon('chat', 30) ?></span>
        <h2>How can we help?</h2>
        <p class="muted">Start a chat and a member of our team will reply here. Your chats are saved, so you can come back and continue any time.</p>
      </div>
    <?php else: ?>
      <header class="chat-head">
        <div><strong><?= e($conv['subject'] ?: 'Chat #' . $conv['id']) ?></strong>
          <div class="muted small" data-conv-status><?= $conv['status'] === 'closed' ? 'Ended. Send a message to reopen.' : 'Started ' . e(fmt_date($conv['created_at'], 'M j, g:i A')) ?></div></div>
        <?php if ($conv['status'] === 'open'): ?>
          <form method="post" action="<?= e(url('chat/' . $conv['id'] . '/close')) ?>"><?= csrf_field() ?><button class="btn btn-ghost btn-sm">End chat</button></form>
        <?php endif; ?>
      </header>
      <div class="chat" data-chat data-att="<?= e(url('attachments')) ?>" data-poll="<?= e(url('chat/' . $conv['id'] . '/messages')) ?>" data-send="<?= e(url('chat/' . $conv['id'])) ?>" data-me="customer">
        <div class="chat-log" data-log aria-live="polite"><p class="empty" data-empty>Send a message to start the conversation.</p></div>
        <form method="post" action="<?= e(url('chat/' . $conv['id'])) ?>" class="chat-form" enctype="multipart/form-data" data-chat-form>
          <?= csrf_field() ?>
          <textarea id="chat-message" name="message" rows="2" maxlength="4000" placeholder="Type your message…" aria-label="Message"></textarea>
          <div class="chat-actions">
            <span class="muted small">Never share your password or one-time codes.</span>
            <label class="att-btn" title="Attach a file"><?= icon('file', 18) ?><input type="file" name="attachment" accept=".png,.jpg,.jpeg,.webp,.pdf,.txt" hidden></label>
            <button class="btn btn-primary">Send</button>
          </div>
        </form>
      </div>
    <?php endif; ?>
  </section>
</div>
