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

// Harden the session cookie. Secure flag follows the original protocol when
// behind a TLS-terminating proxy/tunnel (Cloudflare sets X-Forwarded-Proto).
$isHttps = !empty($_SERVER['HTTPS'])
    || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

$method = $_SERVER['REQUEST_METHOD'];

// ---------- API ----------
if (str_starts_with($path, '/api/')) {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PATCH, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type');
    if ($method === 'OPTIONS') { http_response_code(204); exit; }

    // CSRF guard: a state-changing API call that would ride on the session
    // cookie (no Bearer header) must come from our own origin. Bearer-token
    // requests (the extension) are unaffected; cookies aren't sent by curl.
    $usesBearer = (bool)preg_match('/^Bearer\s/i', $_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if (!in_array($method, ['GET', 'OPTIONS'], true)
        && !$usesBearer
        && !empty($_SESSION['user_id'])
        && !empty($_SERVER['HTTP_ORIGIN'])) {
        $originHost = parse_url($_SERVER['HTTP_ORIGIN'], PHP_URL_HOST) ?? '';
        $originPort = parse_url($_SERVER['HTTP_ORIGIN'], PHP_URL_PORT);
        $expected   = $_SERVER['HTTP_HOST'] ?? '';
        $actual     = $originHost . ($originPort ? ':' . $originPort : '');
        if (strcasecmp($actual, $expected) !== 0) {
            json_out(['error' => 'cross-origin request rejected'], 403);
        }
    }

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
        $ip = client_ip();
        if (login_throttled($ip)) {
            $login_error = 'Too many failed attempts — try again in 10 minutes.';
        } else {
            $u = verify_credentials($_POST['email'] ?? '', $_POST['password'] ?? '');
            if ($u) {
                clear_login_failures($ip);
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int)$u['id'];
                header('Location: /'); exit;
            }
            record_login_failure($ip);
            $login_error = 'Invalid email or password.';
        }
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
