<?php foreach (['success', 'error', 'info'] as $type): if ($msg = flash($type)): ?>
  <div class="alert alert-<?= $type ?>" role="<?= $type === 'error' ? 'alert' : 'status' ?>"><?= e($msg) ?></div>
<?php endif; endforeach; ?>
