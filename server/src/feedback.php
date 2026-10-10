<?php
// Feedback projects: one-sided reviews. A review (e.g. one student's site) has
// one public link (/f/<token>) and any number of pages. Each page is a
// snapshot of one URL — fetched by the server, or captured from the
// reviewer's browser by the extension — replayed inside a sandboxed iframe
// with the notes overlaid.

const FEEDBACK_MAX_BYTES     = 5 * 1024 * 1024;
const FEEDBACK_MAX_REDIRECTS = 5;
const FEEDBACK_CODE_MAX_LINES = 80;

function feedback_snapshot_path(int $pageId): string {
    return UPLOAD_DIR . '/snapshots/' . $pageId . '.html';
}

// Canonical form of a page URL for matching: lowercase scheme/host, no default
// port, no fragment, "/index.html" treated as "/". Mirrors normUrl() in
// lf-frame.js and the extension.
function feedback_normalize_url(string $url): ?string {
    $p = parse_url(trim($url));
    if (!$p || empty($p['host'])) return null;
    $scheme = strtolower($p['scheme'] ?? '');
    if (!in_array($scheme, ['http', 'https'], true)) return null;
    $port = !empty($p['port']) && (int)$p['port'] !== ($scheme === 'https' ? 443 : 80) ? ':' . (int)$p['port'] : '';
    $path = ($p['path'] ?? '') === '' ? '/' : $p['path'];
    $path = preg_replace('#/index\.html?$#i', '/', $path);
    $query = isset($p['query']) && $p['query'] !== '' ? '?' . $p['query'] : '';
    return $scheme . '://' . strtolower($p['host']) . $port . $path . $query;
}

function feedback_url_key(string $url): string {
    return sha1(feedback_normalize_url($url) ?? $url);
}

// The "folder" a URL lives in — a review's default site prefix, so the
// extension recognizes every page under it.
function feedback_site_prefix(string $url): string {
    $norm = feedback_normalize_url($url) ?? $url;
    $norm = preg_replace('/\?.*$/', '', $norm);
    return substr($norm, 0, strrpos($norm, '/') + 1);
}

function feedback_html_title(string $html): ?string {
    if (!preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m)) return null;
    $t = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    return $t === '' ? null : mb_substr($t, 0, 150);
}

function feedback_new_token(): string {
    return rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
}

// Refuse loopback/private/reserved targets so the snapshotter can't be aimed
// at the server's own network. Set FEEDBACK_ALLOW_PRIVATE_HOSTS in config.php
// to review pages on a LAN or localhost.
function feedback_public_ips(string $host): array {
    $ips = [];
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips = [$host];
    } else {
        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $r) {
            if (!empty($r['ip']))   $ips[] = $r['ip'];
            if (!empty($r['ipv6'])) $ips[] = $r['ipv6'];
        }
        if (!$ips) $ips = @gethostbynamel($host) ?: [];
    }
    if (!$ips) throw new RuntimeException("Couldn't resolve $host");
    $allowPrivate = defined('FEEDBACK_ALLOW_PRIVATE_HOSTS') && FEEDBACK_ALLOW_PRIVATE_HOSTS;
    if (!$allowPrivate) {
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new RuntimeException('That address is on a private network and can’t be snapshotted');
            }
        }
    }
    return $ips;
}

