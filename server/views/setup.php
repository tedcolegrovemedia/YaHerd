<?php require __DIR__ . '/layout.php'; layout_top('First-run setup'); ?>
<div class="authcard">
  <h1>Welcome to YaHerd</h1>
  <p>No users exist yet. Create the admin account to get started.</p>
  <?php if (!empty($setup_error)): ?><p class="error"><?= e($setup_error) ?></p><?php endif; ?>
  <form method="post" action="/setup">
    <label>Your name <input name="display_name" required></label>
    <label>Email <input type="email" name="email" required></label>
    <label>Password (min 8 chars) <input type="password" name="password" minlength="8" required></label>
    <button type="submit">Create admin account</button>
  </form>
</div>
<?php layout_bottom(); ?>
