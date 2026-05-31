-- ─────────────────────────────────────────────────────────────────────────────
-- Explore seed — batch 2026-05 (initial)
-- 6 categories × 10 books
-- Cover URLs: Amazon (images-na.ssl-images-amazon.com, ISBN10, taille L)
-- ISBN10 calculés depuis ISBN13 (retrait du préfixe 978 + recalcul check digit)
-- ─────────────────────────────────────────────────────────────────────────────

-- Remise à zéro (idempotent — safe à relancer)
DELETE FROM explore_books;
DELETE FROM explore_categories;

INSERT INTO explore_categories (slug, title, sort_order) VALUES
  ('must-read',  'À lire au moins une fois',      0),
  ('emotional',  'Envie de pleurer ?',             1),
  ('learn',      'Envie d\'apprendre ?',           2),
  ('adventure',  'Envie d\'aventure ?',            3),
  ('cult-2000s', 'Romans cultes des années 2000',  4),
  ('think-diff', 'Pour voir les choses autrement', 5);

-- ── À lire au moins une fois ─────────────────────────────────────────────────
INSERT INTO explore_books (category_id, title, author, cover_url, description, isbn13, provider_book_id, source)
SELECT c.id, books.title, books.author, books.cover_url, books.description, books.isbn13, books.isbn13 AS provider_book_id, 'isbndb' AS source
FROM explore_categories c
CROSS JOIN (
  SELECT 'L\'Étranger'           AS title, 'Albert Camus'             AS author,
         'https://images-na.ssl-images-amazon.com/images/P/2070360024.01.LZZZZZZZ.jpg' AS cover_url,
         'Meursault tue un homme sous le soleil d\'Alger. Un roman sur l\'absurde qui a changé la littérature.' AS description,
         '9782070360024' AS isbn13
  UNION ALL SELECT 'Le Petit Prince', 'Antoine de Saint-Exupéry',
         'https://images-na.ssl-images-amazon.com/images/P/2070408507.01.LZZZZZZZ.jpg',
         'On ne voit bien qu\'avec le cœur. Le livre le plus traduit au monde après la Bible.',
         '9782070408504'
  UNION ALL SELECT '1984', 'George Orwell',
         'https://images-na.ssl-images-amazon.com/images/P/0451524934.01.LZZZZZZZ.jpg',
         'Big Brother vous surveille. Le roman dystopique qui redéfinit notre rapport au pouvoir.',
         '9780451524935'
  UNION ALL SELECT 'Les Misérables', 'Victor Hugo',
         'https://images-na.ssl-images-amazon.com/images/P/2070500403.01.LZZZZZZZ.jpg',
         'Jean Valjean, Cosette, l\'inspecteur Javert. La fresque humaine la plus ample de la littérature française.',
         '9782070500406'
  UNION ALL SELECT 'Germinal', 'Émile Zola',
         'https://images-na.ssl-images-amazon.com/images/P/2072788811.01.LZZZZZZZ.jpg',
         'Les mineurs du Nord en grève. Zola à son sommet — un roman qui fait encore trembler.',
         '9782072788819'
  UNION ALL SELECT 'Madame Bovary', 'Gustave Flaubert',
         'https://images-na.ssl-images-amazon.com/images/P/207041311X.01.LZZZZZZZ.jpg',
         'Emma rêve d\'une autre vie. Le roman du désir inassouvi, et le procès le plus célèbre de l\'histoire littéraire.',
         '9782070413119'
  UNION ALL SELECT 'Le Père Goriot', 'Honoré de Balzac',
         'https://images-na.ssl-images-amazon.com/images/P/2070400948.01.LZZZZZZZ.jpg',
         'Rastignac arrive à Paris et découvre l\'ambition, la misère et la trahison. Balzac dans toute sa puissance.',
         '9782070400942'
  UNION ALL SELECT 'Bel-Ami', 'Guy de Maupassant',
         'https://images-na.ssl-images-amazon.com/images/P/2070413349.01.LZZZZZZZ.jpg',
         'Georges Duroy grimpe dans la société parisienne grâce aux femmes. Acéré et moderne.',
         '9782070413348'
  UNION ALL SELECT 'La Peste', 'Albert Camus',
         'https://images-na.ssl-images-amazon.com/images/P/2070360040.01.LZZZZZZZ.jpg',
         'Une épidémie s\'abat sur Oran. Camus interroge la solidarité humaine face à l\'absurde collectif.',
         '9782070360048'
  UNION ALL SELECT 'Candide', 'Voltaire',
         'https://images-na.ssl-images-amazon.com/images/P/2070417867.01.LZZZZZZZ.jpg',
         'Il faut cultiver notre jardin. La satire la plus féroce et la plus drôle du Siècle des Lumières.',
         '9782070417865'
) books
WHERE c.slug = 'must-read';

