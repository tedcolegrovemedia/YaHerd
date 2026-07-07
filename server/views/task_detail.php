<?php
require __DIR__ . '/layout.php';

$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare(
    "SELECT c.*, COALESCE(u.display_name, 'no user') AS author_name, p.name AS project_name
     FROM comments c LEFT JOIN users u ON u.id = c.author_id JOIN projects p ON p.id = c.project_id
     WHERE c.id = ?"
);
$stmt->execute([$id]);
$c = $stmt->fetch();
if (!$c || !is_project_member($me, (int)$c['project_id'])) {
    http_response_code(404); exit('Task not found');
}

$stmt = db()->prepare(
    "SELECT r.*, COALESCE(u.display_name, 'no user') AS author_name
     FROM comment_replies r LEFT JOIN users u ON u.id = r.author_id
     WHERE r.comment_id = ? ORDER BY r.created_at"
);
$stmt->execute([$id]);
$replies = $stmt->fetchAll();

$stmt = db()->prepare(
    "SELECT DISTINCT u.id, u.display_name FROM users u
     LEFT JOIN project_users pu ON pu.user_id = u.id AND pu.project_id = ?
     WHERE u.is_active = 1 AND (pu.project_id IS NOT NULL OR u.role = 'admin')
     ORDER BY u.display_name"
);
$stmt->execute([(int)$c['project_id']]);
$members = $stmt->fetchAll();

layout_top('Task #' . $id, $me);
?>
<?php $isArchived = !empty($c['archived_at']);
      $canDelete = $me['role'] === 'admin' || (int)$c['author_id'] === (int)$me['id']; ?>
<div class="task-detail">
  <div class="task-topbar">
    <p class="crumbs"><a href="/board?project=<?= (int)$c['project_id'] ?>">← <?= e($c['project_name']) ?> board</a></p>
    <div class="task-actions" data-project="<?= (int)$c['project_id'] ?>">
      <button type="button" class="archive-task btn-ghost" data-comment="<?= (int)$c['id'] ?>"
              data-archived="<?= $isArchived ? '1' : '0' ?>"><?= $isArchived ? 'Restore' : 'Archive' ?></button>
      <?php if ($canDelete): ?>
        <button type="button" class="delete-task btn-ghost danger" data-comment="<?= (int)$c['id'] ?>">Delete</button>
      <?php endif; ?>
    </div>
  </div>
  <?php if ($isArchived): ?><p class="archived-banner">🗄 This task is archived — it's hidden from the board and the live page.</p><?php endif; ?>
  <div class="task-grid">
    <div>
      <?php if ($c['screenshot_path']): ?>
        <img class="shot" src="/api/screenshots/<?= (int)$c['id'] ?>" alt="Pin screenshot"
             onclick="this.classList.toggle('zoomed')">
        <p class="hint">Click image to zoom. The 📍 marks the commented spot.</p>
      <?php else: ?>
        <p class="empty">No screenshot was captured for this task.</p>
      <?php endif; ?>
    </div>
    <div>
      <h1>Task #<?= (int)$c['id'] ?></h1>
      <p class="taskbody"><?= nl2br(e($c['body'])) ?></p>
      <dl>
        <dt>Status</dt>
        <dd>
          <select class="status-select" data-comment="<?= (int)$c['id'] ?>">
            <?php foreach (['queued','working_on','complete'] as $s): ?>
              <option value="<?= $s ?>" <?= $s === $c['status'] ? 'selected' : '' ?>><?= e(status_label($s)) ?></option>
            <?php endforeach; ?>
          </select>
        </dd>
        <dt>Assigned to</dt>
        <dd>
          <select class="assignee-select" data-comment="<?= (int)$c['id'] ?>">
            <option value="">Unassigned</option>
            <?php foreach ($members as $m): ?>
              <option value="<?= (int)$m['id'] ?>" <?= (int)$m['id'] === (int)($c['assignee_id'] ?? 0) ? 'selected' : '' ?>>
                <?= e($m['display_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </dd>
        <dt>Page</dt>
        <dd><a href="<?= e($c['page_url']) ?>" target="_blank" rel="noopener"><?= e($c['page_url']) ?></a><br>
            <span class="hint">Open with the extension installed to see the live pin.</span></dd>
        <dt>Reported by</dt>
        <dd><?= e($c['author_name']) ?> on <?= e(date('M j Y, H:i', strtotime($c['created_at']))) ?></dd>
        <?php if ($c['anchor_selector']): ?>
        <dt>Element</dt>
        <dd><code><?= e($c['anchor_selector']) ?></code></dd>
        <?php endif; ?>
      </dl>

      <h2>Replies</h2>
      <div class="replies">
        <?php foreach ($replies as $r): ?>
          <div class="reply">
            <p class="meta"><?= e($r['author_name']) ?> · <?= e(date('M j, H:i', strtotime($r['created_at']))) ?></p>
            <p><?= nl2br(e($r['body'])) ?></p>
          </div>
        <?php endforeach; ?>
        <?php if (!$replies): ?><p class="empty">No replies yet.</p><?php endif; ?>
      </div>
      <form id="reply-form" data-comment="<?= (int)$c['id'] ?>">
        <textarea name="body" rows="3" placeholder="Write a reply…" required></textarea>
        <button type="submit">Reply</button>
      </form>
    </div>
  </div>
</div>
<?php layout_bottom(); ?>
