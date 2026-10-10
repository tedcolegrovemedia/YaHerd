<?php
// Feedback projects: one-sided review pages. An admin snapshots a page by URL,
// pins notes to it, and hands out a public link (/f/<token>) where the page is
// replayed inside a sandboxed iframe with the notes overlaid.

const FEEDBACK_MAX_BYTES     = 5 * 1024 * 1024;
const FEEDBACK_MAX_REDIRECTS = 5;
const FEEDBACK_CODE_MAX_LINES = 80;

function feedback_snapshot_path(int $pageId): string {
    return UPLOAD_DIR . '/snapshots/' . $pageId . '.html';
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

// Snapshot $url for page $pageId; returns the final URL after redirects.
function feedback_take_snapshot(int $pageId, string $url): string {
    $res = feedback_fetch($url);
    $dir = UPLOAD_DIR . '/snapshots';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    if (file_put_contents(feedback_snapshot_path($pageId), $res['html']) === false) {
        throw new RuntimeException('Couldn’t store the snapshot');
    }
    return $res['final_url'];
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

function feedback_page_by_token(string $token): ?array {
    $stmt = db()->prepare(
        "SELECT fp.*, p.name AS project_name FROM feedback_pages fp
         JOIN projects p ON p.id = fp.project_id
         WHERE fp.share_token = ? AND p.is_active = 1 AND p.type = 'feedback'"
    );
    $stmt->execute([$token]);
    return $stmt->fetch() ?: null;
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

function feedback_share_url(array $page): string {
    $base = APP_BASE_URL !== '' ? rtrim(APP_BASE_URL, '/') : (
        ((!empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http')
        . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    );
    return $base . '/f/' . $page['share_token'];
}
