-- ============================================================
-- 017_explore_enrich_batch3.sql
--   Enrichissement de 9 livres (catégorie « Envie de pleurer ? »)
--   NB : « Des Fleurs pour Algernon » déjà enrichi en 015 → ignoré ici.
-- ============================================================

START TRANSACTION;

-- ------------------------------------------------------------
-- 1. Mille Soleils Splendides — Khaled Hosseini
-- ------------------------------------------------------------
UPDATE `explore_books` SET
  summary = "Kaboul, sur trois décennies de guerre. Mariam, fille illégitime mariée de force, et Laila, jeune fille instruite, se retrouvent unies par le destin sous le toit d'un même mari violent. D'abord rivales, elles deviennent l'unique refuge l'une de l'autre. Hosseini raconte la résilience des femmes afghanes avec une tendresse et une cruauté bouleversantes.",
  why_read = "Encore plus dévastateur que Les Cerfs-volants de Kaboul. Hosseini a ce don rare de t'attacher à des personnages au point que leur douleur devient la tienne. Un roman sur la dignité et l'amour qui survit à l'horreur. Prévois des mouchoirs — beaucoup de mouchoirs.",
  publication_year = 2007,
  language = 'fr',
  difficulty = 'accessible'
WHERE isbn13 = '9782253125273';

-- ------------------------------------------------------------
-- 2. La Vie devant soi — Romain Gary
-- ------------------------------------------------------------
UPDATE `explore_books` SET
  summary = "Momo, petit Arabe de dix ans, vit à Belleville chez Madame Rosa, ancienne prostituée juive rescapée d'Auschwitz qui garde les enfants des filles du quartier. Entre ces deux êtres que tout sépare naît un amour immense et pudique. Quand la santé de Madame Rosa décline, Momo s'accroche à elle de toutes ses forces.",
  why_read = "Prix Goncourt 1975, écrit sous pseudonyme par Romain Gary — un secret littéraire devenu légende. Drôle, tendre et déchirant, porté par la voix inoubliable de Momo. Un roman sur l'amour qui se moque des origines et de l'âge. Court, lumineux, et impossible à oublier.",
  publication_year = 1975,
  language = 'fr',
  difficulty = 'accessible'
WHERE isbn13 = '9782070368624';

-- ------------------------------------------------------------
-- 3. Never Let Me Go — Kazuo Ishiguro
-- ------------------------------------------------------------
UPDATE `explore_books` SET
  summary = "Kathy, Tommy et Ruth grandissent à Hailsham, un pensionnat anglais idyllique mais étrangement coupé du monde. Au fil de leurs souvenirs d'enfance et de leurs amours, une vérité insoutenable se dévoile peu à peu sur la raison de leur existence. Ishiguro distille l'horreur avec une douceur glaçante.",
  why_read = "Prix Nobel de littérature, Ishiguro signe un roman qui te hante longtemps après la dernière page. Tout est dit à voix basse, par petites touches — et c'est justement ce qui rend l'émotion si dévastatrice. Une méditation déchirante sur ce qui fait notre humanité.",
  publication_year = 2005,
  language = 'fr',
  difficulty = 'intermediaire'
WHERE isbn13 = '9782267023794';

-- ------------------------------------------------------------
-- 4. Là où chantent les écrevisses — Delia Owens
-- ------------------------------------------------------------
UPDATE `explore_books` SET
  summary = "Abandonnée par sa famille, Kya grandit seule dans les marais sauvages de Caroline du Nord, surnommée « la fille des marais » par les habitants méfiants. Quand un jeune homme du village est retrouvé mort, tous les soupçons se tournent vers elle. Entre roman d'apprentissage, enquête et ode à la nature, un récit envoûtant.",
  why_read = "Un phénomène mondial qui a ému des millions de lecteurs. Owens, biologiste de formation, écrit la nature comme personne et tient le suspense jusqu'au bout. Le portrait inoubliable d'une survivante, et une fin qui te cueille. Difficile de le lâcher.",
  publication_year = 2018,
  language = 'fr',
  difficulty = 'accessible'
