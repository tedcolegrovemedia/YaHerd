<?php
require __DIR__ . '/layout.php';

$stmt = db()->prepare(
    "SELECT r.*, p.name AS project_name FROM feedback_reviews r
     JOIN projects p ON p.id = r.project_id WHERE r.id = ? AND p.type = 'feedback'"
);
$stmt->execute([(int)($_GET['id'] ?? 0)]);
$review = $stmt->fetch();
if (!$review || !is_project_member($me, (int)$review['project_id'])) { http_response_code(404); exit('Not found'); }

$view = feedback_view_data((int)$review['id'], isset($_GET['page']) ? (int)$_GET['page'] : null);
$shareUrl = feedback_share_url($review);
$warning = trim((string)($_GET['warning'] ?? ''));

layout_top($review['title'], $me, true);
?>
<div class="fb-app" data-mode="edit" data-review="<?= (int)$review['id'] ?>" data-token="<?= e($review['share_token']) ?>">
  <div class="fb-toolbar">
    <a class="crumb" href="/feedback?project=<?= (int)$review['project_id'] ?>">← <?= e($review['project_name']) ?></a>
    <div class="fb-title">
      <strong><?= e($review['title']) ?></strong>
      <a class="tile-origin" id="fb-page-url" href="<?= e($review['site_url']) ?>" target="_blank" rel="noopener"><?= e($review['site_url']) ?></a>
    </div>
    <div class="fb-pagebar">
      <label class="fb-page-pick">Page
        <select id="fb-page-select" aria-label="Page"></select>
      </label>
      <button type="button" class="btn-ghost" id="fb-add-page" title="Add another page of this site by URL">+ Page</button>
    </div>
    <div class="fb-viewports" role="group" aria-label="Preview width">
      <button type="button" class="btn-ghost active" data-width="">Desktop</button>
      <button type="button" class="btn-ghost" data-width="768">Tablet</button>
      <button type="button" class="btn-ghost" data-width="390">Phone</button>
    </div>
    <div class="fb-tools">
      <button type="button" class="btn-ghost" id="fb-resnap">↻ Refresh snapshot</button>
      <button type="button" class="btn-ghost danger" id="fb-del-page">Remove page</button>
      <a class="btn-ghost" href="<?= e($shareUrl) ?>" target="_blank" rel="noopener">Student view ↗</a>
      <button type="button" class="btn-ghost" id="fb-relink" title="Make a new link; the current one stops working">Reset link</button>
      <button type="button" id="fb-copy" data-url="<?= e($shareUrl) ?>">Copy share link</button>
    </div>
  </div>

  <?php if ($warning !== ''): ?>
    <p class="fb-warning" role="alert"><?= e($warning) ?></p>
  <?php endif; ?>

  <div class="fb-split">
    <div class="fb-stage">
      <iframe class="fb-frame" sandbox="allow-scripts allow-popups allow-popups-to-escape-sandbox"
              title="Page snapshot"></iframe>
      <div class="fb-stage-empty" hidden>
        <h2>No pages in this review yet</h2>
        <p>Click <strong>+ Page</strong> to add one by URL, or open
          <a href="<?= e($review['site_url']) ?>" target="_blank" rel="noopener"><?= e($review['site_url']) ?></a>
          in Chrome with the YaHerd extension and comment there. Each page you comment on is saved to this review.</p>
      </div>
    </div>
    <aside class="fb-side" aria-label="Comments">
      <button type="button" id="fb-add" class="fb-add-btn">+ Add comment</button>
      <p id="fb-pick-hint" class="fb-pick-hint" hidden>Click any element on the page to pin a comment. <kbd>Esc</kbd> cancels.</p>

      <form id="fb-compose" class="fb-compose" hidden>
        <div class="fb-compose-head">
          <span class="fb-num fb-num-new">+</span>
          <code id="fb-target" class="fb-target"></code>
        </div>
        <textarea name="body" rows="4" required placeholder="What should the student look at here?"></textarea>
        <label class="check"><input type="checkbox" name="attach_code"> Show code from this section</label>
        <div class="fb-code-pick" hidden>
          <div class="fb-range">
            Lines <input type="number" name="code_start" min="1"> – <input type="number" name="code_end" min="1">
          </div>
          <p class="hint">Click a line number to start there, shift-click to end. Up to <?= FEEDBACK_CODE_MAX_LINES ?> lines.</p>
          <div class="fb-source" role="listbox" aria-label="Page source"></div>
        </div>
        <div class="fb-compose-actions">
          <button type="submit">Save comment</button>
          <button type="button" class="btn-ghost" id="fb-cancel">Cancel</button>
        </div>
      </form>

      <div class="fb-notes" id="fb-notes"></div>
    </aside>
  </div>
</div>
<?= feedback_json_script($view) ?>
<?php layout_bottom(['feedback.js']); ?>