// Fetch an HTML page, following redirects by hand so every hop is re-checked.
// Returns ['html' => UTF-8 string, 'final_url' => string].
function feedback_fetch(string $url): array {
    for ($hop = 0; $hop <= FEEDBACK_MAX_REDIRECTS; $hop++) {
        $p = parse_url($url);
        $scheme = strtolower($p['scheme'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true) || empty($p['host'])) {
            throw new RuntimeException('Enter a full http(s) URL');
        }
        $host = trim($p['host'], '[]');
        $port = (int)($p['port'] ?? ($scheme === 'https' ? 443 : 80));
        $ips = feedback_public_ips($host);

        $body = '';
        $tooBig = false;
        $challenged = false;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_ENCODING       => '',
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; YaHerd-Feedback/1.0)',
            CURLOPT_HTTPHEADER     => ['Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.5'],
            // Pin the connection to the address we just vetted (no DNS rebinding).
            CURLOPT_RESOLVE        => [$host . ':' . $port . ':' . (str_contains($ips[0], ':') ? '[' . $ips[0] . ']' : $ips[0])],
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$challenged) {
                if (preg_match('/^cf-mitigated:\s*challenge/i', $line)) $challenged = true;
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$body, &$tooBig) {
                $body .= $chunk;
                if (strlen($body) > FEEDBACK_MAX_BYTES) { $tooBig = true; return 0; }
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $ctype  = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $redirect = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $err = curl_error($ch);

        if ($tooBig) throw new RuntimeException('That page is larger than 5 MB');
        if ($ok === false) throw new RuntimeException('Couldn’t load the page: ' . $err);
        if ($status >= 300 && $status < 400 && $redirect !== '') { $url = $redirect; continue; }
        if ($challenged) {
            throw new RuntimeException('The site’s bot protection (Cloudflare) blocked YaHerd. Open the page in Chrome with the YaHerd extension instead — it saves the page from your browser.');
        }
        if ($status === 401 || $status === 403) {
            throw new RuntimeException("The site refused YaHerd (HTTP $status). If it loads in your browser, open it with the YaHerd extension instead — it saves the page from your browser.");
        }
        if ($status >= 400) throw new RuntimeException("The site answered with HTTP $status");
        if ($ctype !== '' && !preg_match('#text/html|application/xhtml#i', $ctype)) {
            throw new RuntimeException('That URL isn’t an HTML page');
        }

        // Normalize to UTF-8 so the replay can always be served as UTF-8.
        $charset = null;
        if (preg_match('/charset=([\w-]+)/i', $ctype, $m)) $charset = $m[1];
        elseif (preg_match('/<meta[^>]+charset=["\']?([\w-]+)/i', substr($body, 0, 4096), $m)) $charset = $m[1];
        if ($charset && strcasecmp($charset, 'utf-8') !== 0 && strcasecmp($charset, 'utf8') !== 0) {
            $converted = @mb_convert_encoding($body, 'UTF-8', $charset);
            if ($converted !== false) $body = $converted;
        }
        if (str_starts_with($body, "\xEF\xBB\xBF")) $body = substr($body, 3);
        return ['html' => $body, 'final_url' => $url];
    }
    throw new RuntimeException('Too many redirects');
}

function feedback_store_snapshot(int $pageId, string $html): void {
    if (strlen($html) > FEEDBACK_MAX_BYTES) throw new RuntimeException('That page is larger than 5 MB');
    if (!mb_check_encoding($html, 'UTF-8')) $html = mb_convert_encoding($html, 'UTF-8', 'Windows-1252');
    $dir = UPLOAD_DIR . '/snapshots';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    if (file_put_contents(feedback_snapshot_path($pageId), $html) === false) {
        throw new RuntimeException('Couldn’t store the snapshot');
    }
}

// Fetch $url server-side and store it as page $pageId's snapshot.
// Returns ['final_url' => ..., 'title' => ?string].
function feedback_take_snapshot(int $pageId, string $url): array {
    $res = feedback_fetch($url);
    feedback_store_snapshot($pageId, $res['html']);
    return ['final_url' => $res['final_url'], 'title' => feedback_html_title($res['html'])];
}

function feedback_snapshot_source(int $pageId): ?string {
    $file = feedback_snapshot_path($pageId);
    return is_file($file) ? file_get_contents($file) : null;
}

// Split source into lines the same way the annotator counts them ("\n").
function feedback_source_lines(string $src): array {
    return explode("\n", str_replace("\r\n", "\n", $src));
}

