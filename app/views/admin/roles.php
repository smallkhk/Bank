<div class="page-head"><h1>Roles &amp; permissions</h1><p class="muted">Super Admin always has every permission. Account managers and assistants only see assigned customers unless granted <code>customers.view_all</code>.</p></div>
<?php foreach ($roles as $role): ?>
<section class="card">
  <h2><?= e($role['name']) ?> <span class="muted small"><?= e($role['description']) ?></span></h2>
  <form method="post" action="<?= e(url('admin/roles/' . $role['id'])) ?>">
    <?= csrf_field() ?>
    <div class="perm-grid">
      <?php foreach ($permissions as $p): ?>
        <label class="check"><input type="checkbox" name="permissions[]" value="<?= (int) $p['id'] ?>" <?= isset($grants[$role['id']][$p['id']]) ? 'checked' : '' ?>>
          <span><code><?= e($p['slug']) ?></code><small class="muted"><?= e($p['description']) ?></small></span></label>
      <?php endforeach; ?>
    </div>
    <button class="btn btn-primary btn-sm">Save <?= e($role['name']) ?></button>
  </form>
</section>
<?php endforeach; ?>
