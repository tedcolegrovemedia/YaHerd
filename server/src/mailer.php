<?php
// Minimal outbound email. Uses SMTP when SMTP_HOST is configured, otherwise
// falls back to PHP's mail(). Sending never throws to the caller — it returns
// false and logs, so a mail failure can't break the request that triggered it.

// Public base URL of this dashboard (used to build login links in emails).
// Prefers the configured APP_BASE_URL, else derives it from the current request.
function app_base_url(): string {
    if (defined('APP_BASE_URL') && APP_BASE_URL !== '') return rtrim(APP_BASE_URL, '/');
    $https = !empty($_SERVER['HTTPS'])
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host;
}

function mail_from_address(): string {
    return defined('MAIL_FROM') && MAIL_FROM !== '' ? MAIL_FROM : 'no-reply@localhost';
}

function mail_from_name(): string {
    return defined('MAIL_FROM_NAME') && MAIL_FROM_NAME !== '' ? MAIL_FROM_NAME : 'YaHerd';
}

// Send an email. When $html is given the message goes out as multipart with a
// plain-text alternative and the branded HTML body; otherwise it's plain text.
// Returns true on success.
function send_mail(string $to, string $toName, string $subject, string $body, ?string $html = null): bool {
    try {
        [$contentHeaders, $mimeBody] = build_email_mime($body, $html);
        if (defined('SMTP_HOST') && SMTP_HOST !== '') {
            smtp_send($to, $toName, $subject, $contentHeaders, $mimeBody);
        } else {
            $headers = 'From: ' . mail_from_name() . ' <' . mail_from_address() . ">\r\n" . $contentHeaders;
            if (!@mail($to, $subject, $mimeBody, $headers)) {
                error_log('mail() returned false sending to ' . $to);
                return false;
            }
        }
        return true;
    } catch (Throwable $e) {
        error_log('send_mail failed: ' . $e->getMessage());
        return false;
    }
}

// Assemble the MIME content headers + body. Plain text when $html is null;
// otherwise a multipart/related message: a text+HTML alternative plus the logo
// embedded inline (Content-ID <logo>, referenced as cid:logo in the HTML), so
// the logo shows even in clients that block remote images. All parts are
// base64-encoded to keep line lengths within SMTP limits and carry UTF-8
// cleanly. Falls back to a hosted logo URL if the image file can't be read.
function build_email_mime(string $text, ?string $html): array {
    $mime = "MIME-Version: 1.0\r\n";
    if ($html === null) {
        return [$mime . "Content-Type: text/plain; charset=utf-8\r\n"
              . "Content-Transfer-Encoding: 8bit\r\n", $text];
    }

    $alt = 'alt-' . bin2hex(random_bytes(8));
    $textPart = "--$alt\r\n"
        . "Content-Type: text/plain; charset=utf-8\r\n"
        . "Content-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($text));
    $htmlPart = "--$alt\r\n"
        . "Content-Type: text/html; charset=utf-8\r\n"
        . "Content-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($html));
    $altBody = $textPart . $htmlPart . "--$alt--\r\n";

    $logoPath = dirname(__DIR__) . '/public/assets/yaherd-logo.png';
    $logoData = @file_get_contents($logoPath);
    if ($logoData === false) {
        // Can't embed — point the HTML at the hosted logo and drop the wrapper.
        $altBody = str_replace('cid:logo', email_logo_url(), $altBody);
        return [$mime . "Content-Type: multipart/alternative; boundary=\"$alt\"\r\n", $altBody];
    }

    $rel = 'rel-' . bin2hex(random_bytes(8));
    $body = "--$rel\r\n"
        . "Content-Type: multipart/alternative; boundary=\"$alt\"\r\n\r\n"
        . $altBody . "\r\n"
        . "--$rel\r\n"
        . "Content-Type: image/png; name=\"yaherd-logo.png\"\r\n"
        . "Content-Transfer-Encoding: base64\r\n"
        . "Content-ID: <logo>\r\n"
        . "Content-Disposition: inline; filename=\"yaherd-logo.png\"\r\n\r\n"
        . chunk_split(base64_encode($logoData))
        . "--$rel--\r\n";
    return [$mime . "Content-Type: multipart/related; boundary=\"$rel\"; type=\"multipart/alternative\"\r\n", $body];
}

