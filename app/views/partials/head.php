<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<meta name="theme-color" content="<?= e(setting('color_primary')) ?>">
<title><?= e(($title ?? '') !== '' ? $title . ' · ' . bank_name() : bank_name()) ?></title>
<?php if (setting('favicon_path')): ?><link rel="icon" href="<?= e(url('branding/favicon')) ?>"><?php endif; ?>
<script src="<?= e(asset('js/theme.js')) ?>"></script>
<link rel="preload" href="<?= e(url('assets/fonts/Onest-latin-400-700.woff2')) ?>" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="<?= e(url('assets/fonts/BricolageGrotesque-latin-500-700.woff2')) ?>" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= e(asset('css/fonts.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
<style>:root{--primary:<?= e(setting('color_primary')) ?>;--secondary:<?= e(setting('color_secondary')) ?>;--accent:<?= e(setting('color_accent')) ?>}</style>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
