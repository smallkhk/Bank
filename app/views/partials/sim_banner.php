<?php
/** @var bool $live whether the prices shown come from a live market feed */
?>
<?php if (!empty($live)): ?>
<div class="info-banner" role="note"><strong>Market prices.</strong> Your crypto is held by <?= e(bank_name()) ?> as a cash-settled position that tracks the market price. Buy with your balance and sell back to your account at any time. Coins can't be sent to or received from external wallets.</div>
<?php else: ?>
<div class="sim-banner" role="note"><strong>Simulated prices.</strong> <?= !empty($mixed) ? 'Assets marked "Simulated price" use prices set by the bank, not a live market.' : 'Prices here are set by the bank, not a live market.' ?> Positions are settled in cash to your account; coins can't be sent to or received from external wallets.</div>
<?php endif; ?>
