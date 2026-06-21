-- ============================================================
-- 022_feedback_simplify.sql
--   Refonte du formulaire d'avis : 9 → 6 champs.
--   On retire les colonnes devenues inutiles. Les lignes existantes
--   sont conservées (seules ces colonnes disparaissent).
-- ============================================================

ALTER TABLE `feedback_submissions`
  DROP COLUMN `pain_points`,
  DROP COLUMN `desired_features`,
  DROP COLUMN `reader_profile`,
  DROP COLUMN `improve_one_thing`;
