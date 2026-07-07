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
    $unread = $me ? unread_notification_count((int)$me['id']) : 0;
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#2262e4">
<title><?= e($title) ?> — YaHerd</title>
<link rel="icon" type="image/png" href="/assets/favicon.png?v=<?= filemtime(dirname(__DIR__) . '/public/assets/favicon.png') ?>">
<link rel="apple-touch-icon" href="/assets/favicon.png?v=<?= filemtime(dirname(__DIR__) . '/public/assets/favicon.png') ?>">
<link rel="stylesheet" href="/assets/dashboard.css?v=<?= filemtime(dirname(__DIR__) . '/public/assets/dashboard.css') ?>">
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>
<header class="topbar">
  <a class="brand" href="/" aria-label="YaHerd home">
    <img class="brand-logo" src="/assets/yaherd-logo.png?v=<?= filemtime(dirname(__DIR__) . '/public/assets/yaherd-logo.png') ?>" alt="YaHerd" width="124" height="35">
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
      <span class="avatar-wrap">
        <span class="avatar" aria-hidden="true"><?= e(user_initials($me['display_name'])) ?></span>
        <span class="notif-badge" <?= $unread ? '' : 'hidden' ?> aria-label="<?= (int)$unread ?> unread notifications"><?= $unread > 9 ? '9+' : (int)$unread ?></span>
      </span>
      <span class="username"><?= e($me['display_name']) ?></span>
    </a>
    <a class="btn-ghost" href="/logout">Log out</a>
  </div>
  <?php endif; ?>
</header>
<main id="main">
<?php
}

const EXTENSION_DOWNLOAD_URL = 'https://github.com/tedcolegrovemedia/YaHerd/releases/latest/download/YaHerd-extension.zip';

function layout_bottom(): void {
    ?></main>
<footer class="site-footer">
  <a class="ext-download" href="<?= EXTENSION_DOWNLOAD_URL ?>">
    <svg viewBox="0 0 24 24" width="17" height="17" fill="none" aria-hidden="true">
      <path d="M12 3v11m0 0 4-4m-4 4-4-4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
      <path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
    </svg>
    Download the Chrome extension
  </a>
  <span class="footer-hint">Unzip, then load it at <code>chrome://extensions</code> → Developer mode → Load unpacked.</span>
</footer>
<script src="/assets/dashboard.js?v=<?= filemtime(dirname(__DIR__) . '/public/assets/dashboard.js') ?>"></script>
</body>
</html><?php
}

function status_label(string $s): string {
    return ['queued' => 'Queued', 'working_on' => 'Working on', 'complete' => 'Complete'][$s] ?? $s;
}

// Sub-navigation shown on the admin pages. $current is 'projects' or 'users'.
function admin_tabs(string $current): void {
    $tabs = ['projects' => ['/admin', 'Projects'], 'users' => ['/admin/users', 'Users']];
    echo '<nav class="subtabs" aria-label="Admin sections">';
    foreach ($tabs as $key => [$href, $label]) {
        $attr = $key === $current ? ' class="active" aria-current="page"' : '';
        echo '<a href="' . $href . '"' . $attr . '>' . e($label) . '</a>';
    }
    echo '</nav>';
}