WHERE isbn13 = '9782290219461';

-- ------------------------------------------------------------
-- 5. Tout le bleu du ciel — Mélissa Da Costa
-- ------------------------------------------------------------
UPDATE `explore_books` SET
  summary = "Émile, 26 ans, vient d'apprendre qu'il est atteint d'un Alzheimer précoce. Plutôt que d'attendre la fin, il passe une annonce : il cherche quelqu'un pour partager ses derniers mois de liberté sur les routes. C'est Joanne, mystérieuse et solitaire, qui répond. Commence alors un road trip bouleversant contre l'oubli.",
  why_read = "Un livre lumineux malgré son sujet, devenu un véritable phénomène. Da Costa transforme une histoire de fin de vie en hymne à l'instant présent et à la nature. On rit, on pleure, on referme le livre différent. Le genre de lecture qui te rappelle pourquoi la vie vaut la peine.",
  publication_year = 2019,
  language = 'fr',
  difficulty = 'accessible'
WHERE isbn13 = '9782253104483';

-- ------------------------------------------------------------
-- 6. La Délicatesse — David Foenkinos
-- ------------------------------------------------------------
UPDATE `explore_books` SET
  summary = "Nathalie mène une vie heureuse jusqu'au jour où son mari meurt brutalement. Des années plus tard, encore murée dans son chagrin, elle embrasse sans raison Markus, un collègue suédois effacé et maladroit. De ce geste absurde naît une histoire d'amour inattendue, fragile et délicate.",
  why_read = "Un roman tout en légèreté sur le deuil et la renaissance. Foenkinos manie l'humour et la tendresse avec une grâce rare — c'est doux-amer, charmant, et étonnamment réconfortant. La preuve qu'on peut parler de la perte sans jamais plomber le lecteur.",
  publication_year = 2009,
  language = 'fr',
  difficulty = 'accessible'
WHERE isbn13 = '9782070449422';

-- ------------------------------------------------------------
-- 7. Le Chant d'Achille — Madeline Miller
-- ------------------------------------------------------------
UPDATE `explore_books` SET
  summary = "La guerre de Troie racontée non par les héros, mais par Patrocle, prince exilé et compagnon de toujours d'Achille. De leur enfance à la plaine de Troie, Miller réinvente l'Iliade comme une grande histoire d'amour, jusqu'au destin tragique que les dieux ont écrit pour eux.",
  why_read = "Une réécriture du mythe grec qui transcende complètement le matériau d'origine. Miller rend Homère intime et bouleversant : on connaît la fin, et pourtant elle te brise quand même. Magnifiquement écrit, parfait pour qui aime les mythes ou les grandes romances tragiques.",
  publication_year = 2011,
  language = 'fr',
  difficulty = 'accessible'
WHERE isbn13 = '9782246781745';

-- ------------------------------------------------------------
-- 8. Une Vie — Guy de Maupassant
-- ------------------------------------------------------------
UPDATE `explore_books` SET
  summary = "Jeanne, jeune aristocrate pleine de rêves, sort du couvent persuadée que la vie lui réserve le bonheur. Le mariage, la maternité, les trahisons et les désillusions vont lentement éroder ses espérances. Maupassant brosse le destin d'une femme ordinaire avec une précision tendre et impitoyable.",
  why_read = "Le premier grand roman de Maupassant, et l'un des plus poignants du réalisme français. Pas de drame spectaculaire ici, juste la vie qui passe et déçoit — et c'est précisément ce qui serre le cœur. Une lecture courte, limpide, d'une mélancolie inoubliable.",
  publication_year = 1883,
  language = 'fr',
  difficulty = 'accessible'
WHERE isbn13 = '9782070411894';

