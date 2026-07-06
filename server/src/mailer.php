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

// Send a plain-text email. Returns true on success.
function send_mail(string $to, string $toName, string $subject, string $body): bool {
    try {
        if (defined('SMTP_HOST') && SMTP_HOST !== '') {
            smtp_send($to, $toName, $subject, $body);
        } else {
            $headers = 'From: ' . mail_from_name() . ' <' . mail_from_address() . ">\r\n"
                . "MIME-Version: 1.0\r\n"
                . "Content-Type: text/plain; charset=utf-8\r\n"
                . "Content-Transfer-Encoding: 8bit";
            if (!@mail($to, $subject, $body, $headers)) {
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

// Very small SMTP client: plain / STARTTLS / implicit-SSL, optional AUTH LOGIN.
function smtp_send(string $to, string $toName, string $subject, string $body): void {
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
        . 'MIME-Version: 1.0' . "\r\n"
        . 'Content-Type: text/plain; charset=utf-8' . "\r\n"
        . 'Content-Transfer-Encoding: 8bit' . "\r\n";

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

// Compose and send the "your account is ready" email to a newly-created user.
function send_welcome_email(string $email, string $name, string $password): bool {
    $base = app_base_url();
    $appName = mail_from_name();
    $subject = "Your $appName account is ready";
    $body = "Hi $name,\n\n"
        . "An account has been created for you on $appName.\n\n"
        . "Sign in to the dashboard:\n"
        . "  $base/login\n\n"
        . "Your login details:\n"
        . "  Email:    $email\n"
        . "  Password: $password\n\n"
        . "You can change your password any time from the Account page after signing in.\n\n"
        . "To leave feedback on sites you've been added to, install the $appName Chrome "
        . "extension and sign in with the same email and password. When the extension asks "
        . "for a Server URL, use:\n"
        . "  $base\n\n"
        . "— $appName\n";
    return send_mail($email, $name, $subject, $body);
}
