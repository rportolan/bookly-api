-- Bookly MVP schema (MySQL 8+)
-- Drop order matters because of FK
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS reading_logs;
DROP TABLE IF EXISTS chapters;
DROP TABLE IF EXISTS vocab;
DROP TABLE IF EXISTS quotes;
DROP TABLE IF EXISTS user_books;
DROP TABLE IF EXISTS books;
DROP TABLE IF EXISTS users;

SET FOREIGN_KEY_CHECKS = 1;

-- USERS
CREATE TABLE users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email VARCHAR(190) NOT NULL,
  username VARCHAR(60) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  first_name VARCHAR(80) NOT NULL DEFAULT '',
  last_name VARCHAR(80) NOT NULL DEFAULT '',
  bio TEXT NULL,

  goal_pages_per_day INT UNSIGNED NOT NULL DEFAULT 20,
  language VARCHAR(10) NOT NULL DEFAULT 'FR',
  density VARCHAR(10) NOT NULL DEFAULT 'Comfort',

  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- BOOKS (catalog)
CREATE TABLE books (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  title VARCHAR(255) NOT NULL,
  author VARCHAR(255) NOT NULL,
  genre VARCHAR(120) NULL,
  pages INT UNSIGNED NOT NULL DEFAULT 0,
  publisher VARCHAR(255) NULL,
  cover_url TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY idx_books_title (title),
  KEY idx_books_author (author)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- USER_BOOKS (user-specific state + notes)
CREATE TABLE user_books (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  book_id BIGINT UNSIGNED NOT NULL,

  status ENUM('À lire','En cours','Terminé','Abandonné','En pause') NOT NULL DEFAULT 'À lire',
  progress_pages INT UNSIGNED NOT NULL DEFAULT 0,
  rating DECIMAL(2,1) NULL,
  started_at DATE NULL,
  finished_at DATE NULL,

  summary MEDIUMTEXT NULL,
  analysis_work MEDIUMTEXT NULL,

  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),

  -- prevent duplicates of same book for same user
  UNIQUE KEY uq_user_books_user_book (user_id, book_id),

  KEY idx_user_books_user (user_id),
  KEY idx_user_books_status (user_id, status),
  KEY idx_user_books_book (book_id),

  CONSTRAINT fk_user_books_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE,

  CONSTRAINT fk_user_books_book
    FOREIGN KEY (book_id) REFERENCES books(id)
    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- QUOTES
CREATE TABLE quotes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_book_id BIGINT UNSIGNED NOT NULL,
  content TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY idx_quotes_user_book (user_book_id),
  CONSTRAINT fk_quotes_user_book
    FOREIGN KEY (user_book_id) REFERENCES user_books(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- VOCAB
CREATE TABLE vocab (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_book_id BIGINT UNSIGNED NOT NULL,
  word VARCHAR(190) NOT NULL,
  definition TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY idx_vocab_user_book (user_book_id),
  KEY idx_vocab_word (word),
  CONSTRAINT fk_vocab_user_book
    FOREIGN KEY (user_book_id) REFERENCES user_books(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CHAPTERS
CREATE TABLE chapters (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_book_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(255) NOT NULL,
  resume MEDIUMTEXT NULL,
  observation MEDIUMTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY idx_chapters_user_book (user_book_id),
  CONSTRAINT fk_chapters_user_book
    FOREIGN KEY (user_book_id) REFERENCES user_books(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- READING LOG (1 row per day per user)
CREATE TABLE reading_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  day DATE NOT NULL,
  pages INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uq_reading_logs_user_day (user_id, day),
  KEY idx_reading_logs_user_day (user_id, day),

  CONSTRAINT fk_reading_logs_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
