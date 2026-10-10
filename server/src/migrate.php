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

// Deleting a user should keep their comments/replies (author shown as "no
// user"), so author_id must be nullable with ON DELETE SET NULL rather than
// the original RESTRICT.
function ensure_author_set_null(string $table, string $col): void {
    $stmt = db()->prepare(
        'SELECT rc.CONSTRAINT_NAME, rc.DELETE_RULE
         FROM information_schema.REFERENTIAL_CONSTRAINTS rc
         JOIN information_schema.KEY_COLUMN_USAGE kcu
           ON kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
          AND kcu.CONSTRAINT_NAME   = rc.CONSTRAINT_NAME
         WHERE rc.CONSTRAINT_SCHEMA = DATABASE()
           AND rc.TABLE_NAME = ?
           AND kcu.COLUMN_NAME = ?
           AND kcu.REFERENCED_TABLE_NAME = "users"'
    );
    $stmt->execute([$table, $col]);
    $fk = $stmt->fetch();
    if ($fk && $fk['DELETE_RULE'] === 'SET NULL') return; // already migrated

    if ($fk) db()->exec("ALTER TABLE $table DROP FOREIGN KEY {$fk['CONSTRAINT_NAME']}");
    db()->exec("ALTER TABLE $table MODIFY $col INT UNSIGNED NULL");
    db()->exec(
        "ALTER TABLE $table ADD CONSTRAINT fk_{$table}_{$col}
         FOREIGN KEY ($col) REFERENCES users(id) ON DELETE SET NULL"
    );
    echo "set $table.$col ON DELETE SET NULL\n";
}
ensure_author_set_null('comments', 'author_id');
ensure_author_set_null('comment_replies', 'author_id');

// Per-user email notification preferences (default on).
foreach (['notify_project_added', 'notify_assigned', 'notify_replies', 'notify_status', 'notify_mention'] as $col) {
    if (!db()->query("SHOW COLUMNS FROM users LIKE '$col'")->fetch()) {
        db()->exec("ALTER TABLE users ADD COLUMN $col TINYINT(1) NOT NULL DEFAULT 1");
        echo "added users.$col\n";
    }
}

// Task archiving.
if (!db()->query("SHOW COLUMNS FROM comments LIKE 'archived_at'")->fetch()) {
    db()->exec('ALTER TABLE comments ADD COLUMN archived_at DATETIME NULL AFTER status');
    echo "added comments.archived_at\n";
}

// In-app notification inbox.
db()->exec(
    'CREATE TABLE IF NOT EXISTS notifications (
       id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
       user_id    INT UNSIGNED NOT NULL,
       category   VARCHAR(30) NOT NULL,
       message    VARCHAR(255) NOT NULL,
       link       VARCHAR(500) NULL,
       read_at    DATETIME NULL,
       created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
       KEY idx_user_unread (user_id, read_at),
       FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
     ) ENGINE=InnoDB'
);

// Feedback projects: project type, origin optional, review pages + notes.
if (!db()->query("SHOW COLUMNS FROM projects LIKE 'type'")->fetch()) {
    db()->exec("ALTER TABLE projects ADD COLUMN type ENUM('collab','feedback') NOT NULL DEFAULT 'collab' AFTER name");
    db()->exec('ALTER TABLE projects MODIFY base_origin VARCHAR(190) NULL');
    echo "added projects.type\n";
}
db()->exec(
    'CREATE TABLE IF NOT EXISTS feedback_pages (
       id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
       project_id    INT UNSIGNED NOT NULL,
       title         VARCHAR(150) NOT NULL,
       url           VARCHAR(1000) NOT NULL,
       final_url     VARCHAR(1000) NOT NULL,
       share_token   CHAR(32) NOT NULL UNIQUE,
       snapshot_at   DATETIME NULL,
       created_by    INT UNSIGNED NULL,
       created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
       KEY idx_project (project_id),
       FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
       FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
     ) ENGINE=InnoDB'
);
db()->exec(
    'CREATE TABLE IF NOT EXISTS feedback_notes (
       id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
       page_id         INT UNSIGNED NOT NULL,
       author_id       INT UNSIGNED NULL,
       body            TEXT NOT NULL,
       anchor_selector VARCHAR(1000) NULL,
       anchor_offset_x FLOAT NULL,
       anchor_offset_y FLOAT NULL,
       anchor_text     VARCHAR(200) NULL,
       fallback_x      INT NOT NULL DEFAULT 0,
       fallback_y      INT NOT NULL DEFAULT 0,
       viewport_w      INT NOT NULL DEFAULT 0,
       code_start      INT NULL,
       code_end        INT NULL,
       code_excerpt    TEXT NULL,
       closed_at       DATETIME NULL,
       created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
       updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
       KEY idx_page (page_id),
       FOREIGN KEY (page_id)   REFERENCES feedback_pages(id) ON DELETE CASCADE,
       FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE SET NULL
     ) ENGINE=InnoDB'
);

echo "migrations complete\n";
