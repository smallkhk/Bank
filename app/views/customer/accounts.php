<div class="page-head"><h1>Accounts</h1></div>
<div class="account-cards">
<?php foreach ($accounts as $a): ?>
  <a class="account-card" href="<?= e(url('accounts/' . $a['id'])) ?>">
    <div class="ac-top"><span><?= e($a['nickname'] ?: $a['type_name']) ?></span><?= status_badge($a['status']) ?></div>
    <div class="ac-balance"><?= e(money((int) $a['balance'], $a['currency'])) ?></div>
    <div class="ac-meta"><span class="mono"><?= e($a['account_number']) ?></span><span>Available <?= e(money(App\Services\AccountService::available($a), $a['currency'])) ?></span></div>
  </a>
<?php endforeach; ?>
<?php if (!$accounts): ?><p class="empty">No accounts yet.</p><?php endif; ?>
</div>
