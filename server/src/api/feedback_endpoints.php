<?php
// Feedback projects API. Authoring endpoints require project membership
// (admins are members everywhere); /api/share/<token> endpoints are public
// and scoped to the one page the token unlocks.

function feedback_project_or_404(int $projectId): array {
    $stmt = db()->prepare("SELECT * FROM projects WHERE id = ? AND type = 'feedback'");
    $stmt->execute([$projectId]);
    $p = $stmt->fetch();
    if (!$p) json_out(['error' => 'not found'], 404);
    return $p;
}

// Load a page the current user may author on.
function feedback_page_for_author(int $pageId): array {
    $u = require_auth();
    $stmt = db()->prepare('SELECT * FROM feedback_pages WHERE id = ?');
    $stmt->execute([$pageId]);
    $page = $stmt->fetch();
    if (!$page) json_out(['error' => 'not found'], 404);
    require_project_member($u, (int)$page['project_id']);
    return $page;
}

function feedback_note_for_author(int $noteId): array {
    $stmt = db()->prepare('SELECT * FROM feedback_notes WHERE id = ?');
    $stmt->execute([$noteId]);
    $note = $stmt->fetch();
    if (!$note) json_out(['error' => 'not found'], 404);
    feedback_page_for_author((int)$note['page_id']);
    return $note;
}

// Validate a requested code range against the snapshot and slice it out.
// Returns [start, end, excerpt] or [null, null, null] when no code is attached.
function feedback_code_from_input(array $in, int $pageId): array {
    if (empty($in['code_start'])) return [null, null, null];
    $src = feedback_snapshot_source($pageId);
    if ($src === null) return [null, null, null];
    $lines = feedback_source_lines($src);
    $start = max(1, (int)$in['code_start']);
    $end = max($start, (int)($in['code_end'] ?? $start));
    if ($start > count($lines)) json_out(['error' => 'code range is outside the page source'], 422);
    $end = min($end, count($lines), $start + FEEDBACK_CODE_MAX_LINES - 1);
    $excerpt = implode("\n", array_slice($lines, $start - 1, $end - $start + 1));
    return [$start, $end, $excerpt];
}

route('GET', '#^/api/feedback/projects/(\d+)/pages$#', function ($pid) {
    $u = require_auth();
    feedback_project_or_404((int)$pid);
    require_project_member($u, (int)$pid);
    $stmt = db()->prepare(
        'SELECT fp.*,
                (SELECT COUNT(*) FROM feedback_notes n WHERE n.page_id = fp.id) AS note_count,
                (SELECT COUNT(*) FROM feedback_notes n WHERE n.page_id = fp.id AND n.closed_at IS NOT NULL) AS closed_count
         FROM feedback_pages fp WHERE fp.project_id = ? ORDER BY fp.created_at DESC'
    );
    $stmt->execute([(int)$pid]);
    $rows = array_map(fn($r) => [
        'id'           => (int)$r['id'],
        'title'        => $r['title'],
        'url'          => $r['url'],
        'share_url'    => feedback_share_url($r),
        'note_count'   => (int)$r['note_count'],
        'closed_count' => (int)$r['closed_count'],
        'snapshot_at'  => $r['snapshot_at'],
    ], $stmt->fetchAll());
    json_out(['pages' => $rows]);
});

