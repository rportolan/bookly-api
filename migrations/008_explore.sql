-- Curated explore sections and books
-- Books are added monthly via seed scripts; only the 10 most recent per category are shown.

CREATE TABLE explore_categories (
  id         TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug       VARCHAR(100)     NOT NULL,
  title      VARCHAR(255)     NOT NULL,
  sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,

  PRIMARY KEY (id),
  UNIQUE KEY uq_explore_cat_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE explore_books (
  id               INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  category_id      TINYINT UNSIGNED NOT NULL,
  title            VARCHAR(500)     NOT NULL,
  author           VARCHAR(255)     NOT NULL DEFAULT '',
  cover_url        VARCHAR(1000)    DEFAULT NULL,
  description      TEXT             DEFAULT NULL,
  pages            SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  genre            VARCHAR(255)     DEFAULT NULL,
  isbn13           VARCHAR(13)      DEFAULT NULL,
  isbn10           VARCHAR(13)      DEFAULT NULL,
  provider_book_id VARCHAR(255)     DEFAULT NULL,
  source           VARCHAR(50)      NOT NULL DEFAULT 'isbndb',
  created_at       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY idx_eb_cat_id (category_id, id),

  CONSTRAINT fk_eb_category
    FOREIGN KEY (category_id) REFERENCES explore_categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
