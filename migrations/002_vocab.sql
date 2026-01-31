-- migrations/002_vocab.sql

CREATE TABLE IF NOT EXISTS vocab (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_book_id BIGINT UNSIGNED NOT NULL,
  word VARCHAR(255) NOT NULL,
  definition TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  INDEX idx_vocab_user_book_id (user_book_id),
  UNIQUE KEY uq_vocab_user_book_word (user_book_id, word),

  CONSTRAINT fk_vocab_user_book
    FOREIGN KEY (user_book_id) REFERENCES user_books(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
