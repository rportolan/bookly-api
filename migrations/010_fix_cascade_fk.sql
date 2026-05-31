-- Migration 010: Repair ON DELETE CASCADE on all tables that reference user_books.
--
-- Why this exists: the live database may have been created or partially migrated
-- without the FK constraints being active (e.g. FOREIGN_KEY_CHECKS=0 at creation
-- time, or tables rebuilt without running prior migrations). As a result, deleting
-- a user_books row does NOT cascade-delete vocab / quotes / chapters / characters /
-- etc., leaving orphaned rows behind.
--
-- This migration is SAFE to re-run. It drops every FK that points at user_books
-- (whatever its current name) and re-adds it with ON DELETE CASCADE.

SET FOREIGN_KEY_CHECKS = 0;

-- ──────────────────────────────────────────────────────────
-- Helper procedure: drop ALL FKs on a table that reference
-- a given parent table, then re-add a single named FK.
-- ──────────────────────────────────────────────────────────
DROP PROCEDURE IF EXISTS _bookly_fix_fk;

DELIMITER //
CREATE PROCEDURE _bookly_fix_fk(
    IN p_child   VARCHAR(64),   -- child table name
    IN p_col     VARCHAR(64),   -- FK column in child
    IN p_parent  VARCHAR(64),   -- parent table name
    IN p_fk_name VARCHAR(64)    -- desired FK constraint name
)
BEGIN
    DECLARE done     INT DEFAULT 0;
    DECLARE cur_fk   VARCHAR(255);

    -- Cursor over every FK on p_child that points at p_parent
    DECLARE fk_cur CURSOR FOR
        SELECT kcu.CONSTRAINT_NAME
        FROM   information_schema.KEY_COLUMN_USAGE kcu
        JOIN   information_schema.TABLE_CONSTRAINTS tc
               ON  tc.CONSTRAINT_NAME   = kcu.CONSTRAINT_NAME
               AND tc.TABLE_SCHEMA      = kcu.TABLE_SCHEMA
               AND tc.TABLE_NAME        = kcu.TABLE_NAME
               AND tc.CONSTRAINT_TYPE   = 'FOREIGN KEY'
        WHERE  kcu.TABLE_SCHEMA         = DATABASE()
          AND  kcu.TABLE_NAME           = p_child
          AND  kcu.REFERENCED_TABLE_NAME = p_parent;

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;

    OPEN fk_cur;
    drop_loop: LOOP
        FETCH fk_cur INTO cur_fk;
        IF done THEN LEAVE drop_loop; END IF;

        SET @drop_sql = CONCAT('ALTER TABLE `', p_child, '` DROP FOREIGN KEY `', cur_fk, '`');
        PREPARE _s FROM @drop_sql;
        EXECUTE _s;
        DEALLOCATE PREPARE _s;
    END LOOP;
    CLOSE fk_cur;

    -- Re-add with ON DELETE CASCADE
    SET @add_sql = CONCAT(
        'ALTER TABLE `', p_child, '` ',
        'ADD CONSTRAINT `', p_fk_name, '` ',
        'FOREIGN KEY (`', p_col, '`) ',
        'REFERENCES `', p_parent, '`(`id`) ',
        'ON DELETE CASCADE'
    );
    PREPARE _s FROM @add_sql;
    EXECUTE _s;
    DEALLOCATE PREPARE _s;
END//
DELIMITER ;

-- ──────────────────────────────────────────────────────────
-- Step 1: purge orphaned rows (user_book_id with no parent)
-- This is required before MySQL will accept the FK constraint.
-- ──────────────────────────────────────────────────────────

DELETE FROM vocab            WHERE user_book_id NOT IN (SELECT id FROM user_books);
DELETE FROM quotes           WHERE user_book_id NOT IN (SELECT id FROM user_books);
DELETE FROM chapters         WHERE user_book_id NOT IN (SELECT id FROM user_books);
DELETE FROM characters       WHERE user_book_id NOT IN (SELECT id FROM user_books);
DELETE FROM user_book_blocks WHERE user_book_id NOT IN (SELECT id FROM user_books);
DELETE FROM book_analyses    WHERE user_book_id NOT IN (SELECT id FROM user_books);
DELETE FROM reading_sessions WHERE user_book_id NOT IN (SELECT id FROM user_books);

-- Also clean up learn_progress whose items no longer exist
DELETE FROM learn_progress
WHERE
  (item_type = 'vocab'     AND item_id NOT IN (SELECT id FROM vocab))
  OR (item_type = 'quote'  AND item_id NOT IN (SELECT id FROM quotes))
  OR (item_type = 'character' AND item_id NOT IN (SELECT id FROM characters));

-- ──────────────────────────────────────────────────────────
-- Step 2: apply ON DELETE CASCADE FKs
-- ──────────────────────────────────────────────────────────

CALL _bookly_fix_fk('vocab',            'user_book_id', 'user_books', 'fk_vocab_user_book');
CALL _bookly_fix_fk('quotes',           'user_book_id', 'user_books', 'fk_quotes_user_book');
CALL _bookly_fix_fk('chapters',         'user_book_id', 'user_books', 'fk_chapters_user_book');
CALL _bookly_fix_fk('characters',       'user_book_id', 'user_books', 'fk_characters_user_book');
CALL _bookly_fix_fk('user_book_blocks', 'user_book_id', 'user_books', 'fk_user_book_blocks_user_book');
CALL _bookly_fix_fk('book_analyses',    'user_book_id', 'user_books', 'fk_book_analyses_user_book');
CALL _bookly_fix_fk('reading_sessions', 'user_book_id', 'user_books', 'fk_rs_ub');

-- ──────────────────────────────────────────────────────────
-- Cleanup
-- ──────────────────────────────────────────────────────────
DROP PROCEDURE IF EXISTS _bookly_fix_fk;

SET FOREIGN_KEY_CHECKS = 1;
