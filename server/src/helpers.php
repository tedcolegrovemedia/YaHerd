<?php

function json_out($data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function read_json_body(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

// Normalize a URL down to its origin: "https://Example.com:443/foo" -> "https://example.com"
function origin_of(string $url): ?string {
    $p = parse_url(trim($url));
    if (!$p || empty($p['host'])) {
        // Maybe the user typed a bare domain like "example.com"
        $p = parse_url('https://' . trim($url));
        if (!$p || empty($p['host'])) return null;
    }
    $scheme = strtolower($p['scheme'] ?? 'https');
    if (!in_array($scheme, ['http', 'https'], true)) return null;
    $host = strtolower($p['host']);
    $origin = $scheme . '://' . $host;
    $defaultPort = $scheme === 'https' ? 443 : 80;
    if (!empty($p['port']) && (int)$p['port'] !== $defaultPort) {
        $origin .= ':' . (int)$p['port'];
    }
    return $origin;
}

// Path + query used to group comments per page: "/pricing?tab=teams"
function path_of(string $url): string {
    $p = parse_url(trim($url));
    $path = $p['path'] ?? '/';
    if ($path === '') $path = '/';
    if (!empty($p['query'])) $path .= '?' . $p['query'];
    return substr($path, 0, 500);
}

// Does $origin belong to project (exact origin, or subdomain when enabled)?
function origin_matches_project(string $origin, array $project): bool {
    if ($origin === $project['base_origin']) return true;
    if (!(int)$project['match_subdomains']) return false;
    $o = parse_url($origin);
    $b = parse_url($project['base_origin']);
    if (!$o || !$b || empty($o['host']) || empty($b['host'])) return false;
    return ($o['scheme'] ?? '') === ($b['scheme'] ?? '')
        && str_ends_with($o['host'], '.' . $b['host']);
}

function e(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// Compact relative time, e.g. "just now", "5m ago", "3h ago", "Jul 6".
function time_ago(string $datetime): string {
    $ts = strtotime($datetime);
    if ($ts === false) return '';
    $diff = time() - $ts;
    if ($diff < 60)     return 'just now';
    if ($diff < 3600)   return floor($diff / 60) . 'm ago';
    if ($diff < 86400)  return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M j', $ts);
}
