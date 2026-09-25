<?php use App\Services\Integrations as I; ?>
<div class="page-head"><h1>Integrations</h1><p class="muted">Connect external providers. Everything is off until you enable it. API keys are stored encrypted and never shown again in full.</p></div>
<?php if (!$keyOk): ?><div class="alert alert-error">Set a random <code>app.key</code> (32+ characters) in <code>config/config.php</code> before saving API keys — it is used to encrypt them.</div><?php endif; ?>

<div class="integration-grid">
<?php foreach (I::PROVIDERS as $key => $def): $row = $rows[$key] ?? null; $cfg = $configs[$key] ?? []; ?>
  <section class="card" id="<?= e($key) ?>">
    <div class="card-head"><h2><?= e($def['name']) ?> <span class="muted small"><?= e($def['type']) ?></span></h2>
      <?php if (!$def['driver']): ?><span class="badge badge-muted">Needs provider</span>
      <?php elseif ($row && $row['enabled']): ?><span class="badge badge-success"><?= $row['mode'] === 'live' ? 'Live' : 'Test mode' ?></span>
      <?php else: ?><span class="badge badge-muted">Off</span><?php endif; ?></div>
    <p class="muted small"><?= e($def['description']) ?></p>
    <?php if ($def['driver']): ?>
    <form method="post" action="<?= e(url('admin/integrations/' . $key)) ?>" class="form" autocomplete="off"><?= csrf_field() ?>
      <div class="radio-row">
        <label class="check"><input type="checkbox" name="enabled" value="1" <?= $row && $row['enabled'] ? 'checked' : '' ?>> Enabled</label>
        <label class="check"><input type="radio" name="mode" value="test" <?= !$row || $row['mode'] === 'test' ? 'checked' : '' ?>> Test</label>
        <label class="check"><input type="radio" name="mode" value="live" <?= $row && $row['mode'] === 'live' ? 'checked' : '' ?>> Live</label>
      </div>
      <?php foreach ($def['fields'] as $f => [$label, $secret, $required, $help]): ?>
        <label><?= e($label) ?><?= $required ? '' : ' <span class="muted small">(optional)</span>' ?>
          <?php if ($secret): ?>
            <input type="password" name="f[<?= e($f) ?>]" placeholder="<?= ($cfg[$f] ?? '') !== '' ? e(I::mask((string) $cfg[$f])) . ' — leave blank to keep' : '' ?>" autocomplete="new-password">
          <?php else: ?>
            <input name="f[<?= e($f) ?>]" value="<?= e($cfg[$f] ?? '') ?>">
          <?php endif; ?>
          <?php if ($help): ?><small class="muted"><?= e($help) ?></small><?php endif; ?></label>
      <?php endforeach; ?>
      <?php if (!empty($def['webhook'])): ?>
        <div class="webhook-box"><small class="muted">Webhook endpoint — add it in Stripe (Developers → Webhooks) for the events <code>checkout.session.completed</code>, <code>checkout.session.async_payment_succeeded</code>, <code>checkout.session.async_payment_failed</code> and <code>checkout.session.expired</code>:</small>
          <code class="mono"><?= e($webhookUrl) ?></code></div>
      <?php endif; ?>
      <div class="actions-inline"><button class="btn btn-primary btn-sm">Save</button></div>
    </form>
    <?php if ($row): ?>
    <form method="post" action="<?= e(url('admin/integrations/' . $key . '/test')) ?>" class="inline-form"><?= csrf_field() ?><button class="btn btn-secondary btn-sm">Test connection</button>
      <?php if ($row['last_tested_at']): ?><span class="small <?= $row['last_test_ok'] ? 'pos' : 'neg' ?>"><?= $row['last_test_ok'] ? '✓' : '✗' ?> <?= e($row['last_test_message']) ?> · <?= e(fmt_date($row['last_tested_at'])) ?></span><?php endif; ?></form>
    <?php endif; ?>
    <?php endif; ?>
  </section>
<?php endforeach; ?>
</div>

<section class="card">
  <h2>Card top-ups</h2>
  <?php if (!$payments): ?><p class="empty">No gateway payments yet.</p><?php else: ?>
  <div class="table-wrap"><table class="table small"><thead><tr><th>Date</th><th>Customer</th><th class="num">Amount</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($payments as $p): ?><tr><td class="nowrap"><?= e(fmt_date($p['created_at'])) ?><div class="mono muted"><?= e($p['reference']) ?></div></td><td><?= e($p['full_name']) ?></td>
      <td class="num"><?= e(money((int) $p['amount'], $p['currency'])) ?></td><td><?= status_badge($p['status']) ?><?= $p['failure_reason'] ? '<div class="muted">' . e($p['failure_reason']) . '</div>' : '' ?>
      <?= $p['transaction_id'] ? '<div><a class="mono" href="' . e(url('admin/transactions/' . $p['transaction_id'])) . '">ledger</a></div>' : '' ?></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</section>
<section class="card">
  <h2>Activity log</h2>
  <p class="muted small">Requests to and from providers. Keys, tokens and card numbers are redacted.</p>
  <?php if (!$logs): ?><p class="empty">No activity yet.</p><?php else: ?>
  <div class="table-wrap"><table class="table small"><thead><tr><th>When</th><th>Provider</th><th>Action</th><th>Result</th></tr></thead><tbody>
    <?php foreach ($logs as $l): ?><tr><td class="nowrap"><?= e(fmt_date($l['created_at'], 'M j, g:i:s A')) ?></td><td><?= e($l['provider']) ?> <?= $l['direction'] === 'in' ? '↓' : '↑' ?></td>
      <td class="mono"><?= e($l['action']) ?></td><td><span class="<?= $l['ok'] ? 'pos' : 'neg' ?>"><?= $l['ok'] ? 'OK' : 'Failed' ?></span><?= $l['http_status'] ? ' · ' . (int) $l['http_status'] : '' ?><?= $l['duration_ms'] !== null ? ' · ' . (int) $l['duration_ms'] . ' ms' : '' ?>
        <div class="muted"><?= e(mb_strimwidth((string) $l['summary'], 0, 140, '…')) ?></div></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</section>
