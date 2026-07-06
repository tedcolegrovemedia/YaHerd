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
