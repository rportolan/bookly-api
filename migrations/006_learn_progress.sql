-- migrations/006_learn_progress.sql

CREATE TABLE IF NOT EXISTS learn_progress (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id        BIGINT UNSIGNED NOT NULL,
    item_type      ENUM('vocab', 'quote', 'character') NOT NULL,
    item_id        BIGINT UNSIGNED NOT NULL,
    correct_streak TINYINT UNSIGNED NOT NULL DEFAULT 0,
    total_seen     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    status         ENUM('new', 'learning', 'mastered') NOT NULL DEFAULT 'new',
    last_seen      DATETIME NULL,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_user_item (user_id, item_type, item_id),
    INDEX idx_user_status (user_id, status),

    CONSTRAINT fk_lp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
