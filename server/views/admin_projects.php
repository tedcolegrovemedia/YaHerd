<?php
require __DIR__ . '/layout.php';
$projects = db()->query('SELECT * FROM projects ORDER BY name')->fetchAll();
$users = db()->query('SELECT id, display_name, email FROM users WHERE is_active = 1 ORDER BY display_name')->fetchAll();
$assignments = []; // project_id => [user_id, ...]
foreach (db()->query('SELECT project_id, user_id FROM project_users')->fetchAll() as $r) {
    $assignments[(int)$r['project_id']][] = (int)$r['user_id'];
}
layout_top('Projects', $me);
?>
<h1>Projects</h1>
<?php foreach ($projects as $p): $pid = (int)$p['id']; ?>
<div class="project-card <?= (int)$p['is_active'] ? '' : 'inactive' ?>">
  <div class="project-head">
    <strong><?= e($p['name']) ?></strong>
    <a href="<?= e($p['base_origin']) ?>" target="_blank" rel="noopener"><?= e($p['base_origin']) ?></a>
    <?= (int)$p['match_subdomains'] ? '<span class="tag">+ subdomains</span>' : '' ?>
    <?= (int)$p['is_active'] ? '' : '<span class="tag off">inactive</span>' ?>
    <a class="boardlink" href="/board?project=<?= $pid ?>">Board →</a>
  </div>
  <form class="assign-form" data-project="<?= $pid ?>">
    <span class="hint">Assigned users:</span>
    <?php foreach ($users as $u): ?>
      <label class="check">
        <input type="checkbox" name="user_ids[]" value="<?= (int)$u['id'] ?>"
          <?= in_array((int)$u['id'], $assignments[$pid] ?? [], true) ? 'checked' : '' ?>>
        <?= e($u['display_name']) ?>
      </label>
    <?php endforeach; ?>
    <button type="submit">Save assignments</button>
  </form>
</div>
<?php endforeach; ?>
<?php if (!$projects): ?><p class="empty">No projects yet.</p><?php endif; ?>

<h2>Create project</h2>
<form id="create-project-form" class="stack">
  <label>Name <input name="name" required placeholder="Acme marketing site"></label>
  <label>Site URL or domain <input name="base_origin" required placeholder="https://www.acme.com"></label>
  <label class="check"><input type="checkbox" name="match_subdomains" value="1"> Also match subdomains</label>
  <button type="submit">Create project</button>
</form>
<?php layout_bottom(); ?>
