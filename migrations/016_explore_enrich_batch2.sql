-- ============================================================
-- 016_explore_enrich_batch2.sql
--   Enrichissement complet de 6 livres existants (UPDATE par ISBN)
-- ============================================================

START TRANSACTION;

-- ------------------------------------------------------------
-- 1. Siddhartha — Hermann Hesse
-- ------------------------------------------------------------
UPDATE `explore_books` SET
  summary = "Dans l'Inde ancienne, Siddhartha, fils de brahmane promis à un brillant avenir, quitte tout pour chercher l'éveil. Ascète, marchand, amant, passeur : il traverse toutes les expériences de la vie, des plus spirituelles aux plus charnelles. Au bord d'un fleuve, il finit par comprendre que la sagesse ne se transmet pas — elle se vit. Un conte initiatique d'une limpidité désarmante.",
  why_read = "En 150 pages d'une beauté apaisante, Hesse signe l'un des plus grands romans sur la quête de soi. Ce n'est pas un livre qu'on lit, c'est un livre qu'on respire. Parfait pour ceux qui se posent les grandes questions sans vouloir d'un traité de philosophie — ici tout passe par l'histoire d'un homme.",
  publication_year = 1922,
  language = 'fr',
  difficulty = 'accessible'
WHERE isbn13 = '9780811200684';

-- ------------------------------------------------------------
-- 2. Le Comte de Monte-Cristo — Alexandre Dumas
-- ------------------------------------------------------------
UPDATE `explore_books` SET
  summary = "À la veille de son mariage, le jeune marin Edmond Dantès est trahi par ses amis jaloux et jeté au cachot pour un crime qu'il n'a pas commis. Après quatorze ans d'enfer, il s'évade, découvre un trésor fabuleux et revient sous une fausse identité pour orchestrer la plus implacable des vengeances. Justice, patience, rédemption : un chef-d'œuvre d'intrigue.",
  why_read = "1 200 pages et pourtant impossible à lâcher. C'est LE roman d'aventure et de vengeance, celui qui a défini le genre. Dumas est un conteur hors pair : chaque chapitre se termine sur une tension qui te force à continuer. Le genre de livre qui te fait rater ton arrêt de métro.",
  publication_year = 1844,
  language = 'fr',
  difficulty = 'intermediaire'
WHERE isbn13 = '9782070409136';

-- ------------------------------------------------------------
-- 3. Le Maître et Marguerite — Mikhaïl Boulgakov
-- ------------------------------------------------------------
UPDATE `explore_books` SET
  summary = "Le diable en personne débarque dans le Moscou soviétique des années 30, accompagné d'un chat géant qui parle et d'une troupe diabolique. Entre satire mordante de la bureaucratie athée, histoire d'amour impossible et récit parallèle de Ponce Pilate à Jérusalem, Boulgakov tisse un roman inclassable où le fantastique éclaire le réel.",
  why_read = "Censuré pendant près de 30 ans, ce roman est aujourd'hui un culte absolu. Drôle, vertigineux, profond : on rit autant qu'on réfléchit. Si tu veux lire quelque chose qui ne ressemble à rien d'autre — un cocktail de magie, de politique et de génie littéraire — c'est exactement ça.",
  publication_year = 1967,
  language = 'fr',
  difficulty = 'intermediaire'
WHERE isbn13 = '9782264041524';

-- ------------------------------------------------------------
-- 4. Les Frères Karamazov — Fiodor Dostoïevski
-- ------------------------------------------------------------
UPDATE `explore_books` SET
  summary = "Trois frères que tout oppose — le sensuel, l'intellectuel athée, le mystique — et un père méprisable dont le meurtre va tous les éclabousser. À travers ce drame familial et judiciaire, Dostoïevski affronte les questions ultimes : Dieu existe-t-il ? L'homme est-il libre ? Le mal a-t-il un sens ? Son testament littéraire, et son sommet.",
  why_read = "C'est peut-être le roman le plus profond jamais écrit. Dostoïevski ne donne pas de réponses faciles — il met en scène toutes les voix qui s'affrontent en nous. Exigeant, oui, mais transformateur : on n'en sort pas tout à fait le même. Le célèbre chapitre du Grand Inquisiteur vaut à lui seul le voyage.",
  publication_year = 1880,
  language = 'fr',
  difficulty = 'dense'
