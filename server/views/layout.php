<?php

function layout_top(string $title, ?array $me = null): void {
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> — YaHerd</title>
<link rel="stylesheet" href="/assets/dashboard.css">
</head>
<body>
<header class="topbar">
  <a class="brand" href="/">📌 YaHerd</a>
  <?php if ($me): ?>
  <nav>
    <a href="/board">Board</a>
    <?php if ($me['role'] === 'admin'): ?>
      <a href="/admin/projects">Projects</a>
      <a href="/admin/users">Users</a>
    <?php endif; ?>
  </nav>
  <div class="userbox">
    <span><?= e($me['display_name']) ?></span>
    <a href="/logout">Log out</a>
  </div>
  <?php endif; ?>
</header>
<main>
<?php
}

function layout_bottom(): void {
    ?></main>
<script src="/assets/dashboard.js"></script>
</body>
</html><?php
}

function status_label(string $s): string {
    return ['queued' => 'Queued', 'working_on' => 'Working on', 'complete' => 'Complete'][$s] ?? $s;
}