-- ------------------------------------------------------------
-- 9. L'Enfant Océan — Jean-Claude Mourlevat
-- ------------------------------------------------------------
UPDATE `explore_books` SET
  summary = "Une nuit, sept frères fuient leur ferme misérable et leurs parents violents. À leur tête, Yann, le plus petit, muet mais d'une intelligence stupéfiante, les guide vers l'océan qu'aucun d'eux n'a jamais vu. Réécriture moderne du Petit Poucet, le récit est porté tour à tour par chaque témoin de leur fugue.",
  why_read = "Court, intense et d'une rare puissance. Mourlevat raconte une histoire d'enfance et de fuite qui prend aux tripes, à travers une mosaïque de voix qui se répondent. Le genre de livre qu'on lit d'une traite et qu'on n'oublie jamais — accessible à tout âge, mais qui frappe fort.",
  publication_year = 1999,
  language = 'fr',
  difficulty = 'accessible'
WHERE isbn13 = '9782070572618';

-- ------------------------------------------------------------
-- Liaison des tags (INSERT IGNORE — idempotent)
-- ------------------------------------------------------------
INSERT IGNORE INTO `explore_book_tags` (`book_id`, `tag_id`)
SELECT eb.id, t.id FROM `explore_books` eb
JOIN `catalog_tags` t ON t.slug IN ('dechirant', 'intense', 'captivant', 'depaysant')
WHERE eb.isbn13 = '9782253125273';

INSERT IGNORE INTO `explore_book_tags` (`book_id`, `tag_id`)
SELECT eb.id, t.id FROM `explore_books` eb
JOIN `catalog_tags` t ON t.slug IN ('dechirant', 'reconfortant', 'classique', 'accessible')
WHERE eb.isbn13 = '9782070368624';

INSERT IGNORE INTO `explore_book_tags` (`book_id`, `tag_id`)
SELECT eb.id, t.id FROM `explore_books` eb
JOIN `catalog_tags` t ON t.slug IN ('dechirant', 'lent', 'sombre', 'intellectuel')
WHERE eb.isbn13 = '9782267023794';

INSERT IGNORE INTO `explore_book_tags` (`book_id`, `tag_id`)
SELECT eb.id, t.id FROM `explore_books` eb
JOIN `catalog_tags` t ON t.slug IN ('dechirant', 'captivant', 'depaysant', 'page-turner')
WHERE eb.isbn13 = '9782290219461';

INSERT IGNORE INTO `explore_book_tags` (`book_id`, `tag_id`)
SELECT eb.id, t.id FROM `explore_books` eb
JOIN `catalog_tags` t ON t.slug IN ('dechirant', 'inspirant', 'reconfortant', 'captivant')
WHERE eb.isbn13 = '9782253104483';

INSERT IGNORE INTO `explore_book_tags` (`book_id`, `tag_id`)
SELECT eb.id, t.id FROM `explore_books` eb
JOIN `catalog_tags` t ON t.slug IN ('reconfortant', 'feel-good', 'accessible', 'dechirant')
WHERE eb.isbn13 = '9782070449422';

INSERT IGNORE INTO `explore_book_tags` (`book_id`, `tag_id`)
SELECT eb.id, t.id FROM `explore_books` eb
JOIN `catalog_tags` t ON t.slug IN ('dechirant', 'captivant', 'classique', 'depaysant')
WHERE eb.isbn13 = '9782246781745';

INSERT IGNORE INTO `explore_book_tags` (`book_id`, `tag_id`)
SELECT eb.id, t.id FROM `explore_books` eb
JOIN `catalog_tags` t ON t.slug IN ('dechirant', 'classique', 'sombre', 'accessible')
WHERE eb.isbn13 = '9782070411894';

INSERT IGNORE INTO `explore_book_tags` (`book_id`, `tag_id`)
SELECT eb.id, t.id FROM `explore_books` eb
JOIN `catalog_tags` t ON t.slug IN ('dechirant', 'intense', 'accessible', 'captivant')
WHERE eb.isbn13 = '9782070572618';

COMMIT;
