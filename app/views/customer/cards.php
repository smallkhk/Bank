<div class="page-head"><h1>Cards</h1></div>
<?php if ($cards): ?>
<div class="card-grid">
  <?php foreach ($cards as $card): ?>
    <a class="card-tile" href="<?= e(url('cards/' . $card['id'])) ?>">
      <?php include APP_PATH . '/views/partials/card_visual.php'; ?>
      <div class="card-tile-meta">
        <strong><?= e($card['product_name']) ?></strong> <?= status_badge($card['status']) ?>
        <div class="muted small">
          <?php if ($card['card_type'] === 'credit' && $card['account_number']): ?>
            Owed <?= e(money(max(0, -(int) $card['balance']))) ?> · Available <?= e(money((int) $card['balance'] + (int) $card['credit_limit'] - (int) $card['held_amount'])) ?>
          <?php elseif ($card['account_number']): ?>Linked to <?= e(mask_account($card['account_number'])) ?><?php endif; ?>
        </div>
      </div>
    </a>
  <?php endforeach; ?>
</div>
<?php else: ?>
  <section class="card"><p class="empty">You don't have any cards yet.</p></section>
<?php endif; ?>

<?php if ($products): ?>
<section class="card">
  <h2>Request a card</h2>
  <form method="post" action="<?= e(url('cards')) ?>" class="form grid-2" data-card-request>
    <?= csrf_field() ?>
    <label>Card <select name="product_id" required data-product>
      <?php foreach ($products as $p): ?>
        <option value="<?= (int) $p['id'] ?>" data-type="<?= e($p['card_type']) ?>"><?= e($p['name']) ?> — <?= e(ucfirst($p['card_type'])) ?>, <?= e($p['form_factor']) ?><?= (int) $p['issuance_fee'] ? ' · fee ' . e(money((int) $p['issuance_fee'])) : '' ?><?= $p['card_type'] === 'credit' ? ' · limit ' . e(money((int) $p['credit_limit'])) : '' ?></option>
      <?php endforeach; ?></select></label>
    <label data-account-field>Linked account <select name="account_id">
      <?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>"><?= e(($a['nickname'] ?: $a['type_name']) . ' ' . mask_account($a['account_number'])) ?></option><?php endforeach; ?></select>
      <small class="muted">Debit and prepaid cards spend from this account. Credit cards get their own credit account.</small></label>
    <div class="span-2"><button class="btn btn-primary">Request card</button></div>
  </form>
  <p class="muted small">Cards are issued after review. Card products in this environment are simulated and cannot be used at real merchants.</p>
</section>
<?php endif; ?>
