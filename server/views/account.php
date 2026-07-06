<?php
require __DIR__ . '/layout.php';
layout_top('Account', $me);
?>
<h1>Account</h1>

<div class="stack">
  <p class="hint" style="margin-top:0">
    Signed in as <strong><?= e($me['display_name']) ?></strong> (<?= e($me['email']) ?>)
  </p>
  <h3 style="margin-top:0">Change password</h3>
  <form id="change-password-form">
    <label>Current password
      <input type="password" name="current_password" required autocomplete="current-password">
    </label>
    <label>New password (min 8 chars)
      <input type="password" name="new_password" minlength="8" required autocomplete="new-password">
    </label>
    <label>Confirm new password
      <input type="password" name="confirm_password" minlength="8" required autocomplete="new-password">
    </label>
    <button type="submit">Update password</button>
  </form>
</div>
<?php layout_bottom(); ?>
