<?php

function issue_token(int $userId, string $label = 'extension'): string {
    $raw = bin2hex(random_bytes(32)); // 64 hex chars
    $stmt = db()->prepare(
        'INSERT INTO auth_tokens (user_id, token_hash, label, expires_at)
         VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? DAY))'
    );
    $stmt->execute([$userId, hash('sha256', $raw), $label, TOKEN_TTL_DAYS]);
    return $raw;
}

function user_from_bearer(): ?array {
    $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+([a-f0-9]{64})$/i', $hdr, $m)) return null;
    $stmt = db()->prepare(
        'SELECT u.* FROM auth_tokens t
         JOIN users u ON u.id = t.user_id
         WHERE t.token_hash = ? AND t.expires_at > NOW() AND u.is_active = 1'
    );
    $stmt->execute([hash('sha256', $m[1])]);
    $u = $stmt->fetch();
    return $u ?: null;
}

function user_from_session(): ?array {
    if (empty($_SESSION['user_id'])) return null;
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ? AND is_active = 1');
    $stmt->execute([$_SESSION['user_id']]);
    $u = $stmt->fetch();
    return $u ?: null;
}

function current_user(): ?array {
    static $user = false;
    if ($user === false) {
        $user = user_from_bearer() ?? user_from_session();
    }
    return $user;
}

function require_auth(): array {
    $u = current_user();
    if (!$u) json_out(['error' => 'unauthorized'], 401);
    return $u;
}

function require_admin(): array {
    $u = require_auth();
    if ($u['role'] !== 'admin') json_out(['error' => 'forbidden'], 403);
    return $u;
}

function is_project_member(array $user, int $projectId): bool {
    if ($user['role'] === 'admin') return true;
    $stmt = db()->prepare('SELECT 1 FROM project_users WHERE project_id = ? AND user_id = ?');
    $stmt->execute([$projectId, $user['id']]);
    return (bool)$stmt->fetch();
}

function require_project_member(array $user, int $projectId): void {
    if (!is_project_member($user, $projectId)) {
        json_out(['error' => 'forbidden'], 403);
    }
}

function verify_credentials(string $email, string $password): ?array {
    $stmt = db()->prepare('SELECT * FROM users WHERE email = ? AND is_active = 1');
    $stmt->execute([trim(strtolower($email))]);
    $u = $stmt->fetch();
    if (!$u || !password_verify($password, $u['password_hash'])) return null;
    return $u;
}

function public_user(array $u): array {
    return [
        'id'           => (int)$u['id'],
        'email'        => $u['email'],
        'display_name' => $u['display_name'],
        'role'         => $u['role'],
    ];
}
