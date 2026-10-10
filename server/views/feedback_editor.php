<?php
require __DIR__ . '/layout.php';

$stmt = db()->prepare(
    "SELECT fp.*, p.name AS project_name FROM feedback_pages fp
     JOIN projects p ON p.id = fp.project_id WHERE fp.id = ? AND p.type = 'feedback'"
);
$stmt->execute([(int)($_GET['id'] ?? 0)]);
$page = $stmt->fetch();
if (!$page || !is_project_member($me, (int)$page['project_id'])) { http_response_code(404); exit('Not found'); }

layout_top($page['title'], $me, true);
?>
<div class="fb-app" data-mode="edit" data-page="<?= (int)$page['id'] ?>" data-token="<?= e($page['share_token']) ?>">
  <div class="fb-toolbar">
    <a class="crumb" href="/feedback?project=<?= (int)$page['project_id'] ?>">← <?= e($page['project_name']) ?></a>
    <div class="fb-title">
      <strong id="fb-title"><?= e($page['title']) ?></strong>
      <a class="tile-origin" href="<?= e($page['url']) ?>" target="_blank" rel="noopener"><?= e($page['url']) ?></a>
    </div>
    <div class="fb-viewports" role="group" aria-label="Preview width">
      <button type="button" class="btn-ghost active" data-width="">Desktop</button>
      <button type="button" class="btn-ghost" data-width="768">Tablet</button>
      <button type="button" class="btn-ghost" data-width="390">Phone</button>
    </div>
    <div class="fb-tools">
      <button type="button" class="btn-ghost" id="fb-resnap"
              title="Snapshot taken <?= e($page['snapshot_at'] ? time_ago($page['snapshot_at']) : 'never') ?>">↻ Refresh snapshot</button>
      <a class="btn-ghost" id="fb-open-share" href="<?= e(feedback_share_url($page)) ?>" target="_blank" rel="noopener">Student view ↗</a>
      <button type="button" class="btn-ghost" id="fb-relink" title="Make a new link; the current one stops working">Reset link</button>
      <button type="button" id="fb-copy" data-url="<?= e(feedback_share_url($page)) ?>">Copy share link</button>
    </div>
  </div>

  <div class="fb-split">
    <div class="fb-stage">
      <iframe class="fb-frame" src="/f/<?= e($page['share_token']) ?>/frame"
              sandbox="allow-scripts allow-popups allow-popups-to-escape-sandbox"
              title="Snapshot of <?= e($page['title']) ?>"></iframe>
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
<script type="application/json" id="fb-initial"><?=
    json_encode(feedback_notes((int)$page['id']), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP)
?></script>
<?php layout_bottom(['feedback.js']); ?>
