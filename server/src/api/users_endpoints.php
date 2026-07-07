<?php

route('GET', '#^/api/users$#', function () {
    require_admin();
    $rows = db()->query(
        'SELECT id, email, display_name, role, is_active, created_at FROM users ORDER BY display_name'
    )->fetchAll();
    foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['is_active'] = (int)$r['is_active']; }
    json_out(['users' => $rows]);
});

route('POST', '#^/api/users$#', function () {
    require_admin();
    $in = read_json_body();
    $email = trim(strtolower($in['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_out(['error' => 'valid email required'], 422);
    if (empty($in['display_name'])) json_out(['error' => 'display_name required'], 422);
    if (strlen($in['password'] ?? '') < 8) json_out(['error' => 'password must be at least 8 characters'], 422);
    $role = ($in['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
    $stmt = db()->prepare(
        'INSERT INTO users (email, display_name, password_hash, role) VALUES (?, ?, ?, ?)'
    );
    try {
        $stmt->execute([$email, trim($in['display_name']), password_hash($in['password'], PASSWORD_DEFAULT), $role]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') json_out(['error' => 'a user with that email already exists'], 409);
        throw $e;
    }
    $id = (int)db()->lastInsertId();
    // Email the new user their login details. Account creation still succeeds
    // if mail delivery fails — the UI is told via email_sent so it can warn.
    $emailSent = send_welcome_email($email, trim($in['display_name']), $in['password']);
    json_out(['id' => $id, 'email_sent' => $emailSent], 201);
});

route('PATCH', '#^/api/users/(\d+)$#', function ($id) {
    $admin = require_admin();
    $in = read_json_body();
    $fields = [];
    $params = [];
    if (isset($in['display_name'])) { $fields[] = 'display_name = ?'; $params[] = trim($in['display_name']); }
    if (isset($in['email'])) {
        $email = trim(strtolower($in['email']));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_out(['error' => 'invalid email'], 422);
        $fields[] = 'email = ?'; $params[] = $email;
    }
    if (!empty($in['password'])) {
        if (strlen($in['password']) < 8) json_out(['error' => 'password must be at least 8 characters'], 422);
        $fields[] = 'password_hash = ?'; $params[] = password_hash($in['password'], PASSWORD_DEFAULT);
    }
    if (isset($in['role'])) {
        if ((int)$id === (int)$admin['id'] && $in['role'] !== 'admin') {
            json_out(['error' => 'cannot remove your own admin role'], 422);
        }
        $fields[] = 'role = ?'; $params[] = $in['role'] === 'admin' ? 'admin' : 'user';
    }
    if (isset($in['is_active'])) {
        if ((int)$id === (int)$admin['id'] && !$in['is_active']) json_out(['error' => 'cannot deactivate yourself'], 422);
        $fields[] = 'is_active = ?'; $params[] = $in['is_active'] ? 1 : 0;
    }
    if (!$fields) json_out(['error' => 'nothing to update'], 422);
    $params[] = (int)$id;
    db()->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
    json_out(['ok' => true]);
});

route('DELETE', '#^/api/users/(\d+)$#', function ($id) {
    $admin = require_admin();
    $id = (int)$id;
    if ($id === (int)$admin['id']) json_out(['error' => 'you cannot delete your own account'], 422);
    $stmt = db()->prepare('SELECT id FROM users WHERE id = ?');
    $stmt->execute([$id]);
    if (!$stmt->fetch()) json_out(['error' => 'not found'], 404);
    // Tokens and project memberships cascade; comments/replies they authored
    // stay live with author_id set NULL (rendered as "no user"), and assigned
    // tasks fall back to unassigned.
    db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    json_out(['ok' => true]);
});
