<?php
require __DIR__ . '/layout.php';
$users = db()->query('SELECT id, email, display_name, role, is_active, created_at FROM users ORDER BY display_name')->fetchAll();
layout_top('Users', $me);
?>
<h1>Users</h1>
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

<h2>Create user</h2>
<form id="create-user-form" class="stack">
  <label>Name <input name="display_name" required></label>
  <label>Email <input type="email" name="email" required></label>
  <label>Password (min 8 chars) <input type="password" name="password" minlength="8" required></label>
  <label>Role
    <select name="role"><option value="user">user</option><option value="admin">admin</option></select>
  </label>
  <button type="submit">Create user</button>
</form>
<?php layout_bottom(); ?>
