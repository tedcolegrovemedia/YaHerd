<?php
// Copy this file to config.php and adjust for your environment.
const DB_HOST = '127.0.0.1';
const DB_PORT = 3306;
const DB_NAME = 'webcomment';
const DB_USER = 'root';
const DB_PASS = '';

// Where uploaded pin screenshots are stored (outside the web root).
define('UPLOAD_DIR', dirname(__DIR__) . '/storage/screenshots');

// Token lifetime for extension logins, in days.
const TOKEN_TTL_DAYS = 30;

// Email. Leave SMTP_HOST empty to fall back to PHP's mail(). New users are
// emailed their login details when an admin creates their account.
const SMTP_HOST   = '';              // e.g. 'smtp.mailgun.org'
const SMTP_PORT   = 587;
const SMTP_USER   = '';
const SMTP_PASS   = '';
const SMTP_SECURE = 'tls';           // 'tls' (STARTTLS), 'ssl' (implicit), or '' (none)
const MAIL_FROM      = 'no-reply@example.com';
const MAIL_FROM_NAME = 'YaHerd';
// Public URL of this dashboard for links in emails. Empty = derive from request.
const APP_BASE_URL = '';             // e.g. 'https://feedback.example.com'
