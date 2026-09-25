<?php foreach (['success', 'error', 'info'] as $type): if ($msg = flash($type)): ?>
  <div class="alert alert-<?= $type ?>" role="<?= $type === 'error' ? 'alert' : 'status' ?>"<?= $type === 'success' ? ' data-autohide' : '' ?>><span><?= e($msg) ?></span></div>
<?php endif; endforeach; ?>
