<?php

function nav_active(string $current, array $prefixes): string {
    foreach ($prefixes as $p) {
        if ($p === '/' ? $current === '/' : str_starts_with($current, $p)) {
            return ' aria-current="page" class="active"';
        }
    }
    return '';
}

function user_initials(string $name): string {
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $first = mb_substr($parts[0] ?? '', 0, 1);
    $last  = count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '';
    return mb_strtoupper($first . $last) ?: '?';
}

function layout_top(string $title, ?array $me = null): void {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#4f46e5">
<title><?= e($title) ?> — YaHerd</title>
<link rel="stylesheet" href="/assets/dashboard.css?v=<?= filemtime(dirname(__DIR__) . '/public/assets/dashboard.css') ?>">
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>
<header class="topbar">
  <a class="brand" href="/" aria-label="YaHerd home">
    <span class="brand-mark" aria-hidden="true">
      <svg viewBox="0 0 24 24" width="22" height="22" fill="none">
        <circle cx="7" cy="9" r="3" fill="currentColor" opacity=".55"/>
        <circle cx="17" cy="9" r="3" fill="currentColor" opacity=".55"/>
        <circle cx="12" cy="15" r="3.4" fill="currentColor"/>
      </svg>
    </span>
    <span class="brand-word">YaHerd</span>
  </a>
  <?php if ($me): ?>
  <nav aria-label="Primary">
    <a href="/"<?= nav_active($path, ['/', '/board', '/task']) ?>>Projects</a>
    <?php if ($me['role'] === 'admin'): ?>
      <a href="/admin"<?= nav_active($path, ['/admin']) ?>>Admin</a>
    <?php endif; ?>
  </nav>
  <div class="userbox">
    <a class="usermenu" href="/account"<?= nav_active($path, ['/account']) ?>>
      <span class="avatar" aria-hidden="true"><?= e(user_initials($me['display_name'])) ?></span>
      <span class="username"><?= e($me['display_name']) ?></span>
    </a>
    <a class="btn-ghost" href="/logout">Log out</a>
  </div>
  <?php endif; ?>
</header>
<main id="main">
<?php
}

function layout_bottom(): void {
    ?></main>
<script src="/assets/dashboard.js?v=<?= filemtime(dirname(__DIR__) . '/public/assets/dashboard.js') ?>"></script>
</body>
</html><?php
}

function status_label(string $s): string {
    return ['queued' => 'Queued', 'working_on' => 'Working on', 'complete' => 'Complete'][$s] ?? $s;
}
