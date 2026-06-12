-- ============================================================
-- 015_explore_enrich_sample.sql
--   Échantillon : enrichissement complet de 5 livres existants
--   (UPDATE par ISBN — réutilise les couvertures déjà en place)
-- ============================================================

START TRANSACTION;

-- ------------------------------------------------------------
-- 1. L'Étranger — Albert Camus
-- ------------------------------------------------------------
UPDATE `explore_books` SET
  summary = "Sous le soleil écrasant d'Alger, Meursault apprend la mort de sa mère sans verser une larme. Quelques jours plus tard, sur une plage aveuglante, il tue un homme presque par accident. Commence alors un procès où c'est moins son crime que son indifférence au monde qu'on juge. Camus dresse le portrait d'un homme qui refuse de mentir sur ce qu'il ressent — quitte à en mourir.",
  why_read = "En à peine 180 pages, Camus a signé l'un des romans les plus marquants du XXe siècle. Une prose limpide, glaçante, qui te happe dès la première phrase et ne te lâche plus. À lire au moins une fois pour comprendre ce qu'est l'absurde — et se demander : suis-je vraiment honnête avec ce que je ressens ?",
  publication_year = 1942,
  language = 'fr',
  difficulty = 'accessible'
WHERE isbn13 = '9782070360024';

-- ------------------------------------------------------------
-- 2. Stoner — John Williams
-- ------------------------------------------------------------
UPDATE `explore_books` SET
  summary = "William Stoner naît dans une ferme pauvre du Missouri et découvre la littérature presque par hasard à l'université. Il y consacrera sa vie entière comme professeur, sans gloire ni reconnaissance. Mariage raté, carrière contrariée, amour tardif et impossible : rien ne lui sera épargné. Et pourtant, dans cette existence en apparence ordinaire, Williams révèle une dignité bouleversante.",
  why_read = "Oublié pendant un demi-siècle, redécouvert par le seul bouche-à-oreille, Stoner est devenu un phénomène mondial. Pourquoi ? Parce que c'est l'un des plus beaux romans jamais écrits sur une vie 'normale'. Rien de spectaculaire ici — et c'est exactement ce qui te bouleversera. Un livre qui te fait aimer la vie dans ce qu'elle a de plus discret.",
  publication_year = 1965,
  language = 'fr',
  difficulty = 'accessible'
WHERE isbn13 = '9782311100761';

-- ------------------------------------------------------------
-- 3. La Route — Cormac McCarthy
-- ------------------------------------------------------------
UPDATE `explore_books` SET
  summary = "Dans une Amérique réduite en cendres par une catastrophe jamais nommée, un père et son jeune fils marchent vers le sud pour survivre à l'hiver. Ils n'ont qu'un caddie, une couverture et un revolver avec deux balles. Autour d'eux, un monde de cendre, de froid et de prédateurs humains. Entre eux, un amour si pur qu'il devient la dernière lumière du monde.",
  why_read = "Prix Pulitzer 2006. McCarthy signe le roman post-apocalyptique le plus dépouillé et le plus puissant jamais écrit. Sa prose, sans guillemets ni fioritures, te plonge dans un cauchemar — mais ce que tu retiendras, c'est l'amour absolu d'un père pour son fils. Un livre qui serre la gorge et qu'on n'oublie jamais.",
  publication_year = 2006,
  language = 'fr',
  difficulty = 'intermediaire'
WHERE isbn13 = '9780307387899';

-- ------------------------------------------------------------
-- 4. Des Fleurs pour Algernon — Daniel Keyes
-- ------------------------------------------------------------
UPDATE `explore_books` SET
  summary = "Charlie Gordon, 32 ans, a un QI de 68 et un rêve : devenir intelligent. Une opération expérimentale, déjà testée sur une souris nommée Algernon, va exaucer son vœu au-delà de toute espérance. À travers ses propres journaux intimes, on suit sa fulgurante ascension vers le génie — puis ce qui s'ensuit, quand Algernon commence à décliner.",
  why_read = "Rares sont les romans qui te font ressentir l'intelligence se construire puis s'effondrer de l'intérieur. Le génie de Keyes : tout est raconté par Charlie lui-même, et son écriture évolue avec son esprit. Émotion, intelligence et accessibilité réunies en un seul livre. Prépare-toi à pleurer sur les dernières pages.",
  publication_year = 1966,
  language = 'fr',
  difficulty = 'accessible'
WHERE isbn13 = '9780156030083';

-- ------------------------------------------------------------
-- 5. Sapiens — Yuval Noah Harari
-- ------------------------------------------------------------
UPDATE `explore_books` SET
  summary = "Il y a 70 000 ans, Homo sapiens n'était qu'un animal insignifiant parmi d'autres. Aujourd'hui, il domine la planète. Comment ? Harari raconte cette épopée à travers trois révolutions — cognitive, agricole et scientifique — et montre comment des 'fictions partagées' (l'argent, les nations, les religions, les entreprises) ont permis à notre espèce de coopérer à une échelle inédite.",
  why_read = "Le livre de non-fiction le plus influent de la dernière décennie, traduit en 65 langues. Harari a ce talent rare de te faire voir l'humanité entière sous un angle neuf, en quelques chapitres limpides. Tu ne regarderas plus jamais l'argent, le travail ou la religion de la même façon. Un livre qui élargit littéralement ton horizon.",
  publication_year = 2011,
  language = 'fr',
  difficulty = 'intermediaire'
WHERE isbn13 = '9782226257017';

-- ------------------------------------------------------------
-- 6. Liaison des tags (INSERT IGNORE — idempotent)
-- ------------------------------------------------------------
INSERT IGNORE INTO `explore_book_tags` (`book_id`, `tag_id`)
SELECT eb.id, t.id
FROM `explore_books` eb
JOIN `catalog_tags` t ON t.slug IN ('classique', 'sombre', 'philosophique', 'accessible')
WHERE eb.isbn13 = '9782070360024';

INSERT IGNORE INTO `explore_book_tags` (`book_id`, `tag_id`)
SELECT eb.id, t.id
FROM `explore_books` eb
JOIN `catalog_tags` t ON t.slug IN ('dechirant', 'lent', 'classique', 'inspirant')
WHERE eb.isbn13 = '9782311100761';

INSERT IGNORE INTO `explore_book_tags` (`book_id`, `tag_id`)
SELECT eb.id, t.id
FROM `explore_books` eb
JOIN `catalog_tags` t ON t.slug IN ('sombre', 'dechirant', 'intense', 'page-turner')
WHERE eb.isbn13 = '9780307387899';

INSERT IGNORE INTO `explore_book_tags` (`book_id`, `tag_id`)
SELECT eb.id, t.id
FROM `explore_books` eb
JOIN `catalog_tags` t ON t.slug IN ('dechirant', 'captivant', 'accessible', 'philosophique')
WHERE eb.isbn13 = '9780156030083';

INSERT IGNORE INTO `explore_book_tags` (`book_id`, `tag_id`)
SELECT eb.id, t.id
FROM `explore_books` eb
JOIN `catalog_tags` t ON t.slug IN ('intellectuel', 'captivant', 'accessible', 'inspirant')
WHERE eb.isbn13 = '9782226257017';

COMMIT;