-- ── Envie de pleurer ? ───────────────────────────────────────────────────────
INSERT INTO explore_books (category_id, title, author, cover_url, description, isbn13, provider_book_id, source)
SELECT c.id, books.title, books.author, books.cover_url, books.description, books.isbn13, books.isbn13, 'isbndb'
FROM explore_categories c
CROSS JOIN (
  SELECT 'Never Let Me Go'        AS title, 'Kazuo Ishiguro'           AS author,
         'https://images-na.ssl-images-amazon.com/images/P/1400078776.01.LZZZZZZZ.jpg' AS cover_url,
         'Trois amis grandissent dans une école secrète. Ce qui les attend est insupportable et magnifique.' AS description,
         '9781400078776' AS isbn13
  UNION ALL SELECT 'A Little Life', 'Hanya Yanagihara',
         'https://images-na.ssl-images-amazon.com/images/P/0804172706.01.LZZZZZZZ.jpg',
         'Quatre amis à New York. L\'histoire de Jude est la plus dévastatrice jamais mise en mots.',
         '9780804172707'
  UNION ALL SELECT 'Les Cerfs-volants de Kaboul', 'Khaled Hosseini',
         'https://images-na.ssl-images-amazon.com/images/P/159463193X.01.LZZZZZZZ.jpg',
         'Amir et Hassan dans l\'Afghanistan des années 70. Une amitié trahie qui hante toute une vie.',
         '9781594631931'
  UNION ALL SELECT 'Mille soleils splendides', 'Khaled Hosseini',
         'https://images-na.ssl-images-amazon.com/images/P/1594483078.01.LZZZZZZZ.jpg',
         'Deux femmes afghanes liées par le destin sur trente ans. Encore plus déchirant que Les Cerfs-volants.',
         '9781594483073'
  UNION ALL SELECT 'La Voleuse de livres', 'Markus Zusak',
         'https://images-na.ssl-images-amazon.com/images/P/0375831002.01.LZZZZZZZ.jpg',
         'Liesel vole des livres dans l\'Allemagne nazie. La mort elle-même raconte son histoire.',
         '9780375831003'
  UNION ALL SELECT 'Nos étoiles contraires', 'John Green',
         'https://images-na.ssl-images-amazon.com/images/P/0525478817.01.LZZZZZZZ.jpg',
         'Hazel et Gus se rencontrent dans un groupe de soutien pour malades du cancer. L\'amour à l\'état pur.',
         '9780525478812'
  UNION ALL SELECT 'Avant toi', 'Jojo Moyes',
         'https://images-na.ssl-images-amazon.com/images/P/0143124544.01.LZZZZZZZ.jpg',
         'Louisa prend soin de Will, tétraplégique. Deux visions de la vie radicalement opposées.',
         '9780143124542'
  UNION ALL SELECT 'La Route', 'Cormac McCarthy',
         'https://images-na.ssl-images-amazon.com/images/P/0307387895.01.LZZZZZZZ.jpg',
         'Un père et son fils traversent une Amérique dévastée. L\'amour parental à l\'état brut.',
         '9780307387899'
  UNION ALL SELECT 'Des souris et des hommes', 'John Steinbeck',
         'https://images-na.ssl-images-amazon.com/images/P/0140177396.01.LZZZZZZZ.jpg',
         'George et Lennie rêvent d\'une vie meilleure. Steinbeck en 120 pages qui brisent le cœur.',
         '9780140177398'
  UNION ALL SELECT 'Charlotte', 'David Foenkinos',
         'https://images-na.ssl-images-amazon.com/images/P/2070145379.01.LZZZZZZZ.jpg',
         'La vie de Charlotte Salomon, peintre juive morte à Auschwitz. Un roman qui coupe le souffle.',
         '9782070145379'
) books
WHERE c.slug = 'emotional';

