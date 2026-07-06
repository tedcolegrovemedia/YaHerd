<?php
require __DIR__ . '/layout.php';

// Projects visible to me
if ($me['role'] === 'admin') {
    $projects = db()->query('SELECT * FROM projects ORDER BY name')->fetchAll();
} else {
    $stmt = db()->prepare(
        'SELECT p.* FROM projects p JOIN project_users pu ON pu.project_id = p.id
         WHERE pu.user_id = ? AND p.is_active = 1 ORDER BY p.name'
    );
    $stmt->execute([$me['id']]);
    $projects = $stmt->fetchAll();
}

$projectId = (int)($_GET['project'] ?? ($projects[0]['id'] ?? 0));
$project = null;
foreach ($projects as $p) if ((int)$p['id'] === $projectId) $project = $p;

$byStatus = ['queued' => [], 'working_on' => [], 'complete' => []];
$members = [];
if ($project) {
    $stmt = db()->prepare(
        'SELECT c.*, u.display_name AS author_name,
                (SELECT COUNT(*) FROM comment_replies r WHERE r.comment_id = c.id) AS reply_count
         FROM comments c JOIN users u ON u.id = c.author_id
         WHERE c.project_id = ? ORDER BY c.created_at DESC'
    );
    $stmt->execute([$projectId]);
    foreach ($stmt->fetchAll() as $c) $byStatus[$c['status']][] = $c;

    $stmt = db()->prepare(
        "SELECT DISTINCT u.id, u.display_name FROM users u
         LEFT JOIN project_users pu ON pu.user_id = u.id AND pu.project_id = ?
         WHERE u.is_active = 1 AND (pu.project_id IS NOT NULL OR u.role = 'admin')
         ORDER BY u.display_name"
    );
    $stmt->execute([$projectId]);
    $members = $stmt->fetchAll();
}

function assignee_select(array $c, array $members): void { ?>
  <select class="assignee-select" data-comment="<?= (int)$c['id'] ?>" title="Assign to">
    <option value="">Unassigned</option>
    <?php foreach ($members as $m): ?>
      <option value="<?= (int)$m['id'] ?>" <?= (int)$m['id'] === (int)($c['assignee_id'] ?? 0) ? 'selected' : '' ?>>
        <?= e($m['display_name']) ?>
      </option>
    <?php endforeach; ?>
  </select>
<?php }

layout_top('Board', $me);
?>
<div class="board-head">
  <?php if (!$projects): ?>
    <p><?= $me['role'] === 'admin'
        ? 'No projects yet. <a href="/admin/projects">Create one</a> to get started.'
        : 'No projects assigned to you yet. Ask your admin.' ?></p>
  <?php else: ?>
  <form method="get" action="/board" class="inline">
    <label>Project
      <select name="project" onchange="this.form.submit()">
        <?php foreach ($projects as $p): ?>
          <option value="<?= (int)$p['id'] ?>" <?= (int)$p['id'] === $projectId ? 'selected' : '' ?>>
            <?= e($p['name']) ?> (<?= e($p['base_origin']) ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </label>
  </form>
  <?php if ($project): ?>
    <a class="visit" href="<?= e($project['base_origin']) ?>" target="_blank" rel="noopener">Open site ↗</a>
  <?php endif; ?>
  <?php endif; ?>
</div>

<?php if ($project): ?>
<div class="board">
  <?php foreach ($byStatus as $status => $cards): ?>
  <section class="column status-<?= e($status) ?>" data-status="<?= e($status) ?>">
    <h2><?= e(status_label($status)) ?> <span class="count"><?= count($cards) ?></span></h2>
    <div class="cards">
    <?php foreach ($cards as $c): ?>
    <article class="card" draggable="true" data-comment="<?= (int)$c['id'] ?>">
      <p class="body"><a href="/task?id=<?= (int)$c['id'] ?>"><?= e(mb_strimwidth($c['body'], 0, 140, '…')) ?></a></p>
      <p class="meta">
        <span class="path" title="<?= e($c['page_url']) ?>"><?= e($c['page_path']) ?></span><br>
        <?= e($c['author_name']) ?> · <?= e(date('M j, H:i', strtotime($c['created_at']))) ?>
        <?php if ((int)$c['reply_count']): ?> · 💬 <?= (int)$c['reply_count'] ?><?php endif; ?>
        <?php if ($c['screenshot_path']): ?>
          · <button type="button" class="shot-icon" data-shot="/api/screenshots/<?= (int)$c['id'] ?>"
                    title="View screenshot">📷</button>
        <?php endif; ?>
      </p>
      <p class="assignee-row">👤 <?php assignee_select($c, $members); ?></p>
    </article>
    <?php endforeach; ?>
    </div>
    <p class="empty" <?= $cards ? 'hidden' : '' ?>>Nothing here.</p>
  </section>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php layout_bottom(); ?>
