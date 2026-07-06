<?php
require __DIR__ . '/layout.php';

$users = db()->query(
    'SELECT id, email, display_name, role, is_active, created_at FROM users ORDER BY display_name'
)->fetchAll();

layout_top('Admin — Users', $me);
?>
<h1>Admin</h1>
<?php admin_tabs('users'); ?>

<section id="users" class="admin-page">
  <div class="user-list">
    <?php foreach ($users as $u): $uid = (int)$u['id']; $self = $uid === (int)$me['id']; ?>
    <div class="user-row <?= (int)$u['is_active'] ? '' : 'inactive' ?>">
      <div class="user-id">
        <strong><?= e($u['display_name']) ?></strong>
        <span class="user-email" title="<?= e($u['email']) ?>"><?= e($u['email']) ?></span>
      </div>
      <div class="user-controls">
        <label class="ctl">Role
          <select class="user-role" data-user="<?= $uid ?>" <?= $self ? 'disabled' : '' ?>>
            <option value="user" <?= $u['role'] === 'user' ? 'selected' : '' ?>>user</option>
            <option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>>admin</option>
          </select>
        </label>
        <label class="check">
          <input type="checkbox" class="user-active" data-user="<?= $uid ?>"
                 <?= (int)$u['is_active'] ? 'checked' : '' ?> <?= $self ? 'disabled' : '' ?>> Active
        </label>
        <form class="pw-form" data-user="<?= $uid ?>">
          <input type="password" name="password" placeholder="New password" minlength="8">
          <button type="submit">Set</button>
        </form>
        <?php if (!$self): ?>
          <button type="button" class="delete-user linklike-danger" data-user="<?= $uid ?>"
                  data-name="<?= e($u['display_name']) ?>">Delete</button>
        <?php else: ?>
          <span class="hint">(you)</span>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
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
<?php layout_bottom(); ?>