-- ── Envie d'apprendre ? ──────────────────────────────────────────────────────
INSERT INTO explore_books (category_id, title, author, cover_url, description, isbn13, provider_book_id, source)
SELECT c.id, books.title, books.author, books.cover_url, books.description, books.isbn13, books.isbn13, 'isbndb'
FROM explore_categories c
CROSS JOIN (
  SELECT 'Sapiens'                AS title, 'Yuval Noah Harari'        AS author,
         'https://images-na.ssl-images-amazon.com/images/P/0062316095.01.LZZZZZZZ.jpg' AS cover_url,
         'L\'histoire complète de l\'humanité en 400 pages. Le livre de non-fiction le plus influent de la décennie.' AS description,
         '9780062316097' AS isbn13
  UNION ALL SELECT 'Thinking, Fast and Slow', 'Daniel Kahneman',
         'https://images-na.ssl-images-amazon.com/images/P/0374533555.01.LZZZZZZZ.jpg',
         'Nos deux systèmes de pensée décryptés par un Prix Nobel. Change ta façon de décider.',
         '9780374533557'
  UNION ALL SELECT 'Outliers', 'Malcolm Gladwell',
         'https://images-na.ssl-images-amazon.com/images/P/0316017930.01.LZZZZZZZ.jpg',
         'Pourquoi certains réussissent et pas d\'autres ? La règle des 10 000 heures et le rôle du contexte.',
         '9780316017930'
  UNION ALL SELECT 'Atomic Habits', 'James Clear',
         'https://images-na.ssl-images-amazon.com/images/P/0735211299.01.LZZZZZZZ.jpg',
         'Les habitudes minuscules qui changent tout. Le livre de productivité le plus actionnable jamais écrit.',
         '9780735211292'
  UNION ALL SELECT 'Homo Deus', 'Yuval Noah Harari',
         'https://images-na.ssl-images-amazon.com/images/P/0062464310.01.LZZZZZZZ.jpg',
         'Où va l\'humanité ? IA, immortalité, algorithmes. La suite de Sapiens, encore plus troublante.',
         '9780062464316'
  UNION ALL SELECT 'Découvrir un sens à sa vie', 'Viktor Frankl',
         'https://images-na.ssl-images-amazon.com/images/P/080701429X.01.LZZZZZZZ.jpg',
         'Survivant d\'Auschwitz, Frankl explique comment trouver un sens même dans la souffrance absolue.',
         '9780807014295'
  UNION ALL SELECT 'Le Cygne Noir', 'Nassim Taleb',
         'https://images-na.ssl-images-amazon.com/images/P/081297381X.01.LZZZZZZZ.jpg',
         'Les événements improbables qui changent tout. Réinvente notre rapport au hasard et à l\'incertitude.',
         '9780812973815'
  UNION ALL SELECT 'Influence', 'Robert Cialdini',
         'https://images-na.ssl-images-amazon.com/images/P/006124189X.01.LZZZZZZZ.jpg',
         'Les 6 principes de persuasion qui gouvernent nos décisions. Le livre de psychologie sociale incontournable.',
         '9780061241895'
  UNION ALL SELECT 'L\'Art subtil de s\'en foutre', 'Mark Manson',
         'https://images-na.ssl-images-amazon.com/images/P/0062457713.01.LZZZZZZZ.jpg',
         'Un guide à contre-courant pour vivre une bonne vie. Cynique, drôle, et étonnamment sage.',
         '9780062457714'
  UNION ALL SELECT 'Comment se faire des amis', 'Dale Carnegie',
         'https://images-na.ssl-images-amazon.com/images/P/0671027034.01.LZZZZZZZ.jpg',
         'Écrit en 1936, toujours aussi pertinent. Les bases des relations humaines, immuables.',
         '9780671027032'
) books
WHERE c.slug = 'learn';

