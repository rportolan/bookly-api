-- ============================================================
-- 018_explore_enrich_batch4.sql
--   Enrichissement de 9 livres (catégorie « Envie d'apprendre ? »)
--   NB : « Sapiens » déjà enrichi en 015 → ignoré ici.
-- ============================================================

START TRANSACTION;

-- ------------------------------------------------------------
-- 1. Homo Deus — Yuval Noah Harari
-- ------------------------------------------------------------
UPDATE `explore_books` SET
  summary = "Après avoir survécu à la famine, aux épidémies et à la guerre, à quoi l'humanité va-t-elle désormais s'attaquer ? Harari explore les grands projets du XXIe siècle — vaincre la mort, accéder au bonheur permanent, devenir des dieux — et les bouleversements que l'intelligence artificielle et le biotech feront peser sur le libre arbitre et la valeur de l'humain.",
  why_read = "La suite vertigineuse de Sapiens. Là où le premier racontait notre passé, celui-ci interroge notre futur — et c'est glaçant de lucidité. Harari a ce talent de rendre limpides les enjeux les plus complexes. Tu refermes le livre avec mille questions et un regard neuf sur le monde qui vient.",
  publication_year = 2015,
  language = 'fr',
  difficulty = 'intermediaire'
WHERE isbn13 = '9782226393876';

-- ------------------------------------------------------------
-- 2. Atomic Habits — James Clear
-- ------------------------------------------------------------
UPDATE `explore_books` SET
  summary = "Et si le secret du changement n'était pas la motivation, mais le système ? Clear démontre que des améliorations minuscules — 1 % chaque jour — produisent des résultats spectaculaires sur la durée. À travers quatre lois simples, il explique comment construire de bonnes habitudes et se débarrasser des mauvaises, exemples concrets à l'appui.",
  why_read = "Le livre de développement personnel le plus actionnable jamais écrit. Pas de blabla : des principes clairs, immédiatement applicables, qui marchent vraiment. Si tu n'en lis qu'un sur le sujet, c'est celui-là. Tu commenceras à changer tes habitudes avant même de l'avoir terminé.",
  publication_year = 2018,
  language = 'fr',
  difficulty = 'accessible'
WHERE isbn13 = '9780735211292';

-- ------------------------------------------------------------
-- 3. Thinking, Fast and Slow — Daniel Kahneman
-- ------------------------------------------------------------
UPDATE `explore_books` SET
  summary = "Notre esprit fonctionne avec deux systèmes : l'un rapide, intuitif et émotionnel ; l'autre lent, réfléchi et logique. Prix Nobel d'économie, Kahneman révèle comment ces deux modes façonnent nos jugements et nos décisions — et pourquoi nous nous trompons si souvent sans même nous en rendre compte.",
  why_read = "Une plongée fascinante dans les biais cognitifs par l'un des plus grands psychologues vivants. Dense mais accessible, ce livre change durablement la façon dont tu observes tes propres pensées. À lire absolument pour mieux décider — et pour comprendre pourquoi ton intuition te ment parfois.",
  publication_year = 2011,
  language = 'fr',
  difficulty = 'dense'
WHERE isbn13 = '9780374533557';

-- ------------------------------------------------------------
-- 4. Factfulness — Hans Rosling
-- ------------------------------------------------------------
UPDATE `explore_books` SET
  summary = "Le monde va-t-il vraiment aussi mal qu'on le croit ? Le statisticien Hans Rosling démonte dix instincts qui faussent notre perception de la réalité — l'instinct de peur, de négativité, de généralisation — et montre, données à l'appui, que l'humanité progresse bien plus qu'on ne l'imagine.",
  why_read = "Un antidote salutaire au pessimisme ambiant, fondé non pas sur l'espoir mais sur les faits. Rosling rend les statistiques passionnantes et te fait voir le monde avec plus de justesse. Bill Gates l'a offert à tous les diplômés américains une année — ça résume son impact.",
  publication_year = 2018,
  language = 'fr',
  difficulty = 'accessible'
WHERE isbn13 = '9781250123824';

-- ------------------------------------------------------------
-- 5. Why We Sleep — Matthew Walker
-- ------------------------------------------------------------
UPDATE `explore_books` SET
  summary = "Le neuroscientifique Matthew Walker livre la somme de décennies de recherche sur le sommeil — cette fonction vitale que nous négligeons tous. Mémoire, immunité, espérance de vie, santé mentale : il révèle à quel point dormir conditionne tout, et ce que nous risquons réellement à manquer de sommeil.",
  why_read = "Le livre qui te fera reconsidérer chaque nuit blanche. Révélateur et franchement inquiétant, mais écrit avec clarté et passion. Après l'avoir lu, tu ne traiteras plus jamais ton sommeil comme une variable d'ajustement. Probablement l'un des livres les plus utiles pour ta santé.",
  publication_year = 2017,
  language = 'fr',
  difficulty = 'accessible'
WHERE isbn13 = '9781501144325';

-- ------------------------------------------------------------
-- 6. Behave — Robert Sapolsky
-- ------------------------------------------------------------
UPDATE `explore_books` SET
  summary = "Pourquoi faisons-nous ce que nous faisons ? Sapolsky remonte le fil d'un comportement humain — depuis la seconde qui le précède jusqu'aux millions d'années d'évolution qui l'ont rendu possible. Neurones, hormones, enfance, culture, gènes : une exploration totale des ressorts de la nature humaine, du meilleur au pire.",
  why_read = "La synthèse la plus complète et la plus brillante jamais écrite sur le comportement humain. C'est ambitieux et exigeant, mais Sapolsky est aussi drôle qu'érudit. Si tu veux vraiment comprendre ce qui nous pousse à agir — la violence comme la compassion — ce livre est une mine d'or.",
  publication_year = 2017,
  language = 'fr',
  difficulty = 'dense'
WHERE isbn13 = '9781594205071';

-- ------------------------------------------------------------
-- 7. Deep Work — Cal Newport
-- ------------------------------------------------------------
UPDATE `explore_books` SET
  summary = "Dans un monde saturé de notifications, la capacité à se concentrer profondément devient une compétence rare et précieuse. Newport défend le « travail en profondeur » — ces sessions de concentration intense sans distraction — et donne des stratégies concrètes pour le cultiver et produire un travail de qualité supérieure.",
  why_read = "Un manifeste contre la dispersion permanente, et un mode d'emploi pour retrouver sa capacité d'attention. Concret, structuré, sans bullshit. Si tu as l'impression de t'éparpiller et de ne jamais avancer sur l'essentiel, ce livre peut littéralement transformer ta façon de travailler.",
  publication_year = 2016,
  language = 'fr',
  difficulty = 'accessible'
WHERE isbn13 = '9781455586691';

-- ------------------------------------------------------------
-- 8. The Psychology of Money — Morgan Housel
-- ------------------------------------------------------------
UPDATE `explore_books` SET
  summary = "L'argent n'est pas qu'une affaire de chiffres — c'est avant tout une affaire de comportement. À travers dix-neuf histoires courtes et marquantes, Housel explore notre rapport intime à la richesse : pourquoi des gens brillants ruinent leurs finances, et pourquoi de simples concierges deviennent millionnaires.",
  why_read = "Le livre sur l'argent le plus humain et le plus sage qui soit — et il se lit comme un recueil de nouvelles. Pas de jargon financier, juste des leçons de vie universelles sur l'épargne, la patience et le bonheur. À mettre entre toutes les mains, quel que soit ton rapport à l'argent.",
  publication_year = 2020,
  language = 'fr',
  difficulty = 'accessible'
WHERE isbn13 = '9780857197689';

-- ------------------------------------------------------------
-- 9. Une brève histoire du temps — Stephen Hawking
-- ------------------------------------------------------------
UPDATE `explore_books` SET
  summary = "Du Big Bang aux trous noirs, de la relativité à la mécanique quantique, Stephen Hawking raconte l'histoire de l'univers et les grandes questions qui hantent les physiciens : d'où venons-nous, le temps a-t-il un début, aura-t-il une fin ? Le tout sans une seule équation, ou presque.",
  why_read = "Le livre qui a rendu la cosmologie accessible au grand public, vendu à des millions d'exemplaires. Hawking a ce don de rendre les concepts les plus vertigineux compréhensibles — et de te faire ressentir l'immensité de l'univers. Une porte d'entrée idéale vers les mystères du cosmos.",
  publication_year = 1988,
  language = 'fr',
  difficulty = 'intermediaire'
WHERE isbn13 = '9782081377929';

-- ------------------------------------------------------------
-- Liaison des tags (INSERT IGNORE — idempotent)
-- ------------------------------------------------------------
INSERT IGNORE INTO `explore_book_tags` (`book_id`, `tag_id`)
SELECT eb.id, t.id FROM `explore_books` eb
JOIN `catalog_tags` t ON t.slug IN ('intellectuel', 'captivant', 'inspirant', 'accessible')
WHERE eb.isbn13 = '9782226393876';

INSERT IGNORE INTO `explore_book_tags` (`book_id`, `tag_id`)
SELECT eb.id, t.id FROM `explore_books` eb
JOIN `catalog_tags` t ON t.slug IN ('inspirant', 'accessible', 'captivant', 'intellectuel')
WHERE eb.isbn13 = '9780735211292';

INSERT IGNORE INTO `explore_book_tags` (`book_id`, `tag_id`)
SELECT eb.id, t.id FROM `explore_books` eb
JOIN `catalog_tags` t ON t.slug IN ('intellectuel', 'captivant', 'inspirant', 'lent')
WHERE eb.isbn13 = '9780374533557';

INSERT IGNORE INTO `explore_book_tags` (`book_id`, `tag_id`)
SELECT eb.id, t.id FROM `explore_books` eb
JOIN `catalog_tags` t ON t.slug IN ('intellectuel', 'inspirant', 'accessible', 'captivant')
WHERE eb.isbn13 = '9781250123824';

INSERT IGNORE INTO `explore_book_tags` (`book_id`, `tag_id`)
SELECT eb.id, t.id FROM `explore_books` eb
JOIN `catalog_tags` t ON t.slug IN ('intellectuel', 'inspirant', 'accessible', 'captivant')
WHERE eb.isbn13 = '9781501144325';

INSERT IGNORE INTO `explore_book_tags` (`book_id`, `tag_id`)
SELECT eb.id, t.id FROM `explore_books` eb
JOIN `catalog_tags` t ON t.slug IN ('intellectuel', 'captivant', 'philosophique', 'intense')
WHERE eb.isbn13 = '9781594205071';

INSERT IGNORE INTO `explore_book_tags` (`book_id`, `tag_id`)
SELECT eb.id, t.id FROM `explore_books` eb
JOIN `catalog_tags` t ON t.slug IN ('inspirant', 'accessible', 'intellectuel', 'captivant')
WHERE eb.isbn13 = '9781455586691';

INSERT IGNORE INTO `explore_book_tags` (`book_id`, `tag_id`)
SELECT eb.id, t.id FROM `explore_books` eb
JOIN `catalog_tags` t ON t.slug IN ('accessible', 'inspirant', 'intellectuel', 'captivant')
WHERE eb.isbn13 = '9780857197689';

INSERT IGNORE INTO `explore_book_tags` (`book_id`, `tag_id`)
SELECT eb.id, t.id FROM `explore_books` eb
JOIN `catalog_tags` t ON t.slug IN ('intellectuel', 'captivant', 'inspirant', 'philosophique')
WHERE eb.isbn13 = '9782081377929';

COMMIT;
