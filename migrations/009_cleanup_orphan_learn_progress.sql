-- One-time cleanup: remove learn_progress rows whose item no longer exists
-- (orphaned after book deletions that happened before the FK-cleanup fix).

DELETE FROM learn_progress
WHERE
  (item_type = 'vocab'     AND item_id NOT IN (SELECT id FROM vocab))
  OR (item_type = 'quote'  AND item_id NOT IN (SELECT id FROM quotes))
  OR (item_type = 'character' AND item_id NOT IN (SELECT id FROM characters));