-- ── Envie d'aventure ? ───────────────────────────────────────────────────────
INSERT INTO explore_books (category_id, title, author, cover_url, description, isbn13, provider_book_id, source)
SELECT c.id, books.title, books.author, books.cover_url, books.description, books.isbn13, books.isbn13, 'isbndb'
FROM explore_categories c
CROSS JOIN (
  SELECT 'Le Comte de Monte-Cristo' AS title, 'Alexandre Dumas'        AS author,
         'https://images-na.ssl-images-amazon.com/images/P/0140449264.01.LZZZZZZZ.jpg' AS cover_url,
         'Edmond Dantès, trahi et emprisonné, prépare une vengeance magistrale. L\'aventure ultime.' AS description,
         '9780140449266' AS isbn13
  UNION ALL SELECT '20 000 lieues sous les mers', 'Jules Verne',
         'https://images-na.ssl-images-amazon.com/images/P/0553213873.01.LZZZZZZZ.jpg',
         'À bord du Nautilus avec le mystérieux capitaine Nemo. Jules Verne invente la science-fiction.',
         '9780553213874'
  UNION ALL SELECT 'Into the Wild', 'Jon Krakauer',
         'https://images-na.ssl-images-amazon.com/images/P/0385486804.01.LZZZZZZZ.jpg',
         'Chris McCandless abandonne tout pour l\'Alaska sauvage. Une histoire vraie fascinante et tragique.',
         '9780385486804'
  UNION ALL SELECT 'L\'Alchimiste', 'Paulo Coelho',
         'https://images-na.ssl-images-amazon.com/images/P/0062315005.01.LZZZZZZZ.jpg',
         'Santiago traverse le désert à la recherche d\'un trésor. Le roman de la quête de soi.',
         '9780062315007'
  UNION ALL SELECT 'L\'Histoire de Pi', 'Yann Martel',
         'https://images-na.ssl-images-amazon.com/images/P/0156027321.01.LZZZZZZZ.jpg',
         'Un adolescent naufragé sur un canot de sauvetage avec un tigre du Bengale. Prix Booker.',
         '9780156027328'
  UNION ALL SELECT 'Wild', 'Cheryl Strayed',
         'https://images-na.ssl-images-amazon.com/images/P/0307476073.01.LZZZZZZZ.jpg',
         '1 100 miles à pied seule sur le Pacific Crest Trail. Une femme qui se reconstruit pas à pas.',
         '9780307476074'
  UNION ALL SELECT 'Shantaram', 'Gregory David Roberts',
         'https://images-na.ssl-images-amazon.com/images/P/0312330537.01.LZZZZZZZ.jpg',
         'Un évadé australien se réfugie à Bombay. 900 pages d\'aventure pure, inspiré d\'une histoire vraie.',
         '9780312330538'
  UNION ALL SELECT 'Le Seigneur des anneaux', 'J.R.R. Tolkien',
         'https://images-na.ssl-images-amazon.com/images/P/0618640150.01.LZZZZZZZ.jpg',
         'Frodon doit détruire l\'Anneau Unique. La fantasy épique qui a tout créé.',
         '9780618640157'
  UNION ALL SELECT 'L\'Ombre du vent', 'Carlos Ruiz Zafón',
         'https://images-na.ssl-images-amazon.com/images/P/0143034901.01.LZZZZZZZ.jpg',
         'Barcelone, 1945. Un jeune garçon découvre un livre et une obsession. Un page-turner parfait.',
         '9780143034902'
  UNION ALL SELECT 'Le Guide du voyageur galactique', 'Douglas Adams',
         'https://images-na.ssl-images-amazon.com/images/P/0345391802.01.LZZZZZZZ.jpg',
         'La Terre est démolie pour une voie express. La réponse est 42. Culte et inclassable.',
         '9780345391803'
) books
WHERE c.slug = 'adventure';

