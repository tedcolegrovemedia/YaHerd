<?php

route('GET', '#^/api/projects$#', function () {
    $u = require_auth();
    if ($u['role'] === 'admin') {
        $rows = db()->query('SELECT * FROM projects ORDER BY name')->fetchAll();
    } else {
        $stmt = db()->prepare(
            'SELECT p.* FROM projects p
             JOIN project_users pu ON pu.project_id = p.id
             WHERE pu.user_id = ? AND p.is_active = 1 ORDER BY p.name'
        );
        $stmt->execute([$u['id']]);
        $rows = $stmt->fetchAll();
    }
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['match_subdomains'] = (int)$r['match_subdomains'];
        $r['is_active'] = (int)$r['is_active'];
    }
    json_out(['projects' => $rows]);
});

// Assignable people for a project: its members plus active admins
// (admins are implicit members everywhere).
route('GET', '#^/api/projects/(\d+)/members$#', function ($id) {
    $u = require_auth();
    require_project_member($u, (int)$id);
    $stmt = db()->prepare(
        "SELECT DISTINCT u.id, u.display_name FROM users u
         LEFT JOIN project_users pu ON pu.user_id = u.id AND pu.project_id = ?
         WHERE u.is_active = 1 AND (pu.project_id IS NOT NULL OR u.role = 'admin')
         ORDER BY u.display_name"
    );
    $stmt->execute([(int)$id]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) $r['id'] = (int)$r['id'];
    json_out(['members' => $rows]);
});

// Upload a board-cover screenshot for a project (any member, via extension).
route('POST', '#^/api/projects/(\d+)/cover$#', function ($id) {
    $u = require_auth();
    require_project_member($u, (int)$id);
    if (empty($_FILES['screenshot']) || $_FILES['screenshot']['error'] !== UPLOAD_ERR_OK) {
        json_out(['error' => 'screenshot file required'], 422);
    }
    $f = $_FILES['screenshot'];
    if ($f['size'] > 2 * 1024 * 1024) json_out(['error' => 'screenshot too large (max 2 MB)'], 422);
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
        json_out(['error' => 'screenshot must be JPEG or PNG'], 422);
    }
    $rel = 'covers/' . (int)$id . '.' . ($mime === 'image/png' ? 'png' : 'jpg');
    $dir = UPLOAD_DIR . '/covers';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    if (!move_uploaded_file($f['tmp_name'], UPLOAD_DIR . '/' . $rel)) {
        json_out(['error' => 'could not store screenshot'], 500);
    }
    db()->prepare('UPDATE projects SET cover_path = ? WHERE id = ?')->execute([$rel, (int)$id]);
    json_out(['ok' => true]);
});

route('GET', '#^/api/projects/(\d+)/cover$#', function ($id) {
    $u = require_auth();
    require_project_member($u, (int)$id);
    $stmt = db()->prepare('SELECT cover_path FROM projects WHERE id = ?');
    $stmt->execute([(int)$id]);
    $rel = $stmt->fetchColumn();
    if (!$rel) json_out(['error' => 'no cover'], 404);
    $real = realpath(UPLOAD_DIR . '/' . $rel);
    if (!$real || !str_starts_with($real, realpath(UPLOAD_DIR) . '/')) {
        json_out(['error' => 'not found'], 404);
    }
    header('Content-Type: ' . (str_ends_with($real, '.png') ? 'image/png' : 'image/jpeg'));
    header('Content-Length: ' . filesize($real));
    header('Cache-Control: private, max-age=300');
    readfile($real);
    exit;
});

route('POST', '#^/api/projects$#', function () {
    require_admin();
    $in = read_json_body();
    $origin = origin_of($in['base_origin'] ?? '');
    if (empty($in['name']) || !$origin) {
        json_out(['error' => 'name and a valid base_origin (URL or domain) required'], 422);
    }
    $stmt = db()->prepare(
        'INSERT INTO projects (name, base_origin, match_subdomains) VALUES (?, ?, ?)'
    );
    try {
        $stmt->execute([trim($in['name']), $origin, !empty($in['match_subdomains']) ? 1 : 0]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') json_out(['error' => 'a project with that origin already exists'], 409);
        throw $e;
    }
    json_out(['id' => (int)db()->lastInsertId(), 'base_origin' => $origin], 201);
});

route('PATCH', '#^/api/projects/(\d+)$#', function ($id) {
    require_admin();
    $in = read_json_body();
    $fields = [];
    $params = [];
    if (isset($in['name'])) { $fields[] = 'name = ?'; $params[] = trim($in['name']); }
    if (isset($in['base_origin'])) {
        $origin = origin_of($in['base_origin']);
        if (!$origin) json_out(['error' => 'invalid base_origin'], 422);
        $fields[] = 'base_origin = ?'; $params[] = $origin;
    }
    if (isset($in['match_subdomains'])) { $fields[] = 'match_subdomains = ?'; $params[] = $in['match_subdomains'] ? 1 : 0; }
    if (isset($in['is_active'])) { $fields[] = 'is_active = ?'; $params[] = $in['is_active'] ? 1 : 0; }
    if (!$fields) json_out(['error' => 'nothing to update'], 422);
    $params[] = (int)$id;
    db()->prepare('UPDATE projects SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
    json_out(['ok' => true]);
});

route('PUT', '#^/api/projects/(\d+)/users$#', function ($id) {
    require_admin();
    $in = read_json_body();
    $userIds = array_map('intval', $in['user_ids'] ?? []);
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM project_users WHERE project_id = ?')->execute([(int)$id]);
    $ins = $pdo->prepare('INSERT IGNORE INTO project_users (project_id, user_id) VALUES (?, ?)');
    foreach ($userIds as $uid) $ins->execute([(int)$id, $uid]);
    $pdo->commit();
    json_out(['ok' => true]);
});
