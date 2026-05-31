<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Db;

final class LearnRepository
{
    private \PDO $pdo;

    public function __construct()
    {
        $this->pdo = Db::pdo();
    }

    // -------------------------------------------------------------------------
    // Generic fallback distractor pools (used when the user's own data is sparse)
    // -------------------------------------------------------------------------

    private const GENERIC_DEFINITIONS = [
        'Sentiment de crainte mêlé de respect et d\'admiration',
        'Action de remettre quelque chose à plus tard',
        'Qui se produit deux fois par an',
        'Caractère de ce qui est passager, éphémère',
        'Tendance à voir les choses sous leur aspect le plus favorable',
        'Ensemble des règles qui régissent une langue',
        'Attitude de celui qui cherche à plaire en flattant',
        'Disposition à s\'irriter facilement',
        'Qui manque de clarté, difficile à comprendre',
        'Sentiment de pitié et de compassion pour autrui',
        'Qui appartient à une autre époque, démodé',
        'État de profonde indifférence au plaisir comme à la douleur',
        'Qui agit avec une grande économie, qui dépense peu',
        'Discours long et ennuyeux',
        'Qualité de ce qui est vrai, conforme à la réalité',
    ];

    private const GENERIC_WORDS = [
        'Vénération',
        'Procrastination',
        'Semestriel',
        'Éphémère',
        'Optimisme',
        'Grammaire',
        'Flatterie',
        'Irascibilité',
        'Obscur',
        'Empathie',
        'Anachronique',
        'Stoïcisme',
        'Avare',
        'Tirade',
        'Véracité',
    ];

    private const GENERIC_CHARACTER_NAMES = [
        'Philinte',
        'Célimène',
        'Orgon',
        'Dorine',
        'Valère',
        'Mariane',
        'Tartuffe',
        'Elmire',
        'Alceste',
        'Cléante',
        'Lisette',
        'Damis',
        'Ariste',
        'Éraste',
        'Toinette',
    ];

    private const GENERIC_ROLES = [
        'Protagoniste et héros de l\'histoire',
        'Antagoniste, opposé au héros',
        'Personnage comique, source de légèreté',
        'Confident(e) du personnage principal',
        'Figure d\'autorité et de pouvoir',
        'Amant(e) ou intérêt romantique',
        'Serviteur(se) dévoué(e)',
        'Traître qui trompe les autres',
        'Mentor qui guide le héros',
        'Personnage secondaire de soutien',
        'Messager porteur de nouvelles',
        'Personnage mystérieux à l\'identité cachée',
    ];

    private const GENERIC_BOOK_TITLES = [
        'Le Misanthrope',
        'Tartuffe',
        'Dom Juan',
        'Les Misérables',
        'Le Rouge et le Noir',
        'Madame Bovary',
        'Germinal',
        'Les Fleurs du Mal',
        'Candide',
        'L\'Étranger',
        'La Nausée',
        'Phèdre',
        'Le Cid',
        'Andromaque',
        'Les Liaisons dangereuses',
    ];

    private const GENERIC_AUTHORS = [
        'Molière',
        'Victor Hugo',
        'Stendhal',
        'Gustave Flaubert',
        'Émile Zola',
        'Charles Baudelaire',
        'Voltaire',
        'Albert Camus',
        'Jean-Paul Sartre',
        'Racine',
        'Corneille',
        'Balzac',
        'Flaubert',
        'Maupassant',
        'Proust',
    ];

