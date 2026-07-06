<?php
// Front controller. Run with:
//   php -S localhost:8000 -t server/public server/public/index.php

$uri  = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = rawurldecode($uri ?? '/');

// php -S router mode: serve real files (assets) directly.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . $path;
    if ($path !== '/' && is_file($file)) return false;
}

$src = dirname(__DIR__) . '/src';
if (!is_file($src . '/config.php')) {
    http_response_code(500);
    exit('Missing server/src/config.php — copy config.example.php to config.php and set your DB credentials.');
}
require $src . '/config.php';
require $src . '/db.php';
require $src . '/helpers.php';
require $src . '/auth.php';
require $src . '/router.php';

session_start();
$method = $_SERVER['REQUEST_METHOD'];

// ---------- API ----------
if (str_starts_with($path, '/api/')) {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PATCH, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type');
    if ($method === 'OPTIONS') { http_response_code(204); exit; }

    require $src . '/api/auth_endpoints.php';
    require $src . '/api/users_endpoints.php';
    require $src . '/api/projects_endpoints.php';
    require $src . '/api/comments_endpoints.php';

    try {
        dispatch($method, $path);
    } catch (Throwable $e) {
        error_log($e->getMessage());
        json_out(['error' => 'server error'], 500);
    }
    exit;
}

// ---------- Dashboard ----------
$views = dirname(__DIR__) . '/views';

// First run: no users yet -> force admin creation.
$userCount = (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
if ($userCount === 0) {
    if ($method === 'POST' && $path === '/setup') {
        $email = trim(strtolower($_POST['email'] ?? ''));
        $name  = trim($_POST['display_name'] ?? '');
        $pass  = $_POST['password'] ?? '';
        $err = null;
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $err = 'Valid email required.';
        elseif ($name === '') $err = 'Name required.';
        elseif (strlen($pass) < 8) $err = 'Password must be at least 8 characters.';
        if (!$err) {
            db()->prepare("INSERT INTO users (email, display_name, password_hash, role) VALUES (?, ?, ?, 'admin')")
                ->execute([$email, $name, password_hash($pass, PASSWORD_DEFAULT)]);
            $_SESSION['user_id'] = (int)db()->lastInsertId();
            header('Location: /'); exit;
        }
        $setup_error = $err;
    }
    require $views . '/setup.php';
    exit;
}

// Login / logout
if ($path === '/login') {
    if ($method === 'POST') {
        $u = verify_credentials($_POST['email'] ?? '', $_POST['password'] ?? '');
        if ($u) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$u['id'];
            header('Location: /'); exit;
        }
        $login_error = 'Invalid email or password.';
    }
    require $views . '/login.php';
    exit;
}
if ($path === '/logout') {
    session_destroy();
    header('Location: /login'); exit;
}

$me = user_from_session();
if (!$me) { header('Location: /login'); exit; }

switch ($path) {
    case '/':
    case '/board':
        require $views . '/board.php';
        break;
    case '/task':
        require $views . '/task_detail.php';
        break;
    case '/admin/users':
        if ($me['role'] !== 'admin') { http_response_code(403); exit('Forbidden'); }
        require $views . '/admin_users.php';
        break;
    case '/admin/projects':
        if ($me['role'] !== 'admin') { http_response_code(403); exit('Forbidden'); }
        require $views . '/admin_projects.php';
        break;
    default:
        http_response_code(404);
        exit('Not found');
}
