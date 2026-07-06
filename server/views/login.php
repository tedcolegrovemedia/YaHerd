<?php require __DIR__ . '/layout.php'; layout_top('Log in'); ?>
<div class="authcard">
  <h1>Log in</h1>
  <?php if (!empty($login_error)): ?><p class="error"><?= e($login_error) ?></p><?php endif; ?>
  <form method="post" action="/login">
    <label>Email <input type="email" name="email" required autofocus></label>
    <label>Password <input type="password" name="password" required></label>
    <button type="submit">Log in</button>
  </form>
</div>
<?php layout_bottom(); ?>
