<?php if ($pager['pages'] > 1): ?>
<nav class="pager" aria-label="Pagination">
  <?php if ($pager['page'] > 1): ?><a href="<?= e(page_url($pager['page'] - 1)) ?>">← Previous</a><?php endif; ?>
  <span>Page <?= $pager['page'] ?> of <?= $pager['pages'] ?> · <?= number_format($pager['total']) ?> records</span>
  <?php if ($pager['page'] < $pager['pages']): ?><a href="<?= e(page_url($pager['page'] + 1)) ?>">Next →</a><?php endif; ?>
</nav>
<?php endif; ?>
