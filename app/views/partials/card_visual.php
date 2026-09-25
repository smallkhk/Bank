<?php /** @var array $card */ $cls = ['debit' => 'cv-debit', 'credit' => 'cv-credit', 'prepaid' => 'cv-prepaid'][$card['card_type']] ?? 'cv-debit'; ?>
<div class="card-visual <?= $cls ?> <?= in_array($card['status'], ['active'], true) ? '' : 'cv-inactive' ?>">
  <div class="cv-top"><span class="cv-bank"><?= e(setting('bank_short_name') ?: bank_name()) ?></span><span class="cv-type"><?= e(ucfirst($card['card_type'])) ?> · <?= e(ucfirst($card['form_factor'])) ?></span></div>
  <div class="cv-chip" aria-hidden="true"></div>
  <div class="cv-number mono" data-cv-number><?= e(App\Services\CardService::masked($card)) ?></div>
  <div class="cv-bottom">
    <div><small>Cardholder</small><span><?= e($card['cardholder_name']) ?></span></div>
    <div><small>Expires</small><span data-cv-expiry><?= e(App\Services\CardService::expiry($card)) ?></span></div>
    <div data-cv-cvv-wrap hidden><small>CVV</small><span data-cv-cvv class="mono"></span></div>
  </div>
  <?php if ($card['status'] !== 'active'): ?><div class="cv-status"><?= e(strtoupper($card['status'])) ?></div><?php endif; ?>
</div>
