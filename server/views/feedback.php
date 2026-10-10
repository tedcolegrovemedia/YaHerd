<?php
require __DIR__ . '/layout.php';

$projectId = (int)$_GET['project'];
$stmt = db()->prepare("SELECT * FROM projects WHERE id = ? AND type = 'feedback'");
$stmt->execute([$projectId]);
$project = $stmt->fetch();
if (!$project || !is_project_member($me, $projectId)) { http_response_code(404); exit('Not found'); }

$stmt = db()->prepare(
    'SELECT fp.*,
            (SELECT COUNT(*) FROM feedback_notes n WHERE n.page_id = fp.id) AS note_count,
            (SELECT COUNT(*) FROM feedback_notes n WHERE n.page_id = fp.id AND n.closed_at IS NOT NULL) AS closed_count
     FROM feedback_pages fp WHERE fp.project_id = ? ORDER BY fp.created_at DESC'
);
$stmt->execute([$projectId]);
$pages = $stmt->fetchAll();

layout_top($project['name'], $me);
?>
<div class="board-head">
  <a class="crumb" href="/">← Projects</a>
  <h1 style="margin:0"><?= e($project['name']) ?></h1>
  <span class="tag tag-feedback">Feedback</span>
</div>

<form id="add-page-form" class="fb-add" data-project="<?= $projectId ?>">
  <label>Page URL <input name="url" type="url" required placeholder="https://student.github.io/portfolio/"></label>
  <label>Label <input name="title" placeholder="e.g. Jordan — Portfolio home"></label>
  <button type="submit">Snapshot &amp; start reviewing</button>
</form>
<p class="hint">YaHerd saves a copy of the page now, so your pins and line numbers stay put even if the page changes later. You can refresh the snapshot from the review screen.</p>

<?php if (!$pages): ?>
  <p class="empty">No pages yet. Paste a URL above to start.</p>
<?php else: ?>
<div class="fb-pages">
  <?php foreach ($pages as $pg):
      $open = (int)$pg['note_count'] - (int)$pg['closed_count']; ?>
  <div class="fb-page-row" data-page="<?= (int)$pg['id'] ?>">
    <div class="fb-page-id">
      <a class="fb-page-title" href="/feedback/page?id=<?= (int)$pg['id'] ?>"><?= e($pg['title']) ?></a>
      <a class="tile-origin" href="<?= e($pg['url']) ?>" target="_blank" rel="noopener"><?= e($pg['url']) ?></a>
    </div>
    <span class="tile-counts">
      <span class="pill pill-queued"><?= $open ?> pending</span>
      <span class="pill pill-complete"><?= (int)$pg['closed_count'] ?> acknowledged</span>
    </span>
    <div class="fb-page-actions">
      <button type="button" class="btn-ghost copy-share" data-url="<?= e(feedback_share_url($pg)) ?>">Copy link</button>
      <a class="btn-ghost" href="/feedback/page?id=<?= (int)$pg['id'] ?>">Review</a>
      <button type="button" class="linklike-danger delete-fb-page" data-page="<?= (int)$pg['id'] ?>"
              data-name="<?= e($pg['title']) ?>">Delete</button>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php layout_bottom(); ?>
