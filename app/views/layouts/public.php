<!doctype html>
<html lang="en">
<head><?php include APP_PATH . '/views/partials/head.php'; ?></head>
<body class="public bg-<?= e(setting('bg_style', 'aurora')) ?>">
  <?php include APP_PATH . '/views/partials/sandbox.php'; ?>
  <header class="public-header"><?php include APP_PATH . '/views/partials/brand.php'; ?><?php include APP_PATH . '/views/partials/theme_toggle.php'; ?></header>
  <main class="public-main">
    <?php include APP_PATH . '/views/partials/flash.php'; ?>
    <?= $content ?>
  </main>
  <footer class="public-footer">
    <div><?= e(setting('footer_text') ?: '© ' . gmdate('Y') . ' ' . bank_name()) ?></div>
    <div class="links">
      <a href="<?= e(url('terms')) ?>">Terms</a><a href="<?= e(url('privacy')) ?>">Privacy</a>
      <?php if (setting('contact_email')): ?><a href="mailto:<?= e(setting('contact_email')) ?>"><?= e(setting('contact_email')) ?></a><?php endif; ?>
      <?php if (setting('support_phone')): ?><span><?= e(setting('support_phone')) ?></span><?php endif; ?>
    </div>
  </footer>
</body>
</html>
