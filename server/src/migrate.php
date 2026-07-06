<?php
// Idempotent schema migrations for existing installs.
// Run inside the app container / server: php server/src/migrate.php

require __DIR__ . '/config.php';
require __DIR__ . '/db.php';

$hasAssignee = db()->query("SHOW COLUMNS FROM comments LIKE 'assignee_id'")->fetch();
if (!$hasAssignee) {
    db()->exec(
        'ALTER TABLE comments
           ADD COLUMN assignee_id INT UNSIGNED NULL AFTER author_id,
           ADD CONSTRAINT fk_comments_assignee
             FOREIGN KEY (assignee_id) REFERENCES users(id) ON DELETE SET NULL'
    );
    echo "added comments.assignee_id\n";
}

$hasCover = db()->query("SHOW COLUMNS FROM projects LIKE 'cover_path'")->fetch();
if (!$hasCover) {
    db()->exec('ALTER TABLE projects ADD COLUMN cover_path VARCHAR(300) NULL');
    echo "added projects.cover_path\n";
}

db()->exec(
    'CREATE TABLE IF NOT EXISTS login_attempts (
       id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
       ip         VARCHAR(45) NOT NULL,
       created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
       KEY idx_ip_time (ip, created_at)
     ) ENGINE=InnoDB'
);

echo "migrations complete\n";
