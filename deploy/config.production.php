<?php
// Production config — values come from the container environment (docker-compose).
define('DB_HOST', getenv('DB_HOST') ?: 'db');
define('DB_PORT', (int)(getenv('DB_PORT') ?: 3306));
define('DB_NAME', getenv('DB_NAME') ?: 'yaherd');
define('DB_USER', getenv('DB_USER') ?: 'yaherd');
define('DB_PASS', getenv('DB_PASSWORD') ?: '');

define('UPLOAD_DIR', dirname(__DIR__) . '/storage/screenshots');
define('TOKEN_TTL_DAYS', 30);

// Email — configured via container environment. Leave SMTP_HOST unset to fall
// back to PHP's mail(). New users are emailed their login details on creation.
define('SMTP_HOST',   getenv('SMTP_HOST') ?: '');
define('SMTP_PORT',   (int)(getenv('SMTP_PORT') ?: 587));
define('SMTP_USER',   getenv('SMTP_USER') ?: '');
define('SMTP_PASS',   getenv('SMTP_PASS') ?: '');
define('SMTP_SECURE', getenv('SMTP_SECURE') ?: 'tls');
define('MAIL_FROM',      getenv('MAIL_FROM') ?: 'no-reply@localhost');
define('MAIL_FROM_NAME', getenv('MAIL_FROM_NAME') ?: 'YaHerd');
define('APP_BASE_URL',   getenv('APP_BASE_URL') ?: '');
