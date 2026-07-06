<?php

const COMMENT_STATUSES = ['queued', 'working_on', 'complete'];
const SCREENSHOT_MAX_BYTES = 2 * 1024 * 1024;

function comment_row_out(array $r): array {
    return [
        'id'              => (int)$r['id'],
        'project_id'      => (int)$r['project_id'],
        'author_id'       => (int)$r['author_id'],
        'author_name'     => $r['author_name'] ?? null,
        'page_url'        => $r['page_url'],
        'page_path'       => $r['page_path'],
        'body'            => $r['body'],
        'status'          => $r['status'],
        'anchor_selector' => $r['anchor_selector'],
        'anchor_offset_x' => $r['anchor_offset_x'] !== null ? (float)$r['anchor_offset_x'] : null,
        'anchor_offset_y' => $r['anchor_offset_y'] !== null ? (float)$r['anchor_offset_y'] : null,
        'anchor_text'     => $r['anchor_text'],
        'fallback_x'      => (int)$r['fallback_x'],
        'fallback_y'      => (int)$r['fallback_y'],
        'viewport_w'      => (int)$r['viewport_w'],
        'has_screenshot'  => !empty($r['screenshot_path']),
        'reply_count'     => isset($r['reply_count']) ? (int)$r['reply_count'] : 0,
        'created_at'      => $r['created_at'],
        'updated_at'      => $r['updated_at'],
    ];
}

function fetch_comment_or_404(int $id): array {
    $stmt = db()->prepare('SELECT * FROM comments WHERE id = ?');
    $stmt->execute([$id]);
    $c = $stmt->fetch();
    if (!$c) json_out(['error' => 'not found'], 404);
    return $c;
}

route('GET', '#^/api/comments$#', function () {
    $u = require_auth();
    $projectId = (int)($_GET['project_id'] ?? 0);
    if (!$projectId) json_out(['error' => 'project_id required'], 422);
    require_project_member($u, $projectId);

    $sql = 'SELECT c.*, u.display_name AS author_name,
                   (SELECT COUNT(*) FROM comment_replies r WHERE r.comment_id = c.id) AS reply_count
            FROM comments c JOIN users u ON u.id = c.author_id
            WHERE c.project_id = ?';
    $params = [$projectId];
    if (!empty($_GET['page_path'])) {
        $sql .= ' AND c.page_path = ?';
        $params[] = substr($_GET['page_path'], 0, 500);
    }
    if (!empty($_GET['status']) && in_array($_GET['status'], COMMENT_STATUSES, true)) {
        $sql .= ' AND c.status = ?';
        $params[] = $_GET['status'];
    }
    $sql .= ' ORDER BY c.created_at DESC';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    json_out(['comments' => array_map('comment_row_out', $stmt->fetchAll())]);
});

route('POST', '#^/api/comments$#', function () {
    $u = require_auth();
    // multipart/form-data: fields + optional "screenshot" file
    $projectId = (int)($_POST['project_id'] ?? 0);
    if (!$projectId) json_out(['error' => 'project_id required'], 422);
    require_project_member($u, $projectId);

    $pageUrl = trim($_POST['page_url'] ?? '');
    $body    = trim($_POST['body'] ?? '');
    if ($pageUrl === '' || $body === '') json_out(['error' => 'page_url and body required'], 422);
    // page_url is rendered as a clickable link in the dashboard — never allow
    // javascript:/data: or other non-web schemes.
    $scheme = strtolower((string)parse_url($pageUrl, PHP_URL_SCHEME));
    if (!in_array($scheme, ['http', 'https'], true)) {
        json_out(['error' => 'page_url must be an http(s) URL'], 422);
    }

    $stmt = db()->prepare(
        'INSERT INTO comments
           (project_id, author_id, page_url, page_path, body,
            anchor_selector, anchor_offset_x, anchor_offset_y, anchor_text,
            fallback_x, fallback_y, viewport_w)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $projectId,
        (int)$u['id'],
        substr($pageUrl, 0, 1000),
        path_of($pageUrl),
        $body,
        ($_POST['anchor_selector'] ?? '') !== '' ? substr($_POST['anchor_selector'], 0, 1000) : null,
        isset($_POST['anchor_offset_x']) && $_POST['anchor_offset_x'] !== '' ? (float)$_POST['anchor_offset_x'] : null,
        isset($_POST['anchor_offset_y']) && $_POST['anchor_offset_y'] !== '' ? (float)$_POST['anchor_offset_y'] : null,
        ($_POST['anchor_text'] ?? '') !== '' ? substr($_POST['anchor_text'], 0, 200) : null,
        (int)($_POST['fallback_x'] ?? 0),
        (int)($_POST['fallback_y'] ?? 0),
        (int)($_POST['viewport_w'] ?? 0),
    ]);
    $id = (int)db()->lastInsertId();

    // Screenshot: validate, store outside web root, record relative path.
    if (!empty($_FILES['screenshot']) && $_FILES['screenshot']['error'] === UPLOAD_ERR_OK) {
        $f = $_FILES['screenshot'];
        if ($f['size'] > SCREENSHOT_MAX_BYTES) json_out(['error' => 'screenshot too large (max 2 MB)'], 422);
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
            json_out(['error' => 'screenshot must be JPEG or PNG'], 422);
        }
        $ext = $mime === 'image/png' ? 'png' : 'jpg';
        $rel = $projectId . '/' . $id . '.' . $ext;
        $dir = UPLOAD_DIR . '/' . $projectId;
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        if (move_uploaded_file($f['tmp_name'], UPLOAD_DIR . '/' . $rel)) {
            db()->prepare('UPDATE comments SET screenshot_path = ? WHERE id = ?')->execute([$rel, $id]);
        }
    }

    $c = fetch_comment_or_404($id);
    $c['author_name'] = $u['display_name'];
    json_out(['comment' => comment_row_out($c)], 201);
});

