-- Migration 011: Add day_start_pages to reading_sessions.
--
-- This enables absolute (not delta) daily page tracking per book.
-- Formula: pages_read_today = max(0, current_progress - day_start_pages)
--
-- day_start_pages = the book's progress_pages at the very first update of the day.
-- It is set once on INSERT and never changed (ON DUPLICATE KEY does not touch it).

DROP PROCEDURE IF EXISTS _bookly_011;
DELIMITER //
CREATE PROCEDURE _bookly_011()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'reading_sessions'
          AND COLUMN_NAME  = 'day_start_pages'
    ) THEN
        ALTER TABLE reading_sessions
            ADD COLUMN day_start_pages INT UNSIGNED NOT NULL DEFAULT 0
            AFTER user_book_id;
    END IF;
END//
DELIMITER ;
CALL _bookly_011();
DROP PROCEDURE IF EXISTS _bookly_011;
