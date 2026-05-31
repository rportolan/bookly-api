-- ============================================================
-- 009_auth_v2.sql — Auth v2: magic link + Google OAuth
-- ============================================================

-- 1. password_hash devient nullable (users Google / magic-link n'en ont pas)
ALTER TABLE users MODIFY COLUMN password_hash VARCHAR(255) NULL DEFAULT NULL;

-- 2. username devient nullable (plus requis à l'inscription)
ALTER TABLE users MODIFY COLUMN username VARCHAR(255) NULL DEFAULT NULL;

-- 3. Colonne Google OAuth
ALTER TABLE users ADD COLUMN google_id VARCHAR(255) NULL UNIQUE AFTER email;

-- 4. Avatar (photo de profil Google)
ALTER TABLE users ADD COLUMN avatar_url TEXT NULL AFTER google_id;

-- 5. Objectif de lecture (onboarding)
--    Valeurs: 'occasionnel' | 'regulier' | 'passionne' | 'vorace'
ALTER TABLE users ADD COLUMN reading_goal VARCHAR(20) NULL DEFAULT NULL AFTER avatar_url;

-- 6. Genres préférés (JSON array, onboarding)
ALTER TABLE users ADD COLUMN preferred_genres TEXT NULL DEFAULT NULL AFTER reading_goal;

-- 7. Flag onboarding complété
ALTER TABLE users ADD COLUMN onboarding_completed TINYINT(1) NOT NULL DEFAULT 0 AFTER preferred_genres;

-- 8. Table magic_link_tokens
CREATE TABLE IF NOT EXISTS magic_link_tokens (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id     BIGINT UNSIGNED NOT NULL,
    email       VARCHAR(255)    NOT NULL,
    token_hash  CHAR(64)        NOT NULL,
    expires_at  DATETIME        NOT NULL,
    used_at     DATETIME        NULL DEFAULT NULL,
    created_at  DATETIME        NOT NULL DEFAULT NOW(),
    UNIQUE KEY  uq_token_hash (token_hash),
    INDEX       idx_user_id    (user_id),
    INDEX       idx_email      (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
