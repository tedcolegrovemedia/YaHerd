<?php
// Production config — values come from the container environment (docker-compose).
define('DB_HOST', getenv('DB_HOST') ?: 'db');
define('DB_PORT', (int)(getenv('DB_PORT') ?: 3306));
define('DB_NAME', getenv('DB_NAME') ?: 'yaherd');
define('DB_USER', getenv('DB_USER') ?: 'yaherd');
define('DB_PASS', getenv('DB_PASSWORD') ?: '');

define('UPLOAD_DIR', dirname(__DIR__) . '/storage/screenshots');
define('TOKEN_TTL_DAYS', 30);
