<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e(($title ?? '') !== '' ? $title . ' · ' . bank_name() : bank_name()) ?></title>
<?php if (setting('favicon_path')): ?><link rel="icon" href="<?= e(url('branding/favicon')) ?>"><?php endif; ?>
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
<style>:root{--primary:<?= e(setting('color_primary')) ?>;--secondary:<?= e(setting('color_secondary')) ?>;--accent:<?= e(setting('color_accent')) ?>}</style>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