// Very small SMTP client: plain / STARTTLS / implicit-SSL, optional AUTH LOGIN.
// $contentHeaders describes the body (MIME-Version + Content-Type, built by
// build_email_mime); $body is the already-encoded payload.
function smtp_send(string $to, string $toName, string $subject, string $contentHeaders, string $body): void {
    $secure = defined('SMTP_SECURE') ? strtolower(SMTP_SECURE) : 'tls';
    $port   = defined('SMTP_PORT') ? (int)SMTP_PORT : ($secure === 'ssl' ? 465 : 587);
    $target = ($secure === 'ssl' ? 'ssl://' : '') . SMTP_HOST . ':' . $port;

    $fp = @stream_socket_client($target, $errno, $errstr, 15);
    if (!$fp) throw new RuntimeException("SMTP connect failed: $errstr ($errno)");
    stream_set_timeout($fp, 15);

    $read = function () use ($fp): string {
        $data = '';
        while (($line = fgets($fp, 515)) !== false) {
            $data .= $line;
            // A space in the 4th char marks the final line of a reply.
            if (strlen($line) < 4 || $line[3] === ' ') break;
        }
        return $data;
    };
    $expect = function (string $resp, $codes) {
        $code = (int)substr($resp, 0, 3);
        if (!in_array($code, (array)$codes, true)) {
            throw new RuntimeException('SMTP error: ' . trim($resp));
        }
    };
    $cmd = function (string $line, $codes) use ($fp, $read, $expect) {
        fwrite($fp, $line . "\r\n");
        $expect($read(), $codes);
    };

    $expect($read(), 220);
    $ehloHost = parse_url(app_base_url(), PHP_URL_HOST) ?: 'localhost';
    $cmd('EHLO ' . $ehloHost, 250);

    if ($secure === 'tls') {
        $cmd('STARTTLS', 220);
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new RuntimeException('STARTTLS negotiation failed');
        }
        $cmd('EHLO ' . $ehloHost, 250);
    }

    if (defined('SMTP_USER') && SMTP_USER !== '') {
        $cmd('AUTH LOGIN', 334);
        $cmd(base64_encode(SMTP_USER), 334);
        $cmd(base64_encode(defined('SMTP_PASS') ? SMTP_PASS : ''), 235);
    }

    $from = mail_from_address();
    $cmd('MAIL FROM:<' . $from . '>', 250);
    $cmd('RCPT TO:<' . $to . '>', [250, 251]);
    $cmd('DATA', 354);

    $headers = 'From: ' . mail_encode_name(mail_from_name()) . ' <' . $from . '>' . "\r\n"
        . 'To: ' . mail_encode_name($toName) . ' <' . $to . '>' . "\r\n"
        . 'Subject: ' . mail_encode_header($subject) . "\r\n"
        . 'Date: ' . date('r') . "\r\n"
        . $contentHeaders;

    // Normalize newlines to CRLF and dot-stuff any line starting with a dot.
    $normalized = preg_replace('/\r\n|\r|\n/', "\r\n", $body);
    $normalized = preg_replace('/^\./m', '..', $normalized);

    fwrite($fp, $headers . "\r\n" . $normalized . "\r\n.\r\n");
    $expect($read(), 250);
    fwrite($fp, "QUIT\r\n");
    fclose($fp);
}

// RFC 2047 encode a header value only if it contains non-ASCII characters.
function mail_encode_header(string $s): string {
    if (preg_match('/[^\x20-\x7E]/', $s)) {
        return '=?UTF-8?B?' . base64_encode($s) . '?=';
    }
    return $s;
}

// Encode a display name for use in From:/To: (quote it if it has ASCII specials).
function mail_encode_name(string $name): string {
    if ($name === '') return '';
    if (preg_match('/[^\x20-\x7E]/', $name)) return mail_encode_header($name);
    return '"' . str_replace('"', '', $name) . '"';
}

// ---- HTML email templating --------------------------------------------------

