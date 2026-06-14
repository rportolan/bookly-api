-- ============================================================
-- 020_explore_enrich_batch6.sql
--   Enrichissement de 4 livres (catégorie « Pour voir les choses autrement »)
-- ============================================================

START TRANSACTION;

-- ------------------------------------------------------------
-- 1. Le Mythe de Sisyphe — Albert Camus
-- ------------------------------------------------------------
UPDATE `explore_books` SET
  summary = "Si la vie n'a pas de sens donné, faut-il pour autant en finir ? Camus part de cette question vertigineuse — « le seul problème philosophique vraiment sérieux » — pour explorer l'absurde, ce divorce entre notre soif de sens et le silence du monde. Sa réponse, incarnée par Sisyphe poussant éternellement son rocher : il faut l'imaginer heureux.",
  why_read = "L'essai philosophique le plus accessible et le plus libérateur de Camus. Pas de jargon : une écriture limpide, presque solaire, pour affronter la question du sens sans se mentir. À lire dans un moment de doute existentiel — beaucoup en ressortent étrangement apaisés et prêts à vivre pleinement.",
  publication_year = 1942,
  language = 'fr',
  difficulty = 'intermediaire'
WHERE isbn13 = '9782070323227';

-- ------------------------------------------------------------
-- 2. L'Art subtil de s'en foutre — Mark Manson
-- ------------------------------------------------------------
UPDATE `explore_books` SET
  summary = "À rebours du développement personnel positif à tout prix, Manson défend une idée simple : on ne peut pas se soucier de tout, alors mieux vaut choisir avec soin ce qui compte vraiment. Accepter ses limites, affronter ses problèmes plutôt que de les fuir, et arrêter de courir après un bonheur permanent et illusoire.",
  why_read = "Un guide à contre-courant, cynique, drôle et étonnamment sage. Manson balance des vérités qui dérangent avec un humour qui fait mouche. Si tu en as marre des livres de motivation dégoulinants, celui-ci est l'antidote parfait — un électrochoc rafraîchissant qui remet les priorités en place.",
  publication_year = 2016,
  language = 'fr',
  difficulty = 'accessible'
WHERE isbn13 = '9782501124690';

-- ------------------------------------------------------------
-- 3. Méditations — Marc Aurèle
-- ------------------------------------------------------------
UPDATE `explore_books` SET
  summary = "Empereur romain au sommet du pouvoir, Marc Aurèle tenait un journal intime, pour lui seul, où il consignait ses réflexions stoïciennes. Maîtrise de soi, acceptation de ce qu'on ne contrôle pas, devoir, impermanence : ces notes jamais destinées à être publiées forment l'un des plus grands manuels de sagesse de l'histoire.",
  why_read = "La philosophie de l'action la plus honnête jamais écrite — parce que son auteur ne cherchait à impressionner personne. Près de deux mille ans plus tard, ces pensées restent d'une justesse troublante face au stress, à l'ego et à l'adversité. À garder à portée de main pour y puiser du calme.",
  publication_year = 180,
  language = 'fr',
  difficulty = 'accessible'
WHERE isbn13 = '9782070414093';

-- ------------------------------------------------------------
-- 4. Antifragile — Nassim Nicholas Taleb
-- ------------------------------------------------------------
UPDATE `explore_books` SET
  summary = "Certaines choses ne se contentent pas de résister au chaos : elles s'en nourrissent et en sortent renforcées. Taleb nomme cette propriété l'« antifragilité » et montre comment elle s'applique à tout — l'économie, le corps, les systèmes, nos vies. Une grille de lecture inédite pour prospérer dans un monde incertain et imprévisible.",
  why_read = "Un livre qui change durablement ta façon de penser le risque, l'erreur et l'incertitude. Taleb est provocateur, érudit et brillamment iconoclaste. Exigeant par moments, mais les idées qu'on en retire valent largement l'effort : on ne regarde plus jamais la fragilité et la solidité de la même manière.",
  publication_year = 2012,
  language = 'fr',
  difficulty = 'dense'
WHERE isbn13 = '9782251444840';

-- ------------------------------------------------------------
-- Liaison des tags (INSERT IGNORE — idempotent)
-- ------------------------------------------------------------
INSERT IGNORE INTO `explore_book_tags` (`book_id`, `tag_id`)
SELECT eb.id, t.id FROM `explore_books` eb
JOIN `catalog_tags` t ON t.slug IN ('philosophique', 'intellectuel', 'inspirant', 'accessible')
WHERE eb.isbn13 = '9782070323227';

INSERT IGNORE INTO `explore_book_tags` (`book_id`, `tag_id`)
SELECT eb.id, t.id FROM `explore_books` eb
JOIN `catalog_tags` t ON t.slug IN ('drole', 'inspirant', 'accessible', 'philosophique')
WHERE eb.isbn13 = '9782501124690';

INSERT IGNORE INTO `explore_book_tags` (`book_id`, `tag_id`)
SELECT eb.id, t.id FROM `explore_books` eb
JOIN `catalog_tags` t ON t.slug IN ('philosophique', 'classique', 'inspirant', 'reconfortant')
WHERE eb.isbn13 = '9782070414093';

INSERT IGNORE INTO `explore_book_tags` (`book_id`, `tag_id`)
SELECT eb.id, t.id FROM `explore_books` eb
JOIN `catalog_tags` t ON t.slug IN ('intellectuel', 'philosophique', 'inspirant', 'intense')
WHERE eb.isbn13 = '9782251444840';

COMMIT;
