<?php
// Public student view of a feedback review. $shareReview is set by the router.
$asset = fn(string $f) => '/assets/' . $f . '?v=' . filemtime(dirname(__DIR__) . '/public/assets/' . $f);
$token = $shareReview['share_token'];
$view = feedback_view_data((int)$shareReview['id'], isset($_GET['page']) ? (int)$_GET['page'] : null);
header('X-Robots-Tag: noindex, nofollow');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Feedback: <?= e($shareReview['title']) ?></title>
<link rel="icon" type="image/png" href="<?= $asset('favicon.png') ?>">
<link rel="stylesheet" href="<?= $asset('dashboard.css') ?>">
</head>
<body class="share-body">
<div class="fb-app" data-mode="view" data-token="<?= e($token) ?>">
  <div class="fb-toolbar">
    <div class="fb-title">
      <span class="hint">Feedback on</span>
      <strong><?= e($shareReview['title']) ?></strong>
    </div>
    <div class="fb-pagebar" <?= count($view['pages']) < 2 ? 'hidden' : '' ?>>
      <label class="fb-page-pick">Page
        <select id="fb-page-select" aria-label="Page"></select>
      </label>
    </div>
    <span class="fb-progress" id="fb-progress" aria-live="polite"></span>
  </div>
  <div class="fb-split">
    <div class="fb-stage">
      <iframe class="fb-frame" sandbox="allow-scripts allow-popups allow-popups-to-escape-sandbox"
              title="Your page with feedback pins"></iframe>
      <div class="fb-stage-empty" hidden><h2>No feedback here yet</h2></div>
    </div>
    <aside class="fb-side" aria-label="Feedback">
      <p class="hint fb-intro">Click a numbered pin on the page, or a comment below, to see where it applies. Click <strong>Got it</strong> on each comment to let your teacher know you've read it.<?= count($view['pages']) > 1 ? ' Use the <strong>Page</strong> menu to see comments on your other pages.' : '' ?></p>
      <div class="fb-notes" id="fb-notes"></div>
    </aside>
  </div>
</div>
<?= feedback_json_script($view) ?>
<script src="<?= $asset('feedback.js') ?>"></script>
</body>
</html>
