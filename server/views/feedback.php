<?php
require __DIR__ . '/layout.php';

$projectId = (int)$_GET['project'];
$stmt = db()->prepare("SELECT * FROM projects WHERE id = ? AND type = 'feedback'");
$stmt->execute([$projectId]);
$project = $stmt->fetch();
if (!$project || !is_project_member($me, $projectId)) { http_response_code(404); exit('Not found'); }

$stmt = db()->prepare(
    'SELECT r.*,
            (SELECT COUNT(*) FROM feedback_pages fp WHERE fp.review_id = r.id) AS page_count,
            (SELECT COUNT(*) FROM feedback_notes n JOIN feedback_pages fp ON fp.id = n.page_id
              WHERE fp.review_id = r.id) AS note_count,
            (SELECT COUNT(*) FROM feedback_notes n JOIN feedback_pages fp ON fp.id = n.page_id
              WHERE fp.review_id = r.id AND n.closed_at IS NOT NULL) AS acked_count
     FROM feedback_reviews r WHERE r.project_id = ? ORDER BY r.title'
);
$stmt->execute([$projectId]);
$reviews = $stmt->fetchAll();

layout_top($project['name'], $me);
?>
<div class="board-head">
  <a class="crumb" href="/">← Projects</a>
  <h1 style="margin:0"><?= e($project['name']) ?></h1>
  <span class="tag tag-feedback">Feedback</span>
</div>

<form id="add-review-form" class="fb-add" data-project="<?= $projectId ?>">
  <label>Student or site name <input name="title" required placeholder="e.g. Jordan — Portfolio"></label>
  <label>Site URL <input name="url" type="url" required placeholder="https://student.github.io/portfolio/"></label>
  <button type="submit">Start review</button>
</form>
<p class="hint">Each review gets one link for the student, covering every page you comment on. Add pages by URL from the review screen, or browse the site in Chrome with the YaHerd extension: each page you comment on is saved from your browser, which also works when a site blocks YaHerd's server.</p>

<?php if (!$reviews): ?>
  <p class="empty">No reviews yet. Add a student's site above to start.</p>
<?php else: ?>
<div class="fb-pages">
  <?php foreach ($reviews as $rv):
      $pending = (int)$rv['note_count'] - (int)$rv['acked_count']; ?>
  <div class="fb-page-row" data-review="<?= (int)$rv['id'] ?>">
    <div class="fb-page-id">
      <a class="fb-page-title" href="/feedback/review?id=<?= (int)$rv['id'] ?>"><?= e($rv['title']) ?></a>
      <span class="tile-origin"><?= e($rv['site_url']) ?> · <?= (int)$rv['page_count'] ?> page<?= (int)$rv['page_count'] === 1 ? '' : 's' ?></span>
    </div>
    <span class="tile-counts">
      <span class="pill pill-queued"><?= $pending ?> pending</span>
      <span class="pill pill-complete"><?= (int)$rv['acked_count'] ?> acknowledged</span>
    </span>
    <div class="fb-page-actions">
      <button type="button" class="btn-ghost copy-share" data-url="<?= e(feedback_share_url($rv)) ?>">Copy link</button>
      <a class="btn-ghost" href="/feedback/review?id=<?= (int)$rv['id'] ?>">Review</a>
      <button type="button" class="linklike-danger delete-fb-review" data-review="<?= (int)$rv['id'] ?>"
              data-name="<?= e($rv['title']) ?>">Delete</button>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php layout_bottom(); ?>