// Tag every element's start tag with data-lf-src="<startLine>-<endLine>" so a
// click inside the replay can be mapped back to the student's source. This is
// a forgiving scanner, not a full HTML parser: comments, doctype and raw-text
// elements (script/style/…) are skipped, close tags are matched against a
// stack, and siblings like <li>/<p> that close implicitly end on the line
// before the next one starts. Unmatched elements end on their start line.
function feedback_annotate(string $html): string {
    $html = str_replace("\r\n", "\n", $html);
    $len = strlen($html);
    $raw  = ['script', 'style', 'textarea', 'title', 'xmp', 'noscript', 'iframe', 'noembed', 'noframes'];
    $void = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr', 'param'];
    $autoClose = ['li', 'p', 'dt', 'dd', 'option', 'tr', 'td', 'th'];

    $tags = [];   // [insertOffset, startLine, endLine]
    $stack = [];  // [name, tagIndex]
    $pos = 0; $line = 1; $linePos = 0;
    $lineAt = function (int $p) use (&$line, &$linePos, $html): int {
        if ($p > $linePos) { $line += substr_count($html, "\n", $linePos, $p - $linePos); $linePos = $p; }
        return $line;
    };

    while (($lt = strpos($html, '<', $pos)) !== false) {
        $next = $html[$lt + 1] ?? '';
        if ($next === '!' && substr($html, $lt, 4) === '<!--') {
            $end = strpos($html, '-->', $lt + 4);
            if ($end === false) break;
            $pos = $end + 3;
            continue;
        }
        if ($next === '!' || $next === '?') {
            $end = strpos($html, '>', $lt);
            if ($end === false) break;
            $pos = $end + 1;
            continue;
        }
        if ($next === '/') {
            if (!preg_match('/\G<\/([a-zA-Z][\w:-]*)[^>]*>/', $html, $m, 0, $lt)) { $pos = $lt + 1; continue; }
            $name = strtolower($m[1]);
            $closeEnd = $lt + strlen($m[0]);
            for ($i = count($stack) - 1; $i >= 0 && $stack[$i][0] !== $name; $i--);
            if ($i >= 0) {
                $endLine = $lineAt($closeEnd - 1);
                for ($j = count($stack) - 1; $j >= $i; $j--) $tags[$stack[$j][1]][2] = $endLine;
                array_splice($stack, $i);
            }
            $pos = $closeEnd;
            continue;
        }
        if (!ctype_alpha($next)) { $pos = $lt + 1; continue; }

        preg_match('/\G<([a-zA-Z][\w:-]*)/', $html, $m, 0, $lt);
        $name = strtolower($m[1]);
        // Find the tag's closing '>' while skipping quoted attribute values.
        $p = $lt + strlen($m[0]);
        while ($p < $len) {
            $p += strcspn($html, "\"'>", $p);
            if ($p >= $len || $html[$p] === '>') break;
            $q = strpos($html, $html[$p], $p + 1);
            if ($q === false) { $p = $len; break; }
            $p = $q + 1;
        }
        if ($p >= $len) break;

        $startLine = $lineAt($lt);
        $selfClose = $html[$p - 1] === '/';
        $idx = count($tags);
        $tags[] = [$selfClose ? $p - 1 : $p, $startLine, $startLine];
        $pos = $p + 1;

        if ($selfClose || in_array($name, $void, true)) {
            $tags[$idx][2] = $lineAt($p);
            continue;
        }
        if (in_array($name, $raw, true)) {
            if (!preg_match('/<\/' . $name . '\s*>/i', $html, $mm, PREG_OFFSET_CAPTURE, $pos)) break;
            $closeEnd = $mm[0][1] + strlen($mm[0][0]);
            $tags[$idx][2] = $lineAt($closeEnd - 1);
            $pos = $closeEnd;
            continue;
        }
        if (in_array($name, $autoClose, true) && $stack && end($stack)[0] === $name) {
            $prev = array_pop($stack)[1];
            $tags[$prev][2] = max($tags[$prev][1], $startLine - 1);
        }
        $stack[] = [$name, $idx];
    }

    $out = '';
    $last = 0;
    foreach ($tags as [$at, $s, $e]) {
        $out .= substr($html, $last, $at - $last) . ' data-lf-src="' . $s . '-' . $e . '"';
        $last = $at;
    }
    return $out . substr($html, $last);
}

