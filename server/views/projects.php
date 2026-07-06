<?php
require __DIR__ . '/layout.php';

if ($me['role'] === 'admin') {
    $projects = db()->query('SELECT * FROM projects WHERE is_active = 1 ORDER BY name')->fetchAll();
} else {
    $stmt = db()->prepare(
        'SELECT p.* FROM projects p JOIN project_users pu ON pu.project_id = p.id
         WHERE pu.user_id = ? AND p.is_active = 1 ORDER BY p.name'
    );
    $stmt->execute([$me['id']]);
    $projects = $stmt->fetchAll();
}

// Per-project: open/total counts and a fallback card image (latest pin screenshot).
$stats = [];
if ($projects) {
    $ids = implode(',', array_map(fn($p) => (int)$p['id'], $projects));
    foreach (db()->query(
        "SELECT project_id,
                SUM(status = 'queued')     AS queued,
                SUM(status = 'working_on') AS working_on,
                SUM(status = 'complete')   AS complete
         FROM comments WHERE project_id IN ($ids) GROUP BY project_id"
    ) as $r) $stats[(int)$r['project_id']] = $r;
    foreach (db()->query(
        "SELECT c.project_id, MAX(c.id) AS latest_shot
         FROM comments c WHERE c.project_id IN ($ids) AND c.screenshot_path IS NOT NULL
         GROUP BY c.project_id"
    ) as $r) $stats[(int)$r['project_id']]['latest_shot'] = (int)$r['latest_shot'];
}

layout_top('Projects', $me);
?>
<h1>Projects</h1>
<?php if (!$projects): ?>
  <p class="empty"><?= $me['role'] === 'admin'
      ? 'No projects yet. <a href="/admin/projects">Create one</a> to get started.'
      : 'No projects assigned to you yet. Ask your admin.' ?></p>
<?php endif; ?>

<div class="project-grid">
<?php foreach ($projects as $p): $pid = (int)$p['id']; $s = $stats[$pid] ?? []; ?>
  <a class="project-tile" href="/board?project=<?= $pid ?>">
    <div class="tile-shot">
      <?php if ($p['cover_path']): ?>
        <img src="/api/projects/<?= $pid ?>/cover" alt="" loading="lazy">
      <?php elseif (!empty($s['latest_shot'])): ?>
        <img src="/api/screenshots/<?= (int)$s['latest_shot'] ?>" alt="" loading="lazy">
      <?php else: ?>
        <div class="tile-placeholder"><span><?= e(mb_strtoupper(mb_substr($p['name'], 0, 1))) ?></span></div>
      <?php endif; ?>
    </div>
    <div class="tile-body">
      <strong><?= e($p['name']) ?></strong>
      <span class="tile-origin"><?= e($p['base_origin']) ?></span>
      <span class="tile-counts">
        <span class="pill pill-queued"><?= (int)($s['queued'] ?? 0) ?> queued</span>
        <span class="pill pill-working"><?= (int)($s['working_on'] ?? 0) ?> working</span>
        <span class="pill pill-complete"><?= (int)($s['complete'] ?? 0) ?> done</span>
      </span>
    </div>
  </a>
<?php endforeach; ?>
</div>
<p class="hint" style="margin-top:14px">Tip: to set a project's cover image, open the site with the extension and click <strong>🖼 Set board cover</strong> in the YaHerd sidebar.</p>
<?php layout_bottom(); ?>
