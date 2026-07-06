<?php

route('POST', '#^/api/login$#', function () {
    $in = read_json_body();
    if (empty($in['email']) || empty($in['password'])) {
        json_out(['error' => 'email and password required'], 422);
    }
    $ip = client_ip();
    if (login_throttled($ip)) {
        json_out(['error' => 'too many failed attempts — try again in 10 minutes'], 429);
    }
    $u = verify_credentials($in['email'], $in['password']);
    if (!$u) {
        record_login_failure($ip);
        json_out(['error' => 'invalid credentials'], 401);
    }
    clear_login_failures($ip);
    $token = issue_token((int)$u['id'], $in['label'] ?? 'extension');
    json_out(['token' => $token, 'user' => public_user($u)]);
});

route('POST', '#^/api/logout$#', function () {
    $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^Bearer\s+([a-f0-9]{64})$/i', $hdr, $m)) {
        $stmt = db()->prepare('DELETE FROM auth_tokens WHERE token_hash = ?');
        $stmt->execute([hash('sha256', $m[1])]);
    }
    json_out(['ok' => true]);
});

route('GET', '#^/api/me$#', function () {
    json_out(['user' => public_user(require_auth())]);
});