// The replay document: annotated snapshot + <base> (so the student's relative
// CSS/images/scripts load from their site) + our overlay script, inlined so it
// doesn't depend on the base URL.
function feedback_render_frame(string $src, string $baseUrl, string $parentOrigin): string {
    $html = feedback_annotate($src);
    $script = file_get_contents(dirname(__DIR__) . '/public/assets/lf-frame.js');
    $config = json_encode(['parentOrigin' => $parentOrigin], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
    $inject = '';
    if (!preg_match('/<base\s[^>]*href/i', $html)) {
        $inject .= '<base href="' . e($baseUrl) . '">';
    }
    $inject .= '<script>window.__LF=' . $config . ';' . "\n" . str_replace('</script', '<\/script', $script) . '</script>';

    if (preg_match('/<head\b[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE)) {
        $at = $m[0][1] + strlen($m[0][0]);
        return substr($html, 0, $at) . $inject . substr($html, $at);
    }
    if (preg_match('/<html\b[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE)) {
        $at = $m[0][1] + strlen($m[0][0]);
        return substr($html, 0, $at) . '<head>' . $inject . '</head>' . substr($html, $at);
    }
    return '<head>' . $inject . '</head>' . $html;
}

// Serve the replay with a sandbox CSP so the student's scripts run in an
// opaque origin: no access to YaHerd cookies, storage or same-origin API calls,
// even if someone opens the frame URL directly.
function feedback_send_frame(array $page): never {
    // $page is a feedback_pages row.
    $src = feedback_snapshot_source((int)$page['id']);
    if ($src === null) { http_response_code(404); exit('Snapshot missing'); }
    $isHttps = !empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $parentOrigin = ($isHttps ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    header_remove('X-Frame-Options');
    header("Content-Security-Policy: sandbox allow-scripts allow-popups allow-popups-to-escape-sandbox; frame-ancestors 'self'");
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: private, no-store');
    header('Referrer-Policy: no-referrer');
    echo feedback_render_frame($src, $page['final_url'], $parentOrigin);
    exit;
}

function feedback_review_by_token(string $token): ?array {
    $stmt = db()->prepare(
        "SELECT r.*, p.name AS project_name FROM feedback_reviews r
         JOIN projects p ON p.id = r.project_id
         WHERE r.share_token = ? AND p.is_active = 1 AND p.type = 'feedback'"
    );
    $stmt->execute([$token]);
    return $stmt->fetch() ?: null;
}

// Pages of a review in the order they were added, with note counts.
function feedback_review_pages(int $reviewId): array {
    $stmt = db()->prepare(
        'SELECT fp.*,
                (SELECT COUNT(*) FROM feedback_notes n WHERE n.page_id = fp.id) AS note_count,
                (SELECT COUNT(*) FROM feedback_notes n WHERE n.page_id = fp.id AND n.closed_at IS NOT NULL) AS acked_count
         FROM feedback_pages fp WHERE fp.review_id = ? ORDER BY fp.created_at, fp.id'
    );
    $stmt->execute([$reviewId]);
    return array_map(fn($r) => [
        'id'          => (int)$r['id'],
        'title'       => $r['title'],
        'url'         => $r['url'],
        'label'       => feedback_page_label($r['url'], $r['title']),
        'note_count'  => (int)$r['note_count'],
        'acked_count' => (int)$r['acked_count'],
        'snapshot_at' => $r['snapshot_at'],
        'snapshot_ago'=> $r['snapshot_at'] ? time_ago($r['snapshot_at']) : null,
    ], $stmt->fetchAll());
}

// Short label for a page in menus: its <title>, else its path.
function feedback_page_label(string $url, ?string $title): string {
    if ($title !== null && $title !== '') return $title;
    $path = (string)parse_url($url, PHP_URL_PATH);
    return $path === '' || $path === '/' ? 'Home' : $path;
}

function feedback_note_out(array $r, int $number): array {
    return [
        'id'              => (int)$r['id'],
        'number'          => $number,
        'body'            => $r['body'],
        'author_name'     => $r['author_name'] ?? null,
        'anchor_selector' => $r['anchor_selector'],
        'anchor_offset_x' => $r['anchor_offset_x'] !== null ? (float)$r['anchor_offset_x'] : null,
        'anchor_offset_y' => $r['anchor_offset_y'] !== null ? (float)$r['anchor_offset_y'] : null,
        'anchor_text'     => $r['anchor_text'],
        'fallback_x'      => (int)$r['fallback_x'],
        'fallback_y'      => (int)$r['fallback_y'],
        'viewport_w'      => (int)$r['viewport_w'],
        'code_start'      => $r['code_start'] !== null ? (int)$r['code_start'] : null,
        'code_end'        => $r['code_end'] !== null ? (int)$r['code_end'] : null,
        'code'            => $r['code_excerpt'],
        'closed'          => !empty($r['closed_at']),
        'closed_at'       => $r['closed_at'],
        'closed_ago'      => $r['closed_at'] ? time_ago($r['closed_at']) : null,
        'created_at'      => $r['created_at'],
        'created_ago'     => time_ago($r['created_at']),
    ];
}

// Notes for a page, numbered in the order they were left.
function feedback_notes(int $pageId): array {
    $stmt = db()->prepare(
        'SELECT n.*, u.display_name AS author_name FROM feedback_notes n
         LEFT JOIN users u ON u.id = n.author_id
         WHERE n.page_id = ? ORDER BY n.created_at, n.id'
    );
    $stmt->execute([$pageId]);
    $out = [];
    foreach ($stmt->fetchAll() as $i => $r) $out[] = feedback_note_out($r, $i + 1);
    return $out;
}

// Everything the editor / student view needs up front: every page of the
// review with its notes, plus which page to show first (?page=, else the
// first page with open notes, else the first page).
function feedback_view_data(int $reviewId, ?int $wantPage): array {
    $pages = [];
    $current = null;
    foreach (feedback_review_pages($reviewId) as $pg) {
        $pg['notes'] = feedback_notes($pg['id']);
        $pages[] = $pg;
        if ($wantPage === $pg['id']) $current = $pg['id'];
    }
    if ($current === null) {
        foreach ($pages as $pg) {
            if ($pg['note_count'] > $pg['acked_count']) { $current = $pg['id']; break; }
        }
    }
    if ($current === null && $pages) $current = $pages[0]['id'];
    return ['pages' => $pages, 'current' => $current];
}

function feedback_json_script(array $data): string {
    return '<script type="application/json" id="fb-initial">'
        . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP)
        . '</script>';
}

function feedback_share_url(array $review): string {
    $base = APP_BASE_URL !== '' ? rtrim(APP_BASE_URL, '/') : (
        ((!empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http')
        . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    );
    return $base . '/f/' . $review['share_token'];
}
