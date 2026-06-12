-- ============================================================
-- 014_explore_v2.sql — Explorer v2
--   • Catalogue maître unique (1 livre = 1 ligne, dédupliqué par ISBN)
--   • Relation many-to-many livres ↔ catégories (table pivot)
--   • Enrichissement du modèle livre (summary, why_read, etc.)
--   • Système de tags (many-to-many)
--
-- Migration NON destructive : toutes les données existantes sont
-- préservées (couvertures, ISBN, descriptions). Les nouveaux champs
-- éditoriaux (summary, why_read, tags…) sont nullables et remplis
-- progressivement.
-- ============================================================

START TRANSACTION;

-- ------------------------------------------------------------
-- 1. Table pivot livres ↔ catégories
-- ------------------------------------------------------------
CREATE TABLE `explore_book_categories` (
  `id`          int UNSIGNED      NOT NULL AUTO_INCREMENT,
  `book_id`     int UNSIGNED      NOT NULL,
  `category_id` tinyint UNSIGNED  NOT NULL,
  `sort_order`  smallint UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_ebc_cat`  (`category_id`, `sort_order`),
  KEY `idx_ebc_book` (`book_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 2. Seed du pivot depuis les liaisons existantes (category_id)
-- ------------------------------------------------------------
INSERT INTO `explore_book_categories` (`book_id`, `category_id`)
SELECT `id`, `category_id` FROM `explore_books`;

-- ------------------------------------------------------------
-- 3. Dédup : repointe les liaisons des doublons vers le livre
--    canonique (plus petit id pour un même isbn13)
-- ------------------------------------------------------------
UPDATE `explore_book_categories` ebc
JOIN `explore_books` eb ON eb.id = ebc.book_id
JOIN (
    SELECT isbn13, MIN(id) AS canonical_id
    FROM `explore_books`
    WHERE isbn13 IS NOT NULL AND isbn13 <> ''
    GROUP BY isbn13
    HAVING COUNT(*) > 1
) dup ON dup.isbn13 = eb.isbn13
SET ebc.book_id = dup.canonical_id
WHERE ebc.book_id <> dup.canonical_id;

-- ------------------------------------------------------------
-- 4. Supprime les lignes livres en doublon (garde le canonique)
-- ------------------------------------------------------------
DELETE eb FROM `explore_books` eb
JOIN (
    SELECT isbn13, MIN(id) AS canonical_id
    FROM `explore_books`
    WHERE isbn13 IS NOT NULL AND isbn13 <> ''
    GROUP BY isbn13
    HAVING COUNT(*) > 1
) dup ON dup.isbn13 = eb.isbn13
WHERE eb.id <> dup.canonical_id;

-- ------------------------------------------------------------
-- 5. Dédoublonne le pivot (sécurité : (book,cat) unique)
-- ------------------------------------------------------------
DELETE ebc FROM `explore_book_categories` ebc
JOIN `explore_book_categories` keep
  ON keep.book_id     = ebc.book_id
 AND keep.category_id = ebc.category_id
 AND keep.id          < ebc.id;

-- ------------------------------------------------------------
-- 6. Contraintes finales sur le pivot
-- ------------------------------------------------------------
ALTER TABLE `explore_book_categories`
  ADD UNIQUE KEY `uq_ebc_book_cat` (`book_id`, `category_id`),
  ADD CONSTRAINT `fk_ebc_book` FOREIGN KEY (`book_id`)     REFERENCES `explore_books` (`id`)       ON DELETE CASCADE,
  ADD CONSTRAINT `fk_ebc_cat`  FOREIGN KEY (`category_id`) REFERENCES `explore_categories` (`id`)  ON DELETE CASCADE;

-- ------------------------------------------------------------
-- 7. Détache explore_books de category_id (devient un catalogue pur)
-- ------------------------------------------------------------
ALTER TABLE `explore_books` DROP FOREIGN KEY `fk_eb_category`;
ALTER TABLE `explore_books` DROP KEY `idx_eb_cat_id`;
ALTER TABLE `explore_books` DROP COLUMN `category_id`;

-- ------------------------------------------------------------
-- 8. Enrichissement du modèle livre
--    (champs éditoriaux nullables, remplis progressivement)
-- ------------------------------------------------------------
ALTER TABLE `explore_books`
  ADD COLUMN `summary`          text             NULL              AFTER `description`,
  ADD COLUMN `why_read`         text             NULL              AFTER `summary`,
  ADD COLUMN `publication_year` smallint UNSIGNED NULL             AFTER `genre`,
  ADD COLUMN `language`         varchar(8)       NOT NULL DEFAULT 'fr' AFTER `publication_year`,
  ADD COLUMN `difficulty`       varchar(20)      NULL              AFTER `language`,
  ADD UNIQUE KEY `uq_eb_isbn13` (`isbn13`);

-- ------------------------------------------------------------
-- 9. Système de tags (many-to-many)
-- ------------------------------------------------------------
CREATE TABLE `catalog_tags` (
  `id`    smallint UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug`  varchar(60)  NOT NULL,
  `label` varchar(80)  NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tag_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `explore_book_tags` (
  `book_id` int UNSIGNED      NOT NULL,
  `tag_id`  smallint UNSIGNED NOT NULL,
  PRIMARY KEY (`book_id`, `tag_id`),
  KEY `idx_ebt_tag` (`tag_id`),
  CONSTRAINT `fk_ebt_book` FOREIGN KEY (`book_id`) REFERENCES `explore_books` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ebt_tag`  FOREIGN KEY (`tag_id`)  REFERENCES `catalog_tags` (`id`)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 10. Vocabulaire de tags de départ (mood / intention de lecture)
-- ------------------------------------------------------------
INSERT INTO `catalog_tags` (`slug`, `label`) VALUES
  ('feel-good',     'Feel-good'),
  ('dechirant',     'Déchirant'),
  ('page-turner',   'Page-turner'),
  ('intense',       'Intense'),
  ('lent',          'Contemplatif'),
  ('intellectuel',  'Intellectuel'),
  ('accessible',    'Accessible'),
  ('classique',     'Classique'),
  ('depaysant',     'Dépaysant'),
  ('inspirant',     'Inspirant'),
  ('sombre',        'Sombre'),
  ('drole',         'Drôle'),
  ('captivant',     'Captivant'),
  ('reconfortant',  'Réconfortant'),
  ('philosophique', 'Philosophique');

COMMIT;