WHERE isbn13 = '9780374528379';

-- ------------------------------------------------------------
-- 5. Le Monde de Sophie — Jostein Gaarder
-- ------------------------------------------------------------
UPDATE `explore_books` SET
  summary = "Sophie, 14 ans, trouve dans sa boîte aux lettres d'étranges questions : « Qui es-tu ? », « D'où vient le monde ? ». Un mystérieux professeur l'initie alors, lettre après lettre, à toute l'histoire de la philosophie occidentale — de Socrate à Sartre. Mais un second mystère, vertigineux, se cache derrière ces leçons…",
  why_read = "Le livre qui a rendu la philosophie accessible à des millions de lecteurs. C'est à la fois un roman captivant et un cours complet, sans jamais être pédant. Si tu as toujours voulu comprendre les grands penseurs sans savoir par où commencer, commence ici. Idéal aussi pour (re)donner le goût de réfléchir.",
  publication_year = 1991,
  language = 'fr',
  difficulty = 'accessible'
WHERE isbn13 = '9782070531899';

-- ------------------------------------------------------------
-- 6. Le Prophète — Khalil Gibran
-- ------------------------------------------------------------
UPDATE `explore_books` SET
  summary = "Avant de quitter la ville où il a vécu douze ans, le sage Almustafa répond une dernière fois aux habitants venus l'interroger. L'amour, le travail, la joie et la peine, les enfants, la liberté, la mort : sur chacun de ces thèmes, il offre une parole poétique et lumineuse. Un recueil de méditations devenu un classique universel.",
  why_read = "Un livre qui se lit en une heure et se médite toute une vie. La prose de Gibran, à mi-chemin entre poésie et sagesse, touche au plus juste sans jamais moraliser. À garder sur sa table de chevet pour y revenir : chaque relecture, selon le moment de ta vie, te dira quelque chose de différent.",
  publication_year = 1923,
  language = 'fr',
  difficulty = 'accessible'
WHERE isbn13 = '9780394404288';

-- ------------------------------------------------------------
-- 7. Liaison des tags (INSERT IGNORE — idempotent)
-- ------------------------------------------------------------
INSERT IGNORE INTO `explore_book_tags` (`book_id`, `tag_id`)
SELECT eb.id, t.id
FROM `explore_books` eb
JOIN `catalog_tags` t ON t.slug IN ('philosophique', 'lent', 'inspirant', 'accessible')
WHERE eb.isbn13 = '9780811200684';

INSERT IGNORE INTO `explore_book_tags` (`book_id`, `tag_id`)
SELECT eb.id, t.id
FROM `explore_books` eb
JOIN `catalog_tags` t ON t.slug IN ('captivant', 'page-turner', 'classique', 'depaysant')
WHERE eb.isbn13 = '9782070409136';

INSERT IGNORE INTO `explore_book_tags` (`book_id`, `tag_id`)
SELECT eb.id, t.id
FROM `explore_books` eb
JOIN `catalog_tags` t ON t.slug IN ('depaysant', 'drole', 'intellectuel', 'classique')
WHERE eb.isbn13 = '9782264041524';

INSERT IGNORE INTO `explore_book_tags` (`book_id`, `tag_id`)
SELECT eb.id, t.id
FROM `explore_books` eb
JOIN `catalog_tags` t ON t.slug IN ('philosophique', 'intense', 'classique', 'intellectuel')
WHERE eb.isbn13 = '9780374528379';

INSERT IGNORE INTO `explore_book_tags` (`book_id`, `tag_id`)
SELECT eb.id, t.id
FROM `explore_books` eb
JOIN `catalog_tags` t ON t.slug IN ('philosophique', 'accessible', 'intellectuel', 'captivant')
WHERE eb.isbn13 = '9782070531899';

INSERT IGNORE INTO `explore_book_tags` (`book_id`, `tag_id`)
SELECT eb.id, t.id
FROM `explore_books` eb
JOIN `catalog_tags` t ON t.slug IN ('inspirant', 'lent', 'philosophique', 'reconfortant')
WHERE eb.isbn13 = '9780394404288';

COMMIT;
