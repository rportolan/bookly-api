-- Tracks which blocks are active per user_book (one of each type max)
CREATE TABLE IF NOT EXISTS user_book_blocks (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_book_id BIGINT UNSIGNED NOT NULL,
  type         ENUM('analysis','quotes','vocab','chapters','characters') NOT NULL,
  sort_order   INT UNSIGNED NOT NULL DEFAULT 0,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uk_user_book_block_type (user_book_id, type),
  INDEX idx_user_book_blocks_user_book (user_book_id),

  CONSTRAINT fk_user_book_blocks_user_book
    FOREIGN KEY (user_book_id) REFERENCES user_books(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rich-text (markdown) analysis per user_book — upsertable, one row per book
CREATE TABLE IF NOT EXISTS book_analyses (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_book_id BIGINT UNSIGNED NOT NULL,
  content      MEDIUMTEXT NOT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uk_book_analyses_user_book (user_book_id),

  CONSTRAINT fk_book_analyses_user_book
    FOREIGN KEY (user_book_id) REFERENCES user_books(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Structured characters per user_book
CREATE TABLE IF NOT EXISTS characters (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_book_id BIGINT UNSIGNED NOT NULL,
  name         VARCHAR(255) NOT NULL,
  role         VARCHAR(255) NULL,
  description  TEXT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  INDEX idx_characters_user_book (user_book_id),

  CONSTRAINT fk_characters_user_book
    FOREIGN KEY (user_book_id) REFERENCES user_books(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
