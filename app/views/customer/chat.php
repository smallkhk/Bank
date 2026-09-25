<div class="page-head"><div><a class="back" href="<?= e(url('support')) ?>">← Support</a><h1>Chat with us</h1>
  <p class="muted">Our team typically replies within a few minutes during business hours.</p></div></div>
<section class="card chat" data-chat data-att="<?= e(url('attachments')) ?>" data-poll="<?= e(url('chat/messages')) ?>" data-send="<?= e(url('chat')) ?>" data-me="customer">
  <div class="chat-log" data-log aria-live="polite"><p class="empty" data-empty>Send us a message to start the conversation.</p></div>
  <form method="post" action="<?= e(url('chat')) ?>" class="chat-form" enctype="multipart/form-data" data-chat-form>
    <?= csrf_field() ?>
    <textarea name="message" rows="2" maxlength="4000" placeholder="Type your message…" aria-label="Message"></textarea>
    <div class="chat-actions">
      <label class="att-btn" title="Attach a file">📎<input type="file" name="attachment" accept=".png,.jpg,.jpeg,.webp,.pdf,.txt" hidden></label>
      <button class="btn btn-primary">Send</button>
    </div>
  </form>
  <p class="muted small">For your security, never share your password or one-time codes in chat.</p>
</section>
