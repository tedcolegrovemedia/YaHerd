<?php
require __DIR__ . '/layout.php';

$projects = db()->query('SELECT * FROM projects ORDER BY name')->fetchAll();
$activeUsers = db()->query(
    'SELECT id, email, display_name FROM users WHERE is_active = 1 ORDER BY display_name'
)->fetchAll();
$assignments = []; // project_id => [user_id, ...]
foreach (db()->query('SELECT project_id, user_id FROM project_users')->fetchAll() as $r) {
    $assignments[(int)$r['project_id']][] = (int)$r['user_id'];
}

// Active users as a lookup for the assignment typeahead / chip labels.
$userById = [];
foreach ($activeUsers as $u) $userById[(int)$u['id']] = $u;

layout_top('Admin — Projects', $me);
?>
<h1>Admin</h1>
<?php admin_tabs('projects'); ?>

<script type="application/json" id="all-users"><?=
    json_encode(array_map(fn($u) => [
        'id'    => (int)$u['id'],
        'name'  => $u['display_name'],
        'email' => $u['email'],
    ], $activeUsers), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP)
?></script>

<section id="projects" class="admin-page">
  <?php foreach ($projects as $p): $pid = (int)$p['id']; ?>
  <div class="project-card <?= (int)$p['is_active'] ? '' : 'inactive' ?>">
    <div class="project-head">
      <strong><?= e($p['name']) ?></strong>
      <a href="<?= e($p['base_origin']) ?>" target="_blank" rel="noopener"><?= e($p['base_origin']) ?></a>
      <?= (int)$p['match_subdomains'] ? '<span class="tag">+ subdomains</span>' : '' ?>
      <?= (int)$p['is_active'] ? '' : '<span class="tag off">inactive</span>' ?>
      <a class="boardlink" href="/board?project=<?= $pid ?>">Board →</a>
      <button type="button" class="delete-project linklike-danger" data-project="<?= $pid ?>"
              data-name="<?= e($p['name']) ?>">Delete</button>
    </div>
    <div class="assign-box" data-project="<?= $pid ?>">
      <span class="hint">Assigned users:</span>
      <div class="chips">
        <?php foreach ($assignments[$pid] ?? [] as $uid): if (!isset($userById[$uid])) continue; ?>
          <span class="chip" data-id="<?= $uid ?>">
            <?= e($userById[$uid]['display_name']) ?>
            <button type="button" class="chip-x" aria-label="Remove">&times;</button>
          </span>
        <?php endforeach; ?>
      </div>
      <div class="assign-add">
        <input type="text" class="assign-input" placeholder="Type a name to add…"
               autocomplete="off" spellcheck="false">
        <div class="assign-menu" hidden></div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
  <?php if (!$projects): ?><p class="empty">No projects yet.</p><?php endif; ?>

  <h3>Create project</h3>
  <form id="create-project-form" class="stack">
    <label>Name <input name="name" required placeholder="Acme marketing site"></label>
    <label>Site URL or domain <input name="base_origin" required placeholder="https://www.acme.com"></label>
    <label class="check"><input type="checkbox" name="match_subdomains" value="1"> Also match subdomains</label>
    <button type="submit">Create project</button>
  </form>
</section>
<?php layout_bottom(); ?>