// Escape a value for safe inclusion in an HTML email body.
function email_esc(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

// Absolute URL to the logo — used as a fallback when it can't be embedded.
function email_logo_url(): string {
    return app_base_url() . '/assets/yaherd-logo.png';
}

// Render a message body from a simple block list into BOTH a plain-text body
// and an HTML fragment (the inner content that email_layout() wraps in the
// branded chrome). One source keeps the two versions in sync.
//
// Each block is [type, ...]:
//   ['text',   $string]                 paragraph
//   ['quote',  $string]                 quoted user content (a reply/mention)
//   ['fields', ['Label' => 'value']]    key/value list (e.g. login details)
//   ['button', $label, $url]            call-to-action link
//   ['note',   $string]                 small print
function email_render(string $name, array $blocks): array {
    $text = "Hi $name,\n\n";
    $html = '<p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#33404f;">Hi '
          . email_esc($name) . ',</p>';
    foreach ($blocks as $b) {
        switch ($b[0]) {
            case 'text':
                $text .= $b[1] . "\n\n";
                $html .= '<p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#33404f;">'
                       . nl2br(email_esc($b[1])) . '</p>';
                break;
            case 'quote':
                $text .= rtrim($b[1]) . "\n\n";
                $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 18px;">'
                       . '<tr><td style="border-left:3px solid #2262e4;background:#f6f8fc;padding:12px 16px;'
                       . 'border-radius:0 6px 6px 0;font-size:15px;line-height:1.6;color:#33404f;">'
                       . nl2br(email_esc(rtrim($b[1]))) . '</td></tr></table>';
                break;
            case 'fields':
                $rows = '';
                foreach ($b[1] as $k => $v) {
                    $text .= "  $k: $v\n";
                    $rows .= '<tr><td style="padding:5px 16px 5px 0;font-size:14px;color:#6b7280;white-space:nowrap;">'
                          . email_esc((string)$k) . '</td>'
                          . '<td style="padding:5px 0;font-size:14px;color:#1a2330;font-weight:600;">'
                          . email_esc((string)$v) . '</td></tr>';
                }
                $text .= "\n";
                $html .= '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 18px;">'
                       . $rows . '</table>';
                break;
            case 'button':
                $text .= $b[1] . ":\n  " . $b[2] . "\n\n";
                $html .= '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:6px 0 20px;">'
                       . '<tr><td style="border-radius:8px;background:#2262e4;">'
                       . '<a href="' . email_esc($b[2]) . '" style="display:inline-block;padding:12px 24px;'
                       . 'font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">'
                       . email_esc($b[1]) . '</a></td></tr></table>';
                break;
            case 'note':
                $text .= $b[1] . "\n\n";
                $html .= '<p style="margin:0 0 12px;font-size:13px;line-height:1.5;color:#6b7280;">'
                       . nl2br(email_esc($b[1])) . '</p>';
                break;
        }
    }
    return ['text' => rtrim($text) . "\n", 'html' => $html];
}

// Wrap an HTML content fragment in the branded email shell (logo header + card
// + optional footer small-print). The logo is referenced as cid:logo, embedded
// inline by build_email_mime().
function email_layout(string $innerHtml, string $footerHtml = ''): string {
    $app = email_esc(mail_from_name());
    $footer = $footerHtml !== ''
        ? '<tr><td style="padding:18px 12px 0;text-align:center;font-size:12px;line-height:1.5;color:#9aa3af;">'
          . $footerHtml . '</td></tr>'
        : '';
    return '<!doctype html><html><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1"></head>'
        . '<body style="margin:0;padding:0;background:#eef0f4;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef0f4;padding:32px 12px;">'
        . '<tr><td align="center">'
        . '<table role="presentation" width="480" cellpadding="0" cellspacing="0" style="width:480px;max-width:100%;">'
        . '<tr><td style="background:#ffffff;border:1px solid #e3e6ea;border-radius:14px;overflow:hidden;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">'
        . '<tr><td style="padding:22px 28px 18px;border-bottom:1px solid #eef0f4;">'
        . '<img src="cid:logo" alt="' . $app . '" height="26" style="height:26px;width:auto;display:block;border:0;">'
        . '</td></tr>'
        . '<tr><td style="padding:26px 28px 10px;">' . $innerHtml . '</td></tr>'
        . '</table></td></tr>'
        . $footer
        . '</table></td></tr></table></body></html>';
}

// Compose and send the "your account is ready" email to a newly-created user.
function send_welcome_email(string $email, string $name, string $password): bool {
    $base = app_base_url();
    $appName = mail_from_name();
    $subject = "Your $appName account is ready";
    ['text' => $text, 'html' => $inner] = email_render($name, [
        ['text', "An account has been created for you on $appName. Here are your sign-in details:"],
        ['fields', ['Email' => $email, 'Password' => $password]],
        ['button', 'Sign in to the dashboard', "$base/login"],
        ['text', 'You can change your password any time from the Account page after signing in.'],
        ['note', "To leave feedback on sites you've been added to, install the $appName Chrome extension "
               . "and sign in with the same email and password. When it asks for a Server URL, use: $base"],
    ]);
    $html = email_layout($inner, 'You received this because an account was created for you on ' . $appName . '.');
    return send_mail($email, $name, $subject, $text, $html);
}
