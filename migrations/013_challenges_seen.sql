-- ─────────────────────────────────────────────────────────────────────────────
-- 013 · Challenges — colonne seen pour l'écran de récompense
-- ─────────────────────────────────────────────────────────────────────────────
-- seen = 0 : complété mais l'écran de récompense n'a pas encore été montré
-- seen = 1 : l'utilisateur a vu l'écran de récompense

ALTER TABLE user_challenge_completions
    ADD COLUMN seen TINYINT(1) NOT NULL DEFAULT 0 AFTER xp_awarded;
