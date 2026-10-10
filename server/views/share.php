<?php
// Public student view of a feedback page. $sharePage is set by the router.
$asset = fn(string $f) => '/assets/' . $f . '?v=' . filemtime(dirname(__DIR__) . '/public/assets/' . $f);
$token = $sharePage['share_token'];
header('X-Robots-Tag: noindex, nofollow');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Feedback: <?= e($sharePage['title']) ?></title>
<link rel="icon" type="image/png" href="<?= $asset('favicon.png') ?>">
<link rel="stylesheet" href="<?= $asset('dashboard.css') ?>">
</head>
<body class="share-body">
<div class="fb-app" data-mode="view" data-token="<?= e($token) ?>">
  <div class="fb-toolbar">
    <div class="fb-title">
      <span class="hint">Feedback on</span>
      <strong><?= e($sharePage['title']) ?></strong>
      <a class="tile-origin" href="<?= e($sharePage['url']) ?>" target="_blank" rel="noopener"><?= e($sharePage['url']) ?></a>
    </div>
    <span class="fb-progress" id="fb-progress" aria-live="polite"></span>
  </div>
  <div class="fb-split">
    <div class="fb-stage">
      <iframe class="fb-frame" src="/f/<?= e($token) ?>/frame"
              sandbox="allow-scripts allow-popups allow-popups-to-escape-sandbox"
              title="Your page with feedback pins"></iframe>
    </div>
    <aside class="fb-side" aria-label="Feedback">
      <p class="hint fb-intro">Click a numbered pin on the page, or a comment below, to see where it applies. Click <strong>Got it</strong> on each comment to let your teacher know you've read it.</p>
      <div class="fb-notes" id="fb-notes"></div>
    </aside>
  </div>
</div>
<script type="application/json" id="fb-initial"><?=
    json_encode(feedback_notes((int)$sharePage['id']), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP)
?></script>
<script src="<?= $asset('feedback.js') ?>"></script>
</body>
</html>
