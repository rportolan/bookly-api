CREATE TABLE IF NOT EXISTS chapters (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_book_id BIGINT UNSIGNED NOT NULL,

  title VARCHAR(255) NOT NULL,
  resume MEDIUMTEXT NULL,
  observation MEDIUMTEXT NULL,

  position INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  INDEX idx_chapters_user_book_id (user_book_id),
  INDEX idx_chapters_position (user_book_id, position),

  CONSTRAINT fk_chapters_user_book
    FOREIGN KEY (user_book_id) REFERENCES user_books(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
