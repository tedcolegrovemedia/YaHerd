<?php
require __DIR__ . '/layout.php';
layout_top('Account', $me);

$pref = fn(string $c) => (int)($me[$c] ?? 1) ? 'checked' : '';
?>
<h1>Account</h1>
<p class="hint" style="margin-top:-8px">
  Signed in as <strong><?= e($me['display_name']) ?></strong> (<?= e($me['email']) ?>)
</p>

<div class="account-cols">
  <div class="stack">
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

  <div class="stack">
    <h3 style="margin-top:0">Email notifications</h3>
    <p class="hint" style="margin-top:0">Choose which activity emails you receive.</p>
    <div class="pref-list">
      <label class="check"><input type="checkbox" class="notify-pref" data-pref="notify_project_added" <?= $pref('notify_project_added') ?>> When I'm added to a project</label>
      <label class="check"><input type="checkbox" class="notify-pref" data-pref="notify_assigned" <?= $pref('notify_assigned') ?>> When I'm assigned a task</label>
      <label class="check"><input type="checkbox" class="notify-pref" data-pref="notify_replies" <?= $pref('notify_replies') ?>> When someone replies on my task</label>
      <label class="check"><input type="checkbox" class="notify-pref" data-pref="notify_status" <?= $pref('notify_status') ?>> When my task changes status</label>
    </div>
    <p class="hint">Account &amp; password emails are always sent.</p>
  </div>
</div>
<?php layout_bottom(); ?>
