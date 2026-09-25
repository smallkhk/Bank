<div class="card narrow center">
  <h1><?= e($title) ?></h1>
  <p><?= e($message) ?></p>
  <?php if (!empty($errorId)): ?><p class="muted small">Reference: <?= e($errorId) ?></p><?php endif; ?>
  <?php if (!empty($debug)): ?><pre class="debug"><?= e($debug) ?></pre><?php endif; ?>
  <a class="btn btn-primary" href="<?= e(url('/')) ?>">Return home</a>
</div>