route('PATCH', '#^/api/comments/(\d+)/status$#', function ($id) {
    $u = require_auth();
    $c = fetch_comment_or_404((int)$id);
    require_project_member($u, (int)$c['project_id']);
    $in = read_json_body();
    if (!in_array($in['status'] ?? '', COMMENT_STATUSES, true)) {
        json_out(['error' => 'status must be one of: ' . implode(', ', COMMENT_STATUSES)], 422);
    }
    db()->prepare('UPDATE comments SET status = ? WHERE id = ?')->execute([$in['status'], (int)$id]);
    json_out(['ok' => true, 'status' => $in['status']]);
});

route('DELETE', '#^/api/comments/(\d+)$#', function ($id) {
    $u = require_auth();
    $c = fetch_comment_or_404((int)$id);
    if ($u['role'] !== 'admin' && (int)$c['author_id'] !== (int)$u['id']) {
        json_out(['error' => 'forbidden'], 403);
    }
    if ($c['screenshot_path']) @unlink(UPLOAD_DIR . '/' . $c['screenshot_path']);
    db()->prepare('DELETE FROM comments WHERE id = ?')->execute([(int)$id]);
    json_out(['ok' => true]);
});

route('GET', '#^/api/comments/(\d+)/replies$#', function ($id) {
    $u = require_auth();
    $c = fetch_comment_or_404((int)$id);
    require_project_member($u, (int)$c['project_id']);
    $stmt = db()->prepare(
        'SELECT r.id, r.body, r.created_at, r.author_id, u.display_name AS author_name
         FROM comment_replies r JOIN users u ON u.id = r.author_id
         WHERE r.comment_id = ? ORDER BY r.created_at'
    );
    $stmt->execute([(int)$id]);
    json_out(['replies' => $stmt->fetchAll()]);
});

route('POST', '#^/api/comments/(\d+)/replies$#', function ($id) {
    $u = require_auth();
    $c = fetch_comment_or_404((int)$id);
    require_project_member($u, (int)$c['project_id']);
    $in = read_json_body();
    $body = trim($in['body'] ?? '');
    if ($body === '') json_out(['error' => 'body required'], 422);
    db()->prepare('INSERT INTO comment_replies (comment_id, author_id, body) VALUES (?, ?, ?)')
        ->execute([(int)$id, (int)$u['id'], $body]);
    json_out(['id' => (int)db()->lastInsertId()], 201);
});

route('GET', '#^/api/screenshots/(\d+)$#', function ($id) {
    $u = require_auth();
    $c = fetch_comment_or_404((int)$id);
    require_project_member($u, (int)$c['project_id']);
    if (empty($c['screenshot_path'])) json_out(['error' => 'no screenshot'], 404);
    $file = UPLOAD_DIR . '/' . $c['screenshot_path'];
    $real = realpath($file);
    if (!$real || !str_starts_with($real, realpath(UPLOAD_DIR) . '/')) {
        json_out(['error' => 'not found'], 404);
    }
    header('Content-Type: ' . (str_ends_with($real, '.png') ? 'image/png' : 'image/jpeg'));
    header('Content-Length: ' . filesize($real));
    header('Cache-Control: private, max-age=3600');
    readfile($real);
    exit;
});