-- ── Romans cultes des années 2000 ────────────────────────────────────────────
INSERT INTO explore_books (category_id, title, author, cover_url, description, isbn13, provider_book_id, source)
SELECT c.id, books.title, books.author, books.cover_url, books.description, books.isbn13, books.isbn13, 'isbndb'
FROM explore_categories c
CROSS JOIN (
  SELECT 'Da Vinci Code'          AS title, 'Dan Brown'                AS author,
         'https://images-na.ssl-images-amazon.com/images/P/0307474275.01.LZZZZZZZ.jpg' AS cover_url,
         'Un meurtre au Louvre, des symboles secrets, une chasse au trésor mondiale. 80 millions d\'exemplaires.' AS description,
         '9780307474278' AS isbn13
  UNION ALL SELECT 'Millénium — Les Hommes qui n\'aimaient pas les femmes', 'Stieg Larsson',
         'https://images-na.ssl-images-amazon.com/images/P/0307454541.01.LZZZZZZZ.jpg',
         'Lisbeth Salander, hackeuse iconique. La série policière qui a redéfini le genre.',
         '9780307454546'
  UNION ALL SELECT 'Norwegian Wood', 'Haruki Murakami',
         'https://images-na.ssl-images-amazon.com/images/P/0375704027.01.LZZZZZZZ.jpg',
         'Un étudiant hanté par la mort de ses amis dans le Tokyo des années 60. Murakami au plus intime.',
         '9780375704024'
  UNION ALL SELECT 'Gone Girl', 'Gillian Flynn',
         'https://images-na.ssl-images-amazon.com/images/P/030758836X.01.LZZZZZZZ.jpg',
         'Amy disparaît le jour de leur anniversaire de mariage. Retournement final culte.',
         '9780307588364'
  UNION ALL SELECT 'Hunger Games', 'Suzanne Collins',
         'https://images-na.ssl-images-amazon.com/images/P/0439023483.01.LZZZZZZZ.jpg',
         'Katniss Everdeen, 24 tributs, un seul survivant. La dystopie YA qui a tout déclenché.',
         '9780439023481'
  UNION ALL SELECT 'American Gods', 'Neil Gaiman',
         'https://images-na.ssl-images-amazon.com/images/P/0380789035.01.LZZZZZZZ.jpg',
         'Les anciens dieux contre les nouveaux (Internet, télévision) dans l\'Amérique contemporaine.',
         '9780380789030'
  UNION ALL SELECT 'Mange Prie Aime', 'Elizabeth Gilbert',
         'https://images-na.ssl-images-amazon.com/images/P/0670034169.01.LZZZZZZZ.jpg',
         'Après un divorce, une année en Italie, Inde et Bali. A lancé une génération de voyageurs.',
         '9780670034161'
  UNION ALL SELECT 'Harry Potter à l\'école des sorciers', 'J.K. Rowling',
         'https://images-na.ssl-images-amazon.com/images/P/0439708184.01.LZZZZZZZ.jpg',
         'Le sorcier le plus célèbre du monde. Une génération entière a grandi avec Harry, Ron et Hermione.',
         '9780439708180'
  UNION ALL SELECT 'Kafka sur le rivage', 'Haruki Murakami',
         'https://images-na.ssl-images-amazon.com/images/P/1400079276.01.LZZZZZZZ.jpg',
         'Deux histoires parallèles dans le Japon contemporain. Murakami à son sommet créatif.',
         '9781400079278'
  UNION ALL SELECT 'La Vérité sur l\'affaire Harry Quebert', 'Joël Dicker',
         'https://images-na.ssl-images-amazon.com/images/P/2246857589.01.LZZZZZZZ.jpg',
         'Un écrivain enquête sur son mentor accusé de meurtre. Le phénomène éditorial français des années 2010.',
         '9782246857587'
) books
WHERE c.slug = 'cult-2000s';

