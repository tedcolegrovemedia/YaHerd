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
$showArchived = !empty($_GET['archived']);
$project = null;
foreach ($projects as $p) if ((int)$p['id'] === $projectId) $project = $p;

$byStatus = ['queued' => [], 'working_on' => [], 'complete' => []];
$archived = [];
$archivedCount = 0;
$members = [];
if ($project) {
    $stmt = db()->prepare(
        "SELECT c.*, COALESCE(u.display_name, 'no user') AS author_name,
                (SELECT COUNT(*) FROM comment_replies r WHERE r.comment_id = c.id) AS reply_count
         FROM comments c LEFT JOIN users u ON u.id = c.author_id
         WHERE c.project_id = ? AND c.archived_at IS " . ($showArchived ? 'NOT NULL' : 'NULL') . "
         ORDER BY c.created_at DESC"
    );
    $stmt->execute([$projectId]);
    foreach ($stmt->fetchAll() as $c) {
        if ($showArchived) $archived[] = $c; else $byStatus[$c['status']][] = $c;
    }

    $cnt = db()->prepare('SELECT COUNT(*) FROM comments WHERE project_id = ? AND archived_at IS NOT NULL');
    $cnt->execute([$projectId]);
    $archivedCount = (int)$cnt->fetchColumn();

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

// Render one task card. $archivedView flips the Archive button to Restore.
function task_card(array $c, array $members, array $me, bool $archivedView): void {
    $canDelete = $me['role'] === 'admin' || (int)$c['author_id'] === (int)$me['id'];
    ?>
    <article class="card" <?= $archivedView ? '' : 'draggable="true"' ?> data-comment="<?= (int)$c['id'] ?>">
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
      <?php if (!$archivedView): ?><p class="assignee-row">👤 <?php assignee_select($c, $members); ?></p><?php endif; ?>
      <div class="card-actions">
        <a class="view-task" href="/task?id=<?= (int)$c['id'] ?>">View task</a>
        <button type="button" class="archive-task" data-comment="<?= (int)$c['id'] ?>"
                data-archived="<?= $archivedView ? '1' : '0' ?>"><?= $archivedView ? 'Restore' : 'Archive' ?></button>
        <?php if ($canDelete): ?>
          <button type="button" class="delete-task linklike-danger" data-comment="<?= (int)$c['id'] ?>">Delete</button>
        <?php endif; ?>
      </div>
    </article>
<?php }

layout_top('Board', $me);
?>
<div class="board-head">
  <a class="crumb" href="/">← All projects</a>
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
    <div class="board-actions">
      <?php if ($showArchived): ?>
        <a class="archived-link" href="/board?project=<?= $projectId ?>">← Active board</a>
      <?php else: ?>
        <a class="archived-link" href="/board?project=<?= $projectId ?>&archived=1">🗄 Archived<?= $archivedCount ? ' (' . $archivedCount . ')' : '' ?></a>
      <?php endif; ?>
      <a class="visit" href="<?= e($project['base_origin']) ?>" target="_blank" rel="noopener">Open site ↗</a>
    </div>
  <?php endif; ?>
  <?php endif; ?>
</div>

<?php if ($project && $showArchived): ?>
  <h2 class="archived-title">Archived tasks <span class="count"><?= count($archived) ?></span></h2>
  <?php if (!$archived): ?><p class="empty">No archived tasks.</p><?php endif; ?>
  <div class="archived-list">
    <?php foreach ($archived as $c) task_card($c, $members, $me, true); ?>
  </div>
<?php elseif ($project): ?>
<div class="board">
  <?php foreach ($byStatus as $status => $cards): ?>
  <section class="column status-<?= e($status) ?>" data-status="<?= e($status) ?>">
    <h2><?= e(status_label($status)) ?> <span class="count"><?= count($cards) ?></span></h2>
    <div class="cards">
    <?php foreach ($cards as $c) task_card($c, $members, $me, false); ?>
    </div>
    <p class="empty" <?= $cards ? 'hidden' : '' ?>>Nothing here.</p>
  </section>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php layout_bottom(); ?>
