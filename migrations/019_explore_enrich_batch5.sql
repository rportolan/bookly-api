-- ============================================================
-- 019_explore_enrich_batch5.sql
--   Enrichissement de 10 livres (catégorie « Envie d'aventure ? »)
-- ============================================================

START TRANSACTION;

-- ------------------------------------------------------------
-- 1. Shantaram — Gregory David Roberts
-- ------------------------------------------------------------
UPDATE `explore_books` SET
  summary = "Évadé d'une prison australienne, Lin se réfugie à Bombay sous une fausse identité. Il y découvre les bidonvilles, la mafia, la médecine de fortune, l'amour et la guerre — plongeant toujours plus profond dans les bas-fonds et la grandeur d'une ville tentaculaire. Une fresque inspirée de la vie tumultueuse de son auteur.",
  why_read = "900 pages d'aventure pure que l'on dévore. Roberts a réellement vécu une partie de ce qu'il raconte, et ça se sent : tout vibre d'authenticité. Dépaysement total garanti, des personnages inoubliables et une plume qui transforme Bombay en personnage à part entière. Un voyage dont on ne revient pas indemne.",
  publication_year = 2003,
  language = 'fr',
  difficulty = 'intermediaire'
WHERE isbn13 = '9780312330538';

-- ------------------------------------------------------------
-- 2. Into the Wild — Jon Krakauer
-- ------------------------------------------------------------
UPDATE `explore_books` SET
  summary = "À 24 ans, Chris McCandless abandonne tout — argent, famille, confort — pour partir vivre seul dans la nature sauvage de l'Alaska. À partir de ses carnets et de témoignages, Krakauer reconstitue ce voyage radical vers la liberté absolue, et tente de comprendre ce qui pousse un jeune homme brillant à tout quitter.",
  why_read = "Une histoire vraie aussi fascinante que tragique, qui interroge notre besoin de liberté et nos limites face à la nature. Krakauer enquête sans juger, et le récit te hante longtemps. Parfait pour ceux que la wilderness fait rêver — et qui se demandent jusqu'où ils iraient pour se sentir vivants.",
  publication_year = 1996,
  language = 'fr',
  difficulty = 'accessible'
WHERE isbn13 = '9780385486804';

-- ------------------------------------------------------------
-- 3. L'Île au trésor — Robert Louis Stevenson
-- ------------------------------------------------------------
UPDATE `explore_books` SET
  summary = "Le jeune Jim Hawkins découvre une carte au trésor dans les affaires d'un vieux marin. Embarqué à bord de l'Hispaniola, il se retrouve mêlé à une chasse au trésor où se révèle le terrible et fascinant Long John Silver, pirate manchot au cœur insondable. Mutinerie, île déserte et coffres d'or au programme.",
  why_read = "Le roman d'aventure originel, celui qui a inventé tous les codes du genre : la carte au trésor, le pirate à la jambe de bois, le perroquet… Plus d'un siècle après, ça reste un pur plaisir de lecture, rythmé et palpitant. Idéal pour retrouver l'émerveillement de l'aventure à l'état pur.",
  publication_year = 1883,
  language = 'fr',
  difficulty = 'accessible'
WHERE isbn13 = '9782070412228';

-- ------------------------------------------------------------
-- 4. Le Seigneur des Anneaux — J.R.R. Tolkien
-- ------------------------------------------------------------
UPDATE `explore_books` SET
  summary = "Frodon, modeste hobbit, hérite d'un anneau aux pouvoirs terrifiants que convoite le Seigneur des Ténèbres. Pour sauver la Terre du Milieu, il doit entreprendre un voyage périlleux jusqu'au cœur du Mordor afin de le détruire. Accompagné d'une communauté d'alliés, il affronte un mal qui menace d'engloutir le monde.",
  why_read = "L'œuvre fondatrice de la fantasy moderne : tout en découle. Tolkien n'a pas écrit un roman, il a créé un monde entier — langues, peuples, mythologies, géographie. Un monument à lire au moins une fois pour mesurer d'où vient tout l'imaginaire fantastique d'aujourd'hui. Un voyage épique inégalé.",
  publication_year = 1954,
  language = 'fr',
  difficulty = 'intermediaire'
WHERE isbn13 = '9782267011920';

-- ------------------------------------------------------------
-- 5. L'Assassin Royal — Robin Hobb
-- ------------------------------------------------------------
UPDATE `explore_books` SET
  summary = "Fitz est le fils bâtard d'un prince, recueilli à la cour et secrètement formé à l'art de l'assassinat au service du roi. Doté d'un lien mystérieux avec les animaux, il grandit dans l'ombre des complots, des trahisons et d'une magie ancienne, déchiré entre son devoir et son cœur. Le début d'une saga culte.",
  why_read = "La saga de fantasy la plus humaine et la plus déchirante jamais écrite. Hobb prend le temps de te lier à Fitz au point que ses peines deviennent les tiennes. Moins de batailles spectaculaires, plus d'émotion et de profondeur psychologique. Une fois entré dans ce monde, impossible d'en sortir.",
  publication_year = 1995,
  language = 'fr',
  difficulty = 'accessible'
WHERE isbn13 = '9782290300008';

-- ------------------------------------------------------------
-- 6. La Horde du Contrevent — Alain Damasio
-- ------------------------------------------------------------
UPDATE `explore_books` SET
  summary = "Depuis des générations, une horde de trente-quatre membres marche contre le vent, vers l'amont du monde, pour percer le secret de son origine. Chacun a un rôle, chacun donnera sa vie pour cette quête démesurée. Damasio invente une langue et une géographie inouïes pour ce roman-fleuve où le souffle est partout.",
  why_read = "Un chef-d'œuvre de la science-fiction française, inclassable et foudroyant. Exigeant, oui — la forme même du texte est une aventure — mais d'une puissance rare. Si tu cherches une lecture qui te bouscule, t'émerveille et ne ressemble à aucune autre, La Horde est une expérience inoubliable.",
  publication_year = 2004,
  language = 'fr',
  difficulty = 'dense'
WHERE isbn13 = '9782070461028';

-- ------------------------------------------------------------
-- 7. Les Royaumes du Nord — Philip Pullman
-- ------------------------------------------------------------
UPDATE `explore_books` SET
  summary = "Dans un monde parallèle où l'âme de chacun prend la forme d'un animal — son dæmon —, la jeune Lyra découvre que des enfants disparaissent mystérieusement. Munie d'un instrument capable de dire la vérité, elle s'aventure vers le Grand Nord glacé, ses ours en armure et ses secrets interdits. Premier tome d'À la Croisée des Mondes.",
  why_read = "De la fantasy intelligente qui ne prend jamais ses lecteurs pour des enfants. Pullman mêle aventure haletante, questionnements philosophiques et un univers d'une inventivité folle. Lyra est une héroïne inoubliable. Le genre de trilogie qu'on lit à tout âge et qu'on n'oublie jamais.",
  publication_year = 1995,
  language = 'fr',
  difficulty = 'accessible'
WHERE isbn13 = '9782070612352';

-- ------------------------------------------------------------
-- 8. L'Alchimiste — Paulo Coelho
-- ------------------------------------------------------------
UPDATE `explore_books` SET
  summary = "Santiago, jeune berger andalou, fait un rêve récurrent qui le pousse à traverser le désert jusqu'aux pyramides d'Égypte à la recherche d'un trésor. En chemin, il rencontre des maîtres, affronte des épreuves et apprend à écouter son cœur et les signes du monde pour accomplir ce que Coelho nomme sa « légende personnelle ».",
  why_read = "Un conte initiatique traduit en plus de 80 langues, devenu un véritable phénomène. Court, simple et lumineux, il parle de rêves, de courage et de fidélité à soi-même. À lire dans un moment de doute ou de transition : beaucoup en sont ressortis avec l'envie de changer de vie.",
  publication_year = 1988,
  language = 'fr',
  difficulty = 'accessible'
WHERE isbn13 = '9782290004449';

-- ------------------------------------------------------------
-- 9. Le Nom du Vent — Patrick Rothfuss
-- ------------------------------------------------------------
UPDATE `explore_books` SET
  summary = "Kvothe, devenu aubergiste sous un faux nom, accepte de raconter sa véritable histoire à un chroniqueur. Enfant de la route, orphelin, mendiant, puis étudiant prodige dans une université de magie : il révèle comment il est devenu l'homme le plus célèbre et le plus redouté de son monde. Le récit d'une légende par celui qui l'a vécue.",
  why_read = "La fantasy la plus envoûtante du XXIe siècle. Rothfuss écrit comme un musicien — la prose elle-même est un plaisir. Kvothe est un héros aussi brillant qu'agaçant, et son histoire te happe pour ne plus te lâcher. Si tu veux un grand roman d'apprentissage et de magie, commence ici.",
  publication_year = 2007,
  language = 'fr',
  difficulty = 'intermediaire'
WHERE isbn13 = '9782290006238';

-- ------------------------------------------------------------
-- 10. Voyage au centre de la Terre — Jules Verne
-- ------------------------------------------------------------
UPDATE `explore_books` SET
  summary = "Le professeur Lidenbrock déchiffre un vieux parchemin révélant un passage vers le centre de la Terre, au fond d'un volcan islandais. Accompagné de son neveu Axel et du guide Hans, il entreprend une descente vertigineuse dans les entrailles de la planète, où l'attendent océans souterrains, créatures préhistoriques et merveilles insoupçonnées.",
  why_read = "Jules Verne invente l'aventure scientifique avec une imagination sans limites et une érudition jubilatoire. Plus d'un siècle et demi plus tard, le sense of wonder est intact. Court, palpitant, idéal pour (re)découvrir le maître du roman d'aventure — et pour rêver à tout ce que la Terre cache sous nos pieds.",
  publication_year = 1864,
  language = 'fr',
  difficulty = 'accessible'
WHERE isbn13 = '9782070413003';

-- ------------------------------------------------------------
-- Liaison des tags (INSERT IGNORE — idempotent)
-- ------------------------------------------------------------
INSERT IGNORE INTO `explore_book_tags` (`book_id`, `tag_id`)
SELECT eb.id, t.id FROM `explore_books` eb
JOIN `catalog_tags` t ON t.slug IN ('depaysant', 'captivant', 'intense', 'inspirant')
WHERE eb.isbn13 = '9780312330538';

INSERT IGNORE INTO `explore_book_tags` (`book_id`, `tag_id`)
SELECT eb.id, t.id FROM `explore_books` eb
JOIN `catalog_tags` t ON t.slug IN ('depaysant', 'inspirant', 'intense', 'captivant')
WHERE eb.isbn13 = '9780385486804';

INSERT IGNORE INTO `explore_book_tags` (`book_id`, `tag_id`)
SELECT eb.id, t.id FROM `explore_books` eb
JOIN `catalog_tags` t ON t.slug IN ('classique', 'captivant', 'depaysant', 'page-turner')
WHERE eb.isbn13 = '9782070412228';

INSERT IGNORE INTO `explore_book_tags` (`book_id`, `tag_id`)
SELECT eb.id, t.id FROM `explore_books` eb
JOIN `catalog_tags` t ON t.slug IN ('depaysant', 'classique', 'captivant', 'intense')
WHERE eb.isbn13 = '9782267011920';

INSERT IGNORE INTO `explore_book_tags` (`book_id`, `tag_id`)
SELECT eb.id, t.id FROM `explore_books` eb
JOIN `catalog_tags` t ON t.slug IN ('captivant', 'dechirant', 'depaysant', 'intense')
WHERE eb.isbn13 = '9782290300008';

INSERT IGNORE INTO `explore_book_tags` (`book_id`, `tag_id`)
SELECT eb.id, t.id FROM `explore_books` eb
JOIN `catalog_tags` t ON t.slug IN ('depaysant', 'intellectuel', 'intense', 'captivant')
WHERE eb.isbn13 = '9782070461028';

INSERT IGNORE INTO `explore_book_tags` (`book_id`, `tag_id`)
SELECT eb.id, t.id FROM `explore_books` eb
JOIN `catalog_tags` t ON t.slug IN ('depaysant', 'captivant', 'page-turner', 'intellectuel')
WHERE eb.isbn13 = '9782070612352';

INSERT IGNORE INTO `explore_book_tags` (`book_id`, `tag_id`)
SELECT eb.id, t.id FROM `explore_books` eb
JOIN `catalog_tags` t ON t.slug IN ('inspirant', 'depaysant', 'accessible', 'philosophique')
WHERE eb.isbn13 = '9782290004449';

INSERT IGNORE INTO `explore_book_tags` (`book_id`, `tag_id`)
SELECT eb.id, t.id FROM `explore_books` eb
JOIN `catalog_tags` t ON t.slug IN ('captivant', 'depaysant', 'page-turner', 'intense')
WHERE eb.isbn13 = '9782290006238';

INSERT IGNORE INTO `explore_book_tags` (`book_id`, `tag_id`)
SELECT eb.id, t.id FROM `explore_books` eb
JOIN `catalog_tags` t ON t.slug IN ('classique', 'captivant', 'depaysant', 'accessible')
WHERE eb.isbn13 = '9782070413003';

COMMIT;