    public function userOwnsUserBook(int $userId, int $userBookId): bool
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM user_books WHERE id = :ubid AND user_id = :uid LIMIT 1");
        $stmt->execute(['ubid' => $userBookId, 'uid' => $userId]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Validate that all given userBookIds belong to the user.
     * Returns only the valid IDs.
     */
    public function filterOwnedBooks(int $userId, array $userBookIds): array
    {
        if (empty($userBookIds)) return [];
        $placeholders = implode(',', array_fill(0, count($userBookIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT id FROM user_books WHERE user_id = ? AND id IN ($placeholders)"
        );
        $stmt->execute([$userId, ...$userBookIds]);
        return array_column($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [], 'id');
    }

    public function listBooksForLearn(int $userId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT
              ub.id AS userBookId,
              b.id  AS bookId,
              b.title,
              b.author
            FROM user_books ub
            JOIN books b ON b.id = ub.book_id
            WHERE ub.user_id = :uid
            ORDER BY ub.created_at DESC
        ");
        $stmt->execute(['uid' => $userId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Build a flashcard deck.
     * $userBookIds: specific book IDs to include; empty = all user's books.
     * $types: array of 'vocab','quotes','characters'.
     */
    public function buildDeck(int $userId, array $userBookIds, array $types): array
    {
        $ids = empty($userBookIds) ? [] : $this->filterOwnedBooks($userId, $userBookIds);
        if (!empty($userBookIds) && empty($ids)) return [];

        $cards = [];
        if (in_array('vocab', $types, true)) {
            $cards = array_merge($cards, $this->fetchVocabCards($userId, $ids));
        }
        if (in_array('quotes', $types, true)) {
            $cards = array_merge($cards, $this->fetchQuoteCards($userId, $ids));
        }
        if (in_array('characters', $types, true)) {
            $cards = array_merge($cards, $this->fetchCharacterCards($userId, $ids));
        }
        return $cards;
    }

    /**
     * Legacy deck method — kept for backward compat with old mode param.
     */
    public function deckForUserBook(int $userId, int $userBookId, string $mode, string $search): array
    {
        if (!$this->userOwnsUserBook($userId, $userBookId)) return [];

        $search   = trim($search);
        $hasSearch = $search !== '';
        $like     = '%' . $search . '%';
        $cards    = [];

        if ($mode === 'mix' || $mode === 'vocab') {
            $cards = array_merge($cards, $this->fetchVocabCards($userId, $userBookId, $hasSearch ? $like : null));
        }
        if ($mode === 'mix' || $mode === 'quotes') {
            $cards = array_merge($cards, $this->fetchQuoteCards($userId, $userBookId, $hasSearch ? $like : null));
        }
        if ($mode === 'all' || $mode === 'characters') {
            $cards = array_merge($cards, $this->fetchCharacterCards($userId, $userBookId));
        }

        return $cards;
    }

    public function deckForAllBooks(int $userId, string $mode, string $search): array
    {
        $search   = trim($search);
        $hasSearch = $search !== '';
        $like     = '%' . $search . '%';
        $cards    = [];

        if ($mode === 'mix' || $mode === 'vocab') {
            $cards = array_merge($cards, $this->fetchVocabCards($userId, 0, $hasSearch ? $like : null));
        }
        if ($mode === 'mix' || $mode === 'quotes') {
            $cards = array_merge($cards, $this->fetchQuoteCards($userId, 0, $hasSearch ? $like : null));
        }
        if ($mode === 'all' || $mode === 'characters') {
            $cards = array_merge($cards, $this->fetchCharacterCards($userId, 0));
        }

        return $cards;
    }

    // -------------------------------------------------------------------------
    // Quiz generation
    // -------------------------------------------------------------------------

    /**
     * Generate QCM questions.
     * $userBookIds: specific book IDs; empty = all user's books.
     */
    public function generateQuiz(int $userId, array $userBookIds, array $types, int $limit): array
    {
        $ids = empty($userBookIds) ? [] : $this->filterOwnedBooks($userId, $userBookIds);
        if (!empty($userBookIds) && empty($ids)) return [];

        $questions = [];

        if (in_array('vocab', $types, true)) {
            $questions = array_merge($questions, $this->buildVocabQuestions(
                $this->fetchVocabPool($userId, $ids)
            ));
        }
        if (in_array('characters', $types, true)) {
            $questions = array_merge($questions, $this->buildCharacterQuestions(
                $this->fetchCharacterPool($userId, $ids)
            ));
        }
        if (in_array('quotes', $types, true)) {
            $questions = array_merge($questions, $this->buildQuoteQuestions(
                $this->fetchQuotePoolWithBooks($userId, $ids)
            ));
        }

        if (empty($questions)) return [];
        shuffle($questions);
        return array_slice($questions, 0, $limit);
    }

    // -------------------------------------------------------------------------
    // Card fetchers (private)
    // -------------------------------------------------------------------------

    private function fetchVocabCards(int $userId, array $ids, ?string $like = null): array
    {
        if (!empty($ids)) {
            $in   = implode(',', array_fill(0, count($ids), '?'));
            $sql  = "SELECT id, word, definition FROM vocab WHERE user_book_id IN ($in)" .
                    ($like !== null ? " AND (word LIKE ?  OR definition LIKE ?)" : "") . " ORDER BY id DESC";
            $params = $like !== null ? [...$ids, $like, $like] : $ids;
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
        } else {
            if ($like !== null) {
                $stmt = $this->pdo->prepare("
                    SELECT v.id, v.word, v.definition FROM vocab v
                    JOIN user_books ub ON ub.id = v.user_book_id
                    WHERE ub.user_id = :uid AND (v.word LIKE :q OR v.definition LIKE :q)
                    ORDER BY v.id DESC
                ");
                $stmt->execute(['uid' => $userId, 'q' => $like]);
            } else {
                $stmt = $this->pdo->prepare("
                    SELECT v.id, v.word, v.definition FROM vocab v
                    JOIN user_books ub ON ub.id = v.user_book_id
                    WHERE ub.user_id = :uid ORDER BY v.id DESC
                ");
                $stmt->execute(['uid' => $userId]);
            }
        }

        $cards = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $r) {
            $cards[] = [
                'id'   => 'v_' . (int)$r['id'],
                'type' => 'vocab',
                'front' => (string)$r['word'],
                'back'  => (string)$r['definition'],
                'meta'  => ['label' => 'Vocabulaire', 'icon' => 'book-open-outline'],
            ];
        }
        return $cards;
    }

    private function fetchQuoteCards(int $userId, array $ids, ?string $like = null): array
    {
        if (!empty($ids)) {
            $in     = implode(',', array_fill(0, count($ids), '?'));
            $sql    = "SELECT id, content, author FROM quotes WHERE user_book_id IN ($in)" .
                      ($like !== null ? " AND (content LIKE ? OR author LIKE ?)" : "") . " ORDER BY id DESC";
            $params = $like !== null ? [...$ids, $like, $like] : $ids;
        } else {
            $sql    = "SELECT q.id, q.content, q.author FROM quotes q
                       JOIN user_books ub ON ub.id = q.user_book_id WHERE ub.user_id = ?" .
                      ($like !== null ? " AND (q.content LIKE ? OR q.author LIKE ?)" : "") . " ORDER BY q.id DESC";
            $params = $like !== null ? [$userId, $like, $like] : [$userId];
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $cards = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $r) {
            $content = trim((string)$r['content']);
            $author  = $r['author'] ?? null;
            $author  = is_string($author) ? trim($author) : null;
            if ($author === '') $author = null;

            $cards[] = [
                'id'   => 'q_' . (int)$r['id'],
                'type' => 'quote',
                'front' => '"' . $content . '"',
                'back'  => $author ? ('— ' . $author) : '— Auteur inconnu',
                'meta'  => ['label' => 'Citation', 'icon' => 'chatbubble-outline'],
            ];
        }
        return $cards;
    }

    private function fetchCharacterCards(int $userId, array $ids): array
    {
        if (!empty($ids)) {
            $in   = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->pdo->prepare("SELECT id, name, role, description FROM characters WHERE user_book_id IN ($in) ORDER BY id DESC");
            $stmt->execute($ids);
        } else {
            $stmt = $this->pdo->prepare("
                SELECT c.id, c.name, c.role, c.description FROM characters c
                JOIN user_books ub ON ub.id = c.user_book_id
                WHERE ub.user_id = ? ORDER BY c.id DESC
            ");
            $stmt->execute([$userId]);
        }

        $cards = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $r) {
            $back = (string)($r['description'] ?? $r['role'] ?? '');
            if ($back === '') $back = 'Personnage';

            $cards[] = [
                'id'   => 'c_' . (int)$r['id'],
                'type' => 'character',
                'front' => (string)$r['name'],
                'back'  => $back,
                'meta'  => ['label' => 'Personnage', 'icon' => 'people-outline'],
            ];
        }
        return $cards;
    }

    // -------------------------------------------------------------------------
    // Quiz pool fetchers
    // -------------------------------------------------------------------------

    private function fetchVocabPool(int $userId, array $ids): array
    {
        if (!empty($ids)) {
            $in   = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->pdo->prepare("SELECT id, word, definition FROM vocab WHERE user_book_id IN ($in) ORDER BY RAND()");
            $stmt->execute($ids);
        } else {
            $stmt = $this->pdo->prepare("
                SELECT v.id, v.word, v.definition FROM vocab v
                JOIN user_books ub ON ub.id = v.user_book_id WHERE ub.user_id = ? ORDER BY RAND()
            ");
            $stmt->execute([$userId]);
        }
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    private function fetchCharacterPool(int $userId, array $ids): array
    {
        if (!empty($ids)) {
            $in   = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->pdo->prepare("
                SELECT id, name, role, description FROM characters
                WHERE user_book_id IN ($in)
                AND (description IS NOT NULL OR role IS NOT NULL)
                ORDER BY RAND()
            ");
            $stmt->execute($ids);
        } else {
            $stmt = $this->pdo->prepare("
                SELECT c.id, c.name, c.role, c.description FROM characters c
                JOIN user_books ub ON ub.id = c.user_book_id
                WHERE ub.user_id = ? AND (c.description IS NOT NULL OR c.role IS NOT NULL)
                ORDER BY RAND()
            ");
            $stmt->execute([$userId]);
        }
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    private function fetchQuotePoolWithBooks(int $userId, array $ids): array
    {
        if (!empty($ids)) {
            $in   = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->pdo->prepare("
                SELECT q.id, q.content, q.author, b.title AS book_title
                FROM quotes q
                JOIN user_books ub ON ub.id = q.user_book_id
                JOIN books b ON b.id = ub.book_id
                WHERE q.user_book_id IN ($in)
                ORDER BY RAND()
            ");
            $stmt->execute($ids);
        } else {
            $stmt = $this->pdo->prepare("
                SELECT q.id, q.content, q.author, b.title AS book_title
                FROM quotes q
                JOIN user_books ub ON ub.id = q.user_book_id
                JOIN books b ON b.id = ub.book_id
                WHERE ub.user_id = ?
                ORDER BY RAND()
            ");
            $stmt->execute([$userId]);
        }
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    // -------------------------------------------------------------------------
    // Question builders
    // -------------------------------------------------------------------------

    private function buildVocabQuestions(array $pool): array
    {
        if (count($pool) < 1) return [];

        $allDefinitions = array_column($pool, 'definition');
        $allWords       = array_column($pool, 'word');
        $questions      = [];

        foreach ($pool as $item) {
            $word       = (string)$item['word'];
            $definition = (string)$item['definition'];

            // Variant A — mot → définition  (quelle est la définition ?)
            $dA = $this->pickDistractors($allDefinitions, $definition, 3, self::GENERIC_DEFINITIONS);
            if (count($dA) >= 1) {
                $questions[] = [
                    'id'       => 'v_' . (int)$item['id'] . '_a',
                    'type'     => 'vocab',
                    'question' => 'Quelle est la définition de ce mot ?',
                    'stimulus' => $word,
                    'choices'  => $this->buildChoices($definition, $dA),
                ];
            }

            // Variant B — définition → mot  (quel mot correspond ?)
            $dB = $this->pickDistractors($allWords, $word, 3, self::GENERIC_WORDS);
            if (count($dB) >= 1) {
                $questions[] = [
                    'id'       => 'v_' . (int)$item['id'] . '_b',
                    'type'     => 'vocab',
                    'question' => 'Quel mot correspond à cette définition ?',
                    'stimulus' => $definition,
                    'choices'  => $this->buildChoices($word, $dB),
                ];
            }
        }
        return $questions;
    }

    private function buildCharacterQuestions(array $pool): array
    {
        if (count($pool) < 1) return [];

        $allNames = array_column($pool, 'name');
        $allRoles = array_values(array_filter(array_column($pool, 'role'), fn($r) => trim((string)$r) !== ''));
        $questions = [];

        foreach ($pool as $item) {
            $name        = (string)$item['name'];
            $description = trim((string)($item['description'] ?? ''));
            $role        = trim((string)($item['role'] ?? ''));

            // Variant A — description → nom  (à quel personnage correspond ?)
            $label = $description !== '' ? $description : $role;
            if ($label !== '') {
                $dA = $this->pickDistractors($allNames, $name, 3, self::GENERIC_CHARACTER_NAMES);
                if (count($dA) >= 1) {
                    $questions[] = [
                        'id'       => 'c_' . (int)$item['id'] . '_a',
                        'type'     => 'character',
                        'question' => 'À quel personnage correspond cette description ?',
                        'stimulus' => $label,
                        'choices'  => $this->buildChoices($name, $dA),
                    ];
                }
            }

            // Variant B — nom → rôle  (quel est le rôle de ce personnage ?)
            if ($role !== '') {
                $dB = $this->pickDistractors($allRoles, $role, 3, self::GENERIC_ROLES);
                if (count($dB) >= 1) {
                    $questions[] = [
                        'id'       => 'c_' . (int)$item['id'] . '_b',
                        'type'     => 'character',
                        'question' => 'Quel est le rôle de ce personnage ?',
                        'stimulus' => $name,
                        'choices'  => $this->buildChoices($role, $dB),
                    ];
                }
            }
        }
        return $questions;
    }

    private function buildQuoteQuestions(array $pool): array
    {
        if (count($pool) < 1) return [];

        $books      = array_unique(array_column($pool, 'book_title'));
        $allAuthors = array_values(array_unique(array_filter(
            array_column($pool, 'author'),
            fn($a) => trim((string)$a) !== ''
        )));
        $questions  = [];

        foreach ($pool as $item) {
            $content   = (string)$item['content'];
            $bookTitle = (string)$item['book_title'];
            $author    = trim((string)($item['author'] ?? ''));

            $stimulus = '"' . (mb_strlen($content) > 120 ? mb_substr($content, 0, 120) . '…' : $content) . '"';

            // Variant A — citation → livre  (à quel livre appartient ?)
            $dA = $this->pickDistractors($books, $bookTitle, 3, self::GENERIC_BOOK_TITLES);
            if (count($dA) >= 1) {
                $questions[] = [
                    'id'       => 'q_' . (int)$item['id'] . '_a',
                    'type'     => 'quote',
                    'question' => 'À quel livre appartient cette citation ?',
                    'stimulus' => $stimulus,
                    'choices'  => $this->buildChoices($bookTitle, $dA),
                ];
            }

            // Variant B — citation → auteur  (qui a dit ?)  — seulement si auteur renseigné
            if ($author !== '') {
                $dB = $this->pickDistractors($allAuthors, $author, 3, self::GENERIC_AUTHORS);
                if (count($dB) >= 1) {
                    $questions[] = [
                        'id'       => 'q_' . (int)$item['id'] . '_b',
                        'type'     => 'quote',
                        'question' => 'Qui a prononcé cette citation ?',
                        'stimulus' => $stimulus,
                        'choices'  => $this->buildChoices($author, $dB),
                    ];
                }
            }
        }
        return $questions;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function pickDistractors(array $pool, string $correctValue, int $count, array $fallback = []): array
    {
        $others = array_values(array_filter($pool, fn($v) => (string)$v !== $correctValue));
        $others = array_unique($others);
        shuffle($others);

        // Supplement with generic fallbacks if the pool doesn't have enough
        if (count($others) < $count && !empty($fallback)) {
            $fallbackFiltered = array_values(array_filter(
                $fallback,
                fn($v) => (string)$v !== $correctValue && !in_array((string)$v, $others, true)
            ));
            shuffle($fallbackFiltered);
            $others = array_slice(array_merge($others, $fallbackFiltered), 0, $count);
        }

        return array_slice($others, 0, $count);
    }

    private function buildChoices(string $correctText, array $distractors): array
    {
        $choices = [
            ['id' => 'c_0', 'text' => $correctText, 'correct' => true],
        ];
        foreach ($distractors as $i => $d) {
            $choices[] = ['id' => 'c_' . ($i + 1), 'text' => (string)$d, 'correct' => false];
        }
        shuffle($choices);
        return $choices;
    }
}