// Add a page to review: snapshot it now so pins and line numbers are stable.
route('POST', '#^/api/feedback/projects/(\d+)/pages$#', function ($pid) {
    $u = require_auth();
    feedback_project_or_404((int)$pid);
    require_project_member($u, (int)$pid);
    $in = read_json_body();
    $url = trim($in['url'] ?? '');
    if ($url !== '' && !preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
    $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
    if (!in_array($scheme, ['http', 'https'], true) || !parse_url($url, PHP_URL_HOST)) {
        json_out(['error' => 'Enter the page’s full URL'], 422);
    }
    $title = trim($in['title'] ?? '');
    if ($title === '') $title = (string)parse_url($url, PHP_URL_HOST) . (parse_url($url, PHP_URL_PATH) ?: '');
    $title = mb_substr($title, 0, 150);

    db()->prepare(
        'INSERT INTO feedback_pages (project_id, title, url, final_url, share_token, created_by)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([(int)$pid, $title, substr($url, 0, 1000), substr($url, 0, 1000), feedback_new_token(), (int)$u['id']]);
    $id = (int)db()->lastInsertId();
    try {
        $final = feedback_take_snapshot($id, $url);
    } catch (RuntimeException $e) {
        db()->prepare('DELETE FROM feedback_pages WHERE id = ?')->execute([$id]);
        json_out(['error' => $e->getMessage()], 422);
    }
    db()->prepare('UPDATE feedback_pages SET final_url = ?, snapshot_at = NOW() WHERE id = ?')
        ->execute([substr($final, 0, 1000), $id]);
    json_out(['id' => $id], 201);
});

route('PATCH', '#^/api/feedback/pages/(\d+)$#', function ($id) {
    $page = feedback_page_for_author((int)$id);
    $in = read_json_body();
    if (isset($in['title'])) {
        $title = mb_substr(trim($in['title']), 0, 150);
        if ($title === '') json_out(['error' => 'title required'], 422);
        db()->prepare('UPDATE feedback_pages SET title = ? WHERE id = ?')->execute([$title, (int)$page['id']]);
    }
    // A new token revokes the old share link.
    if (!empty($in['new_link'])) {
        db()->prepare('UPDATE feedback_pages SET share_token = ? WHERE id = ?')
            ->execute([feedback_new_token(), (int)$page['id']]);
    }
    $stmt = db()->prepare('SELECT * FROM feedback_pages WHERE id = ?');
    $stmt->execute([(int)$page['id']]);
    json_out(['ok' => true, 'share_url' => feedback_share_url($stmt->fetch())]);
});

// Re-snapshot the live page. Existing pins re-anchor by selector; their code
// excerpts are kept as they were when written.
route('POST', '#^/api/feedback/pages/(\d+)/snapshot$#', function ($id) {
    $page = feedback_page_for_author((int)$id);
    try {
        $final = feedback_take_snapshot((int)$page['id'], $page['url']);
    } catch (RuntimeException $e) {
        json_out(['error' => $e->getMessage()], 422);
    }
    db()->prepare('UPDATE feedback_pages SET final_url = ?, snapshot_at = NOW() WHERE id = ?')
        ->execute([substr($final, 0, 1000), (int)$page['id']]);
    json_out(['ok' => true]);
});

route('DELETE', '#^/api/feedback/pages/(\d+)$#', function ($id) {
    $page = feedback_page_for_author((int)$id);
    db()->prepare('DELETE FROM feedback_pages WHERE id = ?')->execute([(int)$page['id']]);
    @unlink(feedback_snapshot_path((int)$page['id']));
    json_out(['ok' => true]);
});

route('GET', '#^/api/feedback/pages/(\d+)/source$#', function ($id) {
    $page = feedback_page_for_author((int)$id);
    $src = feedback_snapshot_source((int)$page['id']);
    if ($src === null) json_out(['error' => 'snapshot missing'], 404);
    json_out(['lines' => feedback_source_lines($src)]);
});

route('GET', '#^/api/feedback/pages/(\d+)/notes$#', function ($id) {
    $page = feedback_page_for_author((int)$id);
    json_out(['notes' => feedback_notes((int)$page['id'])]);
});

route('POST', '#^/api/feedback/pages/(\d+)/notes$#', function ($id) {
    $u = require_auth();
    $page = feedback_page_for_author((int)$id);
    $in = read_json_body();
    $body = trim($in['body'] ?? '');
    if ($body === '') json_out(['error' => 'Write a comment first'], 422);
    [$cs, $ce, $code] = feedback_code_from_input($in, (int)$page['id']);
    $a = $in['anchor'] ?? [];
    db()->prepare(
        'INSERT INTO feedback_notes
           (page_id, author_id, body, anchor_selector, anchor_offset_x, anchor_offset_y, anchor_text,
            fallback_x, fallback_y, viewport_w, code_start, code_end, code_excerpt)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        (int)$page['id'],
        (int)$u['id'],
        $body,
        !empty($a['anchor_selector']) ? substr((string)$a['anchor_selector'], 0, 1000) : null,
        isset($a['anchor_offset_x']) ? (float)$a['anchor_offset_x'] : null,
        isset($a['anchor_offset_y']) ? (float)$a['anchor_offset_y'] : null,
        !empty($a['anchor_text']) ? mb_substr((string)$a['anchor_text'], 0, 200) : null,
        (int)($a['fallback_x'] ?? 0),
        (int)($a['fallback_y'] ?? 0),
        (int)($a['viewport_w'] ?? 0),
        $cs, $ce, $code,
    ]);
    json_out(['id' => (int)db()->lastInsertId(), 'notes' => feedback_notes((int)$page['id'])], 201);
});

route('PATCH', '#^/api/feedback/notes/(\d+)$#', function ($id) {
    $note = feedback_note_for_author((int)$id);
    $in = read_json_body();
    $fields = [];
    $params = [];
    if (isset($in['body'])) {
        $body = trim($in['body']);
        if ($body === '') json_out(['error' => 'Write a comment first'], 422);
        $fields[] = 'body = ?'; $params[] = $body;
    }
    if (array_key_exists('code_start', $in)) {
        [$cs, $ce, $code] = feedback_code_from_input($in, (int)$note['page_id']);
        array_push($fields, 'code_start = ?', 'code_end = ?', 'code_excerpt = ?');
        array_push($params, $cs, $ce, $code);
    }
    if (isset($in['closed'])) { $fields[] = 'closed_at = ' . ($in['closed'] ? 'NOW()' : 'NULL'); }
    if (!$fields) json_out(['error' => 'nothing to update'], 422);
    $params[] = (int)$note['id'];
    db()->prepare('UPDATE feedback_notes SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
    json_out(['ok' => true, 'notes' => feedback_notes((int)$note['page_id'])]);
});

route('DELETE', '#^/api/feedback/notes/(\d+)$#', function ($id) {
    $note = feedback_note_for_author((int)$id);
    db()->prepare('DELETE FROM feedback_notes WHERE id = ?')->execute([(int)$note['id']]);
    json_out(['ok' => true, 'notes' => feedback_notes((int)$note['page_id'])]);
});

// ---- Public share endpoints (the token is the credential) ----

function feedback_share_page_or_404(string $token): array {
    $page = feedback_page_by_token($token);
    if (!$page) json_out(['error' => 'This feedback link is no longer valid'], 404);
    return $page;
}

route('GET', '#^/api/share/([\w-]{32})$#', function ($token) {
    $page = feedback_share_page_or_404($token);
    json_out(['notes' => feedback_notes((int)$page['id'])]);
});

// Students can only mark notes done (or undo that) — nothing else.
route('POST', '#^/api/share/([\w-]{32})/notes/(\d+)/(close|reopen)$#', function ($token, $noteId, $action) {
    $page = feedback_share_page_or_404($token);
    $stmt = db()->prepare(
        'UPDATE feedback_notes SET closed_at = ' . ($action === 'close' ? 'COALESCE(closed_at, NOW())' : 'NULL') . '
         WHERE id = ? AND page_id = ?'
    );
    $stmt->execute([(int)$noteId, (int)$page['id']]);
    json_out(['ok' => true, 'notes' => feedback_notes((int)$page['id'])]);
});