-- ── Pour voir les choses autrement ───────────────────────────────────────────
INSERT INTO explore_books (category_id, title, author, cover_url, description, isbn13, provider_book_id, source)
SELECT c.id, books.title, books.author, books.cover_url, books.description, books.isbn13, books.isbn13, 'isbndb'
FROM explore_categories c
CROSS JOIN (
  SELECT 'Pensées pour moi-même'  AS title, 'Marc Aurèle'             AS author,
         'https://images-na.ssl-images-amazon.com/images/P/0140449337.01.LZZZZZZZ.jpg' AS cover_url,
         'Le journal intime d\'un empereur romain. Le stoïcisme à l\'état pur, écrit il y a 2 000 ans.' AS description,
         '9780140449334' AS isbn13
  UNION ALL SELECT 'Le Mythe de Sisyphe', 'Albert Camus',
         'https://images-na.ssl-images-amazon.com/images/P/0679733736.01.LZZZZZZZ.jpg',
         'Il faut imaginer Sisyphe heureux. Comment vivre dans un monde absurde. Camus au sommet.',
         '9780679733737'
  UNION ALL SELECT 'Découvrir un sens à sa vie', 'Viktor Frankl',
         'https://images-na.ssl-images-amazon.com/images/P/080701429X.01.LZZZZZZZ.jpg',
         'La logothérapie née dans les camps. Trouver un pourquoi pour supporter n\'importe quel comment.',
         '9780807014295'
  UNION ALL SELECT 'L\'Art d\'aimer', 'Erich Fromm',
         'https://images-na.ssl-images-amazon.com/images/P/0060916478.01.LZZZZZZZ.jpg',
         'L\'amour n\'est pas un sentiment — c\'est une pratique. Redéfinit complètement ce qu\'est aimer.',
         '9780060916473'
  UNION ALL SELECT 'L\'Art subtil de s\'en foutre', 'Mark Manson',
         'https://images-na.ssl-images-amazon.com/images/P/0062457713.01.LZZZZZZZ.jpg',
         'Un guide à contre-courant pour vivre une bonne vie. Cynique, drôle, étonnamment sage.',
         '9780062457714'
  UNION ALL SELECT 'Walden', 'Henry David Thoreau',
         'https://images-na.ssl-images-amazon.com/images/P/0691096120.01.LZZZZZZZ.jpg',
         'Deux ans seul dans les bois. La réflexion fondatrice sur la simplicité volontaire.',
         '9780691096124'
  UNION ALL SELECT '12 règles pour une vie', 'Jordan Peterson',
         'https://images-na.ssl-images-amazon.com/images/P/0345816021.01.LZZZZZZZ.jpg',
         'Nettoie ta chambre. Affronte le chaos. Une philosophie de vie directe et sans concession.',
         '9780345816023'
  UNION ALL SELECT 'Flow', 'Mihaly Csikszentmihalyi',
         'https://images-na.ssl-images-amazon.com/images/P/0061339202.01.LZZZZZZZ.jpg',
         'L\'état optimal d\'absorption totale dans une activité. La psychologie du bonheur par l\'engagement.',
         '9780061339202'
  UNION ALL SELECT 'Les Frères Karamazov', 'Fiodor Dostoïevski',
         'https://images-na.ssl-images-amazon.com/images/P/0374528373.01.LZZZZZZZ.jpg',
         'Dieu existe-t-il ? L\'homme est-il libre ? Dostoïevski pose les questions ultimes en 900 pages.',
         '9780374528379'
  UNION ALL SELECT 'Ainsi parlait Zarathoustra', 'Friedrich Nietzsche',
         'https://images-na.ssl-images-amazon.com/images/P/0140441182.01.LZZZZZZZ.jpg',
         'Dieu est mort. L\'Übermensch. Nietzsche à son plus ambitieux — difficile, mais transformateur.',
         '9780140441185'
) books
WHERE c.slug = 'think-diff';
