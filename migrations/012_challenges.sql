-- ─────────────────────────────────────────────────────────────────────────────
-- 012 · Challenges
-- ─────────────────────────────────────────────────────────────────────────────

DROP TABLE IF EXISTS user_challenge_completions;
DROP TABLE IF EXISTS challenges;
DROP TABLE IF EXISTS weekly_challenges;

-- ── challenges ────────────────────────────────────────────────────────────────
CREATE TABLE challenges (
    id           BIGINT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    kind         ENUM('weekly','monthly') NOT NULL DEFAULT 'weekly',
    type         VARCHAR(50)      NOT NULL,
    title        VARCHAR(255)     NOT NULL,
    description  TEXT             NULL,
    reward_title VARCHAR(255)     NULL,   -- titre affiché sur l'écran de récompense
    reward_text  TEXT             NULL,   -- message personnalisé de félicitation
    target_value INT UNSIGNED     NOT NULL DEFAULT 1,
    xp_reward    INT UNSIGNED     NOT NULL DEFAULT 50,
    book_id      BIGINT UNSIGNED  NULL,
    period_start DATE             NOT NULL,
    period_end   DATE             NOT NULL,
    sort_order   TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at   TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_period (period_start, period_end),
    CONSTRAINT fk_challenge_book
        FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── user_challenge_completions ────────────────────────────────────────────────
CREATE TABLE user_challenge_completions (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      BIGINT UNSIGNED NOT NULL,
    challenge_id BIGINT UNSIGNED NOT NULL,
    xp_awarded   INT UNSIGNED    NOT NULL DEFAULT 0,
    completed_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_challenge (user_id, challenge_id),
    FOREIGN KEY (user_id)      REFERENCES users(id)      ON DELETE CASCADE,
    FOREIGN KEY (challenge_id) REFERENCES challenges(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Seed
-- ─────────────────────────────────────────────────────────────────────────────

SET @today            = CURDATE();
SET @week_start       = DATE_SUB(@today, INTERVAL (DAYOFWEEK(@today) + 5) % 7 DAY);
SET @week_end         = DATE_ADD(@week_start, INTERVAL 6 DAY);
SET @next_week_start  = DATE_ADD(@week_start, INTERVAL 7 DAY);
SET @next_week_end    = DATE_ADD(@week_end,   INTERVAL 7 DAY);
SET @month_start      = DATE_FORMAT(@today, '%Y-%m-01');
SET @month_end        = LAST_DAY(@today);
SET @next_month_start = DATE_FORMAT(DATE_ADD(@today, INTERVAL 1 MONTH), '%Y-%m-01');
SET @next_month_end   = LAST_DAY(DATE_ADD(@today, INTERVAL 1 MONTH));

-- ── Défis semaine ─────────────────────────────────────────────────────────────
INSERT INTO challenges
  (kind, type, title, description, reward_title, reward_text, target_value, xp_reward, period_start, period_end, sort_order)
VALUES

('weekly', 'PAGES_READ', 'Marathon de pages',
  'Lis au moins 100 pages cette semaine.',
  'Beau marathon !',
  'Cent pages en une semaine, c\'est une vraie discipline de lecteur. Chaque session de lecture forge ta concentration et enrichit ton imaginaire. Garde ce rythme !',
  100, 80, @week_start, @week_end, 0),

('weekly', 'VOCAB_ADDED', 'Chasseur de mots',
  'Ajoute 5 nouveaux mots de vocabulaire à tes fiches.',
  'Vocabulaire enrichi !',
  'Cinq nouveaux mots ajoutés à ton arsenal. La maîtrise du langage est le premier outil de la pensée — tu viens de l\'affûter un peu plus.',
  5, 50, @week_start, @week_end, 1),

('weekly', 'QUOTES_ADDED', 'Collectionneur de citations',
  'Note 3 citations marquantes dans tes lectures.',
  'Belle collection !',
  'Trois citations sauvées de l\'oubli. Les phrases qu\'on retient disent autant sur nous que sur leurs auteurs. Tu construis ta propre bibliothèque de pensées.',
  3, 50, @week_start, @week_end, 2),

('weekly', 'CHAPTERS_ADDED', 'Prise de notes',
  'Rédige des notes sur 2 chapitres de tes livres.',
  'Notes prises !',
  'Deux chapitres annotés, c\'est deux fois plus de chances de retenir l\'essentiel. Écrire ce qu\'on lit, c\'est lire deux fois.',
  2, 60, @week_start, @week_end, 3);

-- ── Défis mois ────────────────────────────────────────────────────────────────
INSERT INTO challenges
  (kind, type, title, description, reward_title, reward_text, target_value, xp_reward, period_start, period_end, sort_order)
VALUES

('monthly', 'PAGES_READ', 'Lecteur du mois',
  'Lis 400 pages au cours du mois.',
  'Mois de lecteur accompli !',
  'Quatre cents pages en un mois, c\'est une performance remarquable. Tu as prouvé que la lecture n\'est pas un loisir occasionnel, mais une vraie pratique. Bravo.',
  400, 200, @month_start, @month_end, 0),

('monthly', 'BOOKS_FINISHED', 'Terminer un livre',
  'Termine au moins un livre ce mois-ci.',
  'Livre bouclé !',
  'Un livre de plus dans ta liste. Chaque fin de livre est un petit accomplissement — tu n\'as pas abandonné, tu es allé au bout. C\'est plus rare qu\'on ne le croit.',
  1, 150, @month_start, @month_end, 1),

('monthly', 'VOCAB_ADDED', 'Vocabulaire enrichi',
  'Ajoute 20 nouveaux mots à tes fiches ce mois-ci.',
  'Beau travail sur le vocabulaire !',
  'Vingt mots, c\'est vingt façons nouvelles de nommer le monde. Le vocabulaire, c\'est la précision de la pensée — et tu viens de l\'aiguiser sérieusement.',
  20, 120, @month_start, @month_end, 2);

-- ── Défi livre du mois ────────────────────────────────────────────────────────
-- Modifie le WHERE pour cibler le livre de ton choix.
INSERT INTO challenges
  (kind, type, title, description, reward_title, reward_text, target_value, xp_reward, book_id, period_start, period_end, sort_order)
SELECT
  'monthly',
  'BOOK_SPECIFIC',
  CONCAT('Lire : ', b.title),
  CONCAT('Termine « ', b.title, ' » de ', b.author, ' avant la fin du mois.'),
  CONCAT('Tu as terminé ', b.title, ' !'),
  CONCAT(
    'Félicitations ! Tu as terminé « ', b.title, ' » de ', b.author, '. ',
    'Ce livre t\'a offert une plongée dans un univers littéraire unique. ',
    'Prends le temps de noter ce que tu en retiens — les meilleures lectures laissent toujours une trace.'
  ),
  1, 300, b.id, @month_start, @month_end, 3
FROM books b
WHERE b.title LIKE '%Avare%'
LIMIT 1;

-- ── Semaine prochaine (preview) ───────────────────────────────────────────────
INSERT INTO challenges
  (kind, type, title, description, reward_title, reward_text, target_value, xp_reward, period_start, period_end, sort_order)
VALUES

('weekly', 'PAGES_READ', 'À fond les pages',
  'Lis 150 pages la semaine prochaine.',
  'Excellent rythme !',
  'Cent cinquante pages en une semaine, tu es clairement dans le tempo. La régularité de lecture est une des habitudes les plus précieuses qui soit.',
  150, 100, @next_week_start, @next_week_end, 0),

('weekly', 'VOCAB_ADDED', 'Semaine studieuse',
  'Enrichis ton vocabulaire avec 8 nouveaux mots.',
  'Semaine productive !',
  'Huit mots glanés au fil de tes lectures. À ce rythme, ton vocabulaire actif va s\'élargir de façon significative en quelques mois.',
  8, 70, @next_week_start, @next_week_end, 1),

('weekly', 'QUOTES_ADDED', 'Citations choisies',
  'Sélectionne 5 citations de tes lectures.',
  'Belle sélection !',
  'Cinq citations préservées. Une bonne citation, c\'est une idée qu\'on peut emporter partout. Tu t\'es constitué un vrai trésor.',
  5, 60, @next_week_start, @next_week_end, 2);

-- ── Mois prochain (preview) ───────────────────────────────────────────────────
INSERT INTO challenges
  (kind, type, title, description, reward_title, reward_text, target_value, xp_reward, period_start, period_end, sort_order)
VALUES

('monthly', 'PAGES_READ', 'Grand lecteur',
  'Lis 500 pages au cours du mois prochain.',
  'Performance exceptionnelle !',
  'Cinq cents pages en un mois, c\'est une vraie prouesse. Tu fais partie des lecteurs assidus qui savent que chaque livre est une conversation avec son auteur.',
  500, 250, @next_month_start, @next_month_end, 0),

('monthly', 'BOOKS_FINISHED', 'Double objectif',
  'Termine deux livres dans le mois.',
  'Double accomplissement !',
  'Deux livres terminés en un mois — c\'est deux univers explorés, deux points de vue assimilés. Tu ne lis pas, tu accumules de l\'expérience.',
  2, 250, @next_month_start, @next_month_end, 1);
