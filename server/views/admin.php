<?php
require __DIR__ . '/layout.php';

$projects = db()->query('SELECT * FROM projects ORDER BY name')->fetchAll();
$users = db()->query('SELECT id, email, display_name, role, is_active, created_at FROM users ORDER BY display_name')->fetchAll();
$activeUsers = array_values(array_filter($users, fn($u) => (int)$u['is_active']));
$assignments = []; // project_id => [user_id, ...]
foreach (db()->query('SELECT project_id, user_id FROM project_users')->fetchAll() as $r) {
    $assignments[(int)$r['project_id']][] = (int)$r['user_id'];
}

// Active users as a lookup for the assignment typeahead.
$userById = [];
foreach ($activeUsers as $u) $userById[(int)$u['id']] = $u;

layout_top('Admin', $me);
?>
<h1>Admin</h1>

<script type="application/json" id="all-users"><?=
    json_encode(array_map(fn($u) => [
        'id'    => (int)$u['id'],
        'name'  => $u['display_name'],
        'email' => $u['email'],
    ], $activeUsers), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP)
?></script>

<div class="admin-cols">
<section id="projects">
  <h2>Projects</h2>
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

<section id="users">
  <h2>Users</h2>
  <div class="table-wrap">
  <table class="admin-table">
    <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Active</th><th>Reset password</th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): ?>
      <tr>
        <td><?= e($u['display_name']) ?></td>
        <td><?= e($u['email']) ?></td>
        <td>
          <select class="user-role" data-user="<?= (int)$u['id'] ?>" <?= (int)$u['id'] === (int)$me['id'] ? 'disabled' : '' ?>>
            <option value="user" <?= $u['role'] === 'user' ? 'selected' : '' ?>>user</option>
            <option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>>admin</option>
          </select>
        </td>
        <td>
          <input type="checkbox" class="user-active" data-user="<?= (int)$u['id'] ?>"
                 <?= (int)$u['is_active'] ? 'checked' : '' ?> <?= (int)$u['id'] === (int)$me['id'] ? 'disabled' : '' ?>>
        </td>
        <td>
          <form class="pw-form inline" data-user="<?= (int)$u['id'] ?>">
            <input type="password" name="password" placeholder="New password" minlength="8">
            <button type="submit">Set</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>

  <h3>Create user</h3>
  <form id="create-user-form" class="stack">
    <label>Name <input name="display_name" required></label>
    <label>Email <input type="email" name="email" required></label>
    <label>Password (min 8 chars) <input type="password" name="password" minlength="8" required></label>
    <label>Role
      <select name="role"><option value="user">user</option><option value="admin">admin</option></select>
    </label>
    <button type="submit">Create user</button>
  </form>
</section>
</div>
<?php layout_bottom(); ?>
