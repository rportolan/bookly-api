-- Reading sessions: per-book-per-day reading log
CREATE TABLE reading_sessions (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id      BIGINT UNSIGNED NOT NULL,
  user_book_id BIGINT UNSIGNED NOT NULL,
  day          DATE NOT NULL,
  pages        INT UNSIGNED NOT NULL DEFAULT 0,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uq_rs_user_book_day (user_id, user_book_id, day),
  KEY idx_rs_user_day (user_id, day),

  CONSTRAINT fk_rs_user FOREIGN KEY (user_id)      REFERENCES users(id)       ON DELETE CASCADE,
  CONSTRAINT fk_rs_ub   FOREIGN KEY (user_book_id) REFERENCES user_books(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
