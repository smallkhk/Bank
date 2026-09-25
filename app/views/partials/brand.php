<a class="brand" href="<?= e(url('/')) ?>">
  <?php if (setting('logo_path')): ?>
    <img src="<?= e(url('branding/logo')) ?>" alt="" class="brand-logo">
  <?php else: ?>
    <span class="brand-mark" aria-hidden="true"><?= e(mb_substr(setting('bank_short_name') ?: bank_name(), 0, 1)) ?></span>
  <?php endif; ?>
  <span class="brand-name"><?= e(bank_name()) ?></span>
</a>
