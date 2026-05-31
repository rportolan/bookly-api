-- migrations/005_vocab_extended.sql

-- Add new fields to vocab
ALTER TABLE vocab
    ADD COLUMN word_type VARCHAR(100) NULL AFTER definition,
    ADD COLUMN gender VARCHAR(20) NULL AFTER word_type,
    ADD COLUMN example TEXT NULL AFTER gender;

-- Drop unique constraint on (user_book_id, word) to allow multiple entries per word
ALTER TABLE vocab DROP INDEX uq_vocab_user_book_word;

-- Create dictionary_cache table
CREATE TABLE IF NOT EXISTS dictionary_cache (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    lang VARCHAR(10) NOT NULL,
    term VARCHAR(255) NOT NULL,
    data JSON NOT NULL,
    fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,

    UNIQUE KEY uq_dict_cache_lang_term (lang, term),
    INDEX idx_dict_cache_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
