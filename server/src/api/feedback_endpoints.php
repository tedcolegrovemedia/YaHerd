<?php
// Feedback projects API. Authoring endpoints require project membership
// (admins are members everywhere); /api/share/<token> endpoints are public
// and scoped to the one review the token unlocks.

function feedback_project_or_404(int $projectId): array {
    $stmt = db()->prepare("SELECT * FROM projects WHERE id = ? AND type = 'feedback'");
    $stmt->execute([$projectId]);
    $p = $stmt->fetch();
    if (!$p) json_out(['error' => 'not found'], 404);
    return $p;
}

function feedback_review_for_author(int $reviewId): array {
    $u = require_auth();
    $stmt = db()->prepare('SELECT * FROM feedback_reviews WHERE id = ?');
    $stmt->execute([$reviewId]);
    $review = $stmt->fetch();
    if (!$review) json_out(['error' => 'not found'], 404);
    require_project_member($u, (int)$review['project_id']);
    return $review;
}

// Load a page the current user may author on (with its review's project).
function feedback_page_for_author(int $pageId): array {
    $u = require_auth();
    $stmt = db()->prepare(
        'SELECT fp.*, r.project_id FROM feedback_pages fp
         JOIN feedback_reviews r ON r.id = fp.review_id WHERE fp.id = ?'
    );
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

function feedback_url_from_input($raw): string {
    $url = trim((string)$raw);
    if ($url !== '' && !preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
    if (!feedback_normalize_url($url)) json_out(['error' => 'Enter the page’s full URL'], 422);
    return substr($url, 0, 1000);
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

function feedback_review_out(array $r): array {
    return [
        'id'        => (int)$r['id'],
        'title'     => $r['title'],
        'site_url'  => $r['site_url'],
        'share_url' => feedback_share_url($r),
        'pages'     => feedback_review_pages((int)$r['id']),
    ];
}

// Add (or, with $replace, re-snapshot) the page at $url in a review. The HTML
// comes from the extension (captured in the reviewer's browser) when given,
// else the server fetches it. Returns [pageId, created?].
function feedback_upsert_page(array $review, string $url, ?string $html, bool $replace, int $userId): array {
    $key = feedback_url_key($url);
    $stmt = db()->prepare('SELECT id FROM feedback_pages WHERE review_id = ? AND url_key = ?');
    $stmt->execute([(int)$review['id'], $key]);
    $existing = $stmt->fetchColumn();
    if ($existing && !$replace) return [(int)$existing, false];

    $pageId = $existing ? (int)$existing : null;
    if (!$pageId) {
        db()->prepare(
            'INSERT INTO feedback_pages (review_id, url, url_key, final_url, created_by) VALUES (?, ?, ?, ?, ?)'
        )->execute([(int)$review['id'], $url, $key, $url, $userId]);
        $pageId = (int)db()->lastInsertId();
    }
    try {
        if ($html !== null) {
            feedback_store_snapshot($pageId, $html);
            $final = $url;
            $title = feedback_html_title($html);
        } else {
            ['final_url' => $final, 'title' => $title] = feedback_take_snapshot($pageId, $url);
        }
    } catch (RuntimeException $e) {
        if (!$existing) {
            db()->prepare('DELETE FROM feedback_pages WHERE id = ?')->execute([$pageId]);
            @unlink(feedback_snapshot_path($pageId));
        }
        throw $e;
    }
    db()->prepare('UPDATE feedback_pages SET final_url = ?, title = ?, snapshot_at = NOW() WHERE id = ?')
        ->execute([substr($final, 0, 1000), $title, $pageId]);
    return [$pageId, !$existing];
}

function feedback_html_from_input(array $in): ?string {
    if (!isset($in['html'])) return null;
    $html = (string)$in['html'];
    if (trim($html) === '') json_out(['error' => 'The captured page was empty'], 422);
    return $html;
}

// Feedback projects I can author on, with their reviews and pages — what the
// extension needs to recognize pages and offer reviews.
route('GET', '#^/api/feedback/projects$#', function () {
    $u = require_auth();
    if ($u['role'] === 'admin') {
        $projects = db()->query("SELECT id, name FROM projects WHERE type = 'feedback' AND is_active = 1 ORDER BY name")->fetchAll();
    } else {
        $stmt = db()->prepare(
            "SELECT p.id, p.name FROM projects p JOIN project_users pu ON pu.project_id = p.id
             WHERE pu.user_id = ? AND p.type = 'feedback' AND p.is_active = 1 ORDER BY p.name"
        );
        $stmt->execute([$u['id']]);
        $projects = $stmt->fetchAll();
    }
    $out = [];
    foreach ($projects as $p) {
        $stmt = db()->prepare('SELECT * FROM feedback_reviews WHERE project_id = ? ORDER BY title');
        $stmt->execute([(int)$p['id']]);
        $out[] = [
            'id'      => (int)$p['id'],
            'name'    => $p['name'],
            'reviews' => array_map('feedback_review_out', $stmt->fetchAll()),
        ];
    }
    json_out(['projects' => $out]);
});

// Start a review. Its first page is snapshotted when possible; if the server
// can't fetch it (bot protection etc.) the review is still created and the
// error is returned as a warning so the extension can capture it instead.
route('POST', '#^/api/feedback/projects/(\d+)/reviews$#', function ($pid) {
    $u = require_auth();
    feedback_project_or_404((int)$pid);
    require_project_member($u, (int)$pid);
    $in = read_json_body();
    $url = feedback_url_from_input($in['url'] ?? '');
    $html = feedback_html_from_input($in);
    $title = mb_substr(trim($in['title'] ?? ''), 0, 150);
    if ($title === '') $title = (string)parse_url($url, PHP_URL_HOST);
    $site = trim($in['site_url'] ?? '');
    $site = $site !== '' ? (feedback_normalize_url($site) ?? feedback_site_prefix($url)) : feedback_site_prefix($url);

    db()->prepare(
        'INSERT INTO feedback_reviews (project_id, title, site_url, share_token, created_by) VALUES (?, ?, ?, ?, ?)'
    )->execute([(int)$pid, $title, substr($site, 0, 1000), feedback_new_token(), (int)$u['id']]);
    $reviewId = (int)db()->lastInsertId();
    $review = ['id' => $reviewId];

    $pageId = null;
    $warning = null;
    try {
        [$pageId] = feedback_upsert_page($review, $url, $html, false, (int)$u['id']);
    } catch (RuntimeException $e) {
        $warning = $e->getMessage();
    }
    json_out(['id' => $reviewId, 'page_id' => $pageId, 'warning' => $warning], 201);
});

route('PATCH', '#^/api/feedback/reviews/(\d+)$#', function ($id) {
    $review = feedback_review_for_author((int)$id);
    $in = read_json_body();
    if (isset($in['title'])) {
        $title = mb_substr(trim($in['title']), 0, 150);
        if ($title === '') json_out(['error' => 'name required'], 422);
        db()->prepare('UPDATE feedback_reviews SET title = ? WHERE id = ?')->execute([$title, (int)$review['id']]);
    }
    if (isset($in['site_url'])) {
        $site = feedback_normalize_url($in['site_url']);
        if (!$site) json_out(['error' => 'Enter the site’s full URL'], 422);
        db()->prepare('UPDATE feedback_reviews SET site_url = ? WHERE id = ?')->execute([substr($site, 0, 1000), (int)$review['id']]);
    }
    // A new token revokes the old share link.
    if (!empty($in['new_link'])) {
        db()->prepare('UPDATE feedback_reviews SET share_token = ? WHERE id = ?')
            ->execute([feedback_new_token(), (int)$review['id']]);
    }
    $stmt = db()->prepare('SELECT * FROM feedback_reviews WHERE id = ?');
    $stmt->execute([(int)$review['id']]);
    json_out(['ok' => true, 'review' => feedback_review_out($stmt->fetch())]);
});

route('DELETE', '#^/api/feedback/reviews/(\d+)$#', function ($id) {
    $review = feedback_review_for_author((int)$id);
    $stmt = db()->prepare('SELECT id FROM feedback_pages WHERE review_id = ?');
    $stmt->execute([(int)$review['id']]);
    $pageIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    db()->prepare('DELETE FROM feedback_reviews WHERE id = ?')->execute([(int)$review['id']]);
    foreach ($pageIds as $pageId) @unlink(feedback_snapshot_path((int)$pageId));
    json_out(['ok' => true]);
});

// Add a page by URL (server fetch) or with captured HTML (extension). An
// already-added page is returned as-is unless replace is set.
route('POST', '#^/api/feedback/reviews/(\d+)/pages$#', function ($id) {
    $u = require_auth();
    $review = feedback_review_for_author((int)$id);
    $in = read_json_body();
    $url = feedback_url_from_input($in['url'] ?? '');
    try {
        [$pageId, $created] = feedback_upsert_page($review, $url, feedback_html_from_input($in), !empty($in['replace']), (int)$u['id']);
    } catch (RuntimeException $e) {
        json_out(['error' => $e->getMessage()], 422);
    }
    json_out(['page_id' => $pageId, 'created' => $created, 'review' => feedback_review_out($review)], $created ? 201 : 200);
});

// Re-snapshot a page: from captured HTML when given, else by fetching it.
// Existing pins re-anchor by selector; code already quoted in notes is kept.
route('POST', '#^/api/feedback/pages/(\d+)/snapshot$#', function ($id) {
    $u = require_auth();
    $page = feedback_page_for_author((int)$id);
    $in = read_json_body();
    try {
        feedback_upsert_page(['id' => (int)$page['review_id']], $page['url'], feedback_html_from_input($in), true, (int)$u['id']);
    } catch (RuntimeException $e) {
        json_out(['error' => $e->getMessage()], 422);
    }
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

function feedback_share_review_or_404(string $token): array {
    $review = feedback_review_by_token($token);
    if (!$review) json_out(['error' => 'This feedback link is no longer valid'], 404);
    return $review;
}

// Students can only acknowledge notes (or undo that) — nothing else.
route('POST', '#^/api/share/([\w-]{32})/notes/(\d+)/(close|reopen)$#', function ($token, $noteId, $action) {
    $review = feedback_share_review_or_404($token);
    $stmt = db()->prepare(
        'SELECT n.page_id FROM feedback_notes n JOIN feedback_pages fp ON fp.id = n.page_id
         WHERE n.id = ? AND fp.review_id = ?'
    );
    $stmt->execute([(int)$noteId, (int)$review['id']]);
    $pageId = $stmt->fetchColumn();
    if (!$pageId) json_out(['error' => 'not found'], 404);
    db()->prepare(
        'UPDATE feedback_notes SET closed_at = ' . ($action === 'close' ? 'COALESCE(closed_at, NOW())' : 'NULL') . ' WHERE id = ?'
    )->execute([(int)$noteId]);
    json_out(['ok' => true, 'notes' => feedback_notes((int)$pageId)]);
});
