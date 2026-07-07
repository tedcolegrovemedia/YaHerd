<?php
require __DIR__ . '/layout.php';

$notifs = db()->prepare(
    'SELECT id, category, message, link, created_at
     FROM notifications WHERE user_id = ? AND read_at IS NULL
     ORDER BY created_at DESC LIMIT 50'
);
$notifs->execute([(int)$me['id']]);
$notifications = $notifs->fetchAll();

layout_top('Account', $me);

$pref = fn(string $c) => (int)($me[$c] ?? 1) ? 'checked' : '';
?>
<h1>Account</h1>
<p class="hint" style="margin-top:-8px">
  Signed in as <strong><?= e($me['display_name']) ?></strong> (<?= e($me['email']) ?>)
</p>

<div class="stack notif-center" style="max-width:none;margin-bottom:24px">
  <div class="notif-head">
    <h3 style="margin:0">Notifications</h3>
    <button type="button" class="btn-ghost notif-readall" <?= $notifications ? '' : 'hidden' ?>>Mark all read</button>
  </div>
  <div class="notif-list">
    <?php foreach ($notifications as $n): ?>
      <div class="notif-item" data-id="<?= (int)$n['id'] ?>">
        <?php if ($n['link']): ?>
          <a class="notif-msg" href="<?= e($n['link']) ?>"><?= e($n['message']) ?></a>
        <?php else: ?>
          <span class="notif-msg"><?= e($n['message']) ?></span>
        <?php endif; ?>
        <span class="notif-time"><?= e(time_ago($n['created_at'])) ?></span>
        <button type="button" class="notif-read" title="Mark read" aria-label="Mark read">✓</button>
      </div>
    <?php endforeach; ?>
  </div>
  <p class="empty notif-empty" <?= $notifications ? 'hidden' : '' ?>>You're all caught up. 🎉</p>
</div>

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
