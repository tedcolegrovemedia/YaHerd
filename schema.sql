-- WebComment schema
-- Create DB first:
--   CREATE DATABASE webcomment CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- Then: mysql -u root webcomment < schema.sql

CREATE TABLE users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email         VARCHAR(190) NOT NULL UNIQUE,
  display_name  VARCHAR(100) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('admin','user') NOT NULL DEFAULT 'user',
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  notify_project_added TINYINT(1) NOT NULL DEFAULT 1,
  notify_assigned      TINYINT(1) NOT NULL DEFAULT 1,
  notify_replies       TINYINT(1) NOT NULL DEFAULT 1,
  notify_status        TINYINT(1) NOT NULL DEFAULT 1,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE auth_tokens (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  token_hash  CHAR(64) NOT NULL UNIQUE,
  label       VARCHAR(50) NOT NULL DEFAULT 'extension',
  expires_at  DATETIME NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE projects (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name             VARCHAR(100) NOT NULL,
  base_origin      VARCHAR(190) NOT NULL,
  match_subdomains TINYINT(1) NOT NULL DEFAULT 0,
  is_active        TINYINT(1) NOT NULL DEFAULT 1,
  cover_path       VARCHAR(300) NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_origin (base_origin)
) ENGINE=InnoDB;

CREATE TABLE project_users (
  project_id INT UNSIGNED NOT NULL,
  user_id    INT UNSIGNED NOT NULL,
  PRIMARY KEY (project_id, user_id),
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE comments (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id      INT UNSIGNED NOT NULL,
  author_id       INT UNSIGNED NULL,
  assignee_id     INT UNSIGNED NULL,
  page_url        VARCHAR(1000) NOT NULL,
  page_path       VARCHAR(500)  NOT NULL,
  body            TEXT NOT NULL,
  status          ENUM('queued','working_on','complete') NOT NULL DEFAULT 'queued',
  archived_at     DATETIME NULL,
  anchor_selector VARCHAR(1000) NULL,
  anchor_offset_x FLOAT NULL,
  anchor_offset_y FLOAT NULL,
  anchor_text     VARCHAR(200) NULL,
  fallback_x      INT NOT NULL,
  fallback_y      INT NOT NULL,
  viewport_w      INT NOT NULL,
  screenshot_path VARCHAR(300) NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_project_path (project_id, page_path(191)),
  KEY idx_project_status (project_id, status),
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  FOREIGN KEY (author_id)  REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (assignee_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE login_attempts (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip         VARCHAR(45) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ip_time (ip, created_at)
) ENGINE=InnoDB;

CREATE TABLE comment_replies (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  comment_id INT UNSIGNED NOT NULL,
  author_id  INT UNSIGNED NULL,
  body       TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (comment_id) REFERENCES comments(id) ON DELETE CASCADE,
  FOREIGN KEY (author_id)  REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- In-app notification inbox (parallel to the activity emails).
CREATE TABLE notifications (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  category   VARCHAR(30) NOT NULL,
  message    VARCHAR(255) NOT NULL,
  link       VARCHAR(500) NULL,
  read_at    DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user_unread (user_id, read_at),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
