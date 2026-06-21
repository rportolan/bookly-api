-- ============================================================
-- 021_challenges_timeless.sql
--   Refonte totale des défis : paliers de progression intemporels
--   (plus de notion de semaine / mois).
--
--   Les colonnes kind / period_start / period_end existent toujours
--   mais ne sont plus utilisées par le backend — on les remplit avec
--   des valeurs neutres (plage très large).
--
--   ⚠️ La suppression des défis cascade sur user_challenge_completions
--   (FK ON DELETE CASCADE). Les anciens enregistrements de complétion
--   sont effacés : les nouveaux paliers déjà atteints par un utilisateur
--   seront re-validés au prochain chargement (et la récompense réaffichée).
-- ============================================================

START TRANSACTION;

-- Repart de zéro
DELETE FROM `challenges`;

INSERT INTO `challenges`
  (`kind`, `type`, `title`, `description`, `reward_title`, `reward_text`,
   `target_value`, `xp_reward`, `book_id`, `period_start`, `period_end`, `sort_order`)
VALUES

-- ── Pages lues (cumul total) ─────────────────────────────────────────────────
('weekly','PAGES_READ','Premiers pas','Lis 100 pages au total.','Premières pages !','Cent pages au compteur. Chaque grand lecteur a commencé exactement là où tu es. C''est le début de quelque chose.',100,50,NULL,'2020-01-01','2099-12-31',0),
('weekly','PAGES_READ','Lecteur assidu','Lis 500 pages au total.','Bien lancé !','Cinq cents pages, ce n''est plus un hasard : c''est une habitude qui s''installe. Continue, le plus dur est derrière toi.',500,100,NULL,'2020-01-01','2099-12-31',1),
('weekly','PAGES_READ','Le cap des mille','Lis 1 000 pages au total.','Mille pages !','Mille pages lues. Tu fais désormais partie des lecteurs qui tiennent la distance. Une vraie fierté.',1000,160,NULL,'2020-01-01','2099-12-31',2),
('weekly','PAGES_READ','Grand lecteur','Lis 2 500 pages au total.','Performance solide !','Deux mille cinq cents pages — l''équivalent d''une petite bibliothèque. Ta régularité paie, et ça se voit.',2500,280,NULL,'2020-01-01','2099-12-31',3),
('weekly','PAGES_READ','Dévoreur de pages','Lis 5 000 pages au total.','Impressionnant !','Cinq mille pages. À ce stade, lire n''est plus une activité, c''est une partie de toi. Chapeau.',5000,450,NULL,'2020-01-01','2099-12-31',4),
('weekly','PAGES_READ','Bibliophage','Lis 10 000 pages au total.','Lecteur d''exception !','Dix mille pages. Très peu de gens atteignent ce niveau. Tu es de ceux pour qui les livres comptent vraiment.',10000,700,NULL,'2020-01-01','2099-12-31',5),

-- ── Livres terminés (cumul total) ────────────────────────────────────────────
('weekly','BOOKS_FINISHED','Premier livre','Termine 1 livre.','Premier livre bouclé !','Un livre terminé, du début à la fin. C''est plus rare qu''on ne le croit — beaucoup abandonnent en route. Pas toi.',1,80,NULL,'2020-01-01','2099-12-31',10),
('weekly','BOOKS_FINISHED','Trois lectures','Termine 3 livres.','Belle série !','Trois livres au compteur. Trois univers explorés, trois fins atteintes. Tu prends le rythme.',3,160,NULL,'2020-01-01','2099-12-31',11),
('weekly','BOOKS_FINISHED','Cinq sur l''étagère','Termine 5 livres.','Étagère qui se remplit !','Cinq livres terminés. Ta bibliothèque personnelle commence à raconter une histoire : la tienne.',5,260,NULL,'2020-01-01','2099-12-31',12),
('weekly','BOOKS_FINISHED','La dizaine','Termine 10 livres.','Dizaine atteinte !','Dix livres lus jusqu''au bout. Tu n''es plus un lecteur occasionnel — c''est devenu une vraie pratique.',10,450,NULL,'2020-01-01','2099-12-31',13),
('weekly','BOOKS_FINISHED','Grand collectionneur','Termine 25 livres.','Lecteur accompli !','Vingt-cinq livres. Une vraie collection, une vraie culture qui se construit page après page. Remarquable.',25,800,NULL,'2020-01-01','2099-12-31',14),

-- ── Vocabulaire (cumul total) ────────────────────────────────────────────────
('weekly','VOCAB_ADDED','Premiers mots','Ajoute 10 mots à ton vocabulaire.','Lexique lancé !','Dix mots collectés. La maîtrise du langage est le premier outil de la pensée — tu viens de l''affûter.',10,50,NULL,'2020-01-01','2099-12-31',20),
('weekly','VOCAB_ADDED','Lexique en marche','Ajoute 25 mots à ton vocabulaire.','Vocabulaire enrichi !','Vingt-cinq mots, c''est vingt-cinq nuances de plus pour dire le monde. Ton expression gagne en précision.',25,110,NULL,'2020-01-01','2099-12-31',21),
('weekly','VOCAB_ADDED','Vocabulaire riche','Ajoute 50 mots à ton vocabulaire.','Beau travail !','Cinquante mots glanés au fil de tes lectures. Ton vocabulaire actif s''élargit pour de bon.',50,200,NULL,'2020-01-01','2099-12-31',22),
('weekly','VOCAB_ADDED','Maître des mots','Ajoute 100 mots à ton vocabulaire.','Orfèvre du langage !','Cent mots. Tu ne lis plus seulement pour l''histoire, mais aussi pour la langue. C''est la marque des grands lecteurs.',100,380,NULL,'2020-01-01','2099-12-31',23),

-- ── Citations (cumul total) ──────────────────────────────────────────────────
('weekly','QUOTES_ADDED','Premières citations','Note 5 citations marquantes.','Belle collection !','Cinq phrases sauvées de l''oubli. Les citations qu''on retient disent autant sur nous que sur leurs auteurs.',5,50,NULL,'2020-01-01','2099-12-31',30),
('weekly','QUOTES_ADDED','Collectionneur','Note 15 citations marquantes.','Carnet bien rempli !','Quinze citations préservées. Tu te construis une bibliothèque de pensées dans laquelle puiser à tout moment.',15,130,NULL,'2020-01-01','2099-12-31',31),
('weekly','QUOTES_ADDED','Anthologie personnelle','Note 30 citations marquantes.','Superbe anthologie !','Trente citations rassemblées — un vrai trésor d''idées et de belles phrases qui n''appartient qu''à toi.',30,240,NULL,'2020-01-01','2099-12-31',32),

-- ── Chapitres annotés (cumul total) ──────────────────────────────────────────
('weekly','CHAPTERS_ADDED','Premières notes','Annote 5 chapitres.','Notes prises !','Cinq chapitres annotés. Écrire ce qu''on lit, c''est lire deux fois — et retenir bien davantage.',5,60,NULL,'2020-01-01','2099-12-31',40),
('weekly','CHAPTERS_ADDED','Annotateur','Annote 15 chapitres.','Bel effort d''analyse !','Quinze chapitres passés à la loupe. L''annotation transforme la lecture passive en dialogue avec l''auteur.',15,150,NULL,'2020-01-01','2099-12-31',41),
('weekly','CHAPTERS_ADDED','Analyste','Annote 30 chapitres.','Lecteur méthodique !','Trente chapitres décortiqués. Tu ne traverses pas les livres, tu les habites. C''est une lecture de fond.',30,260,NULL,'2020-01-01','2099-12-31',42);

COMMIT;
