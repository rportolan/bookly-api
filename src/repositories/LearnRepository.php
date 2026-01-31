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

    public function userOwnsUserBook(int $userId, int $userBookId): bool
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM user_books WHERE id = :ubid AND user_id = :uid LIMIT 1");
        $stmt->execute(['ubid' => $userBookId, 'uid' => $userId]);
        return (bool)$stmt->fetchColumn();
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
     * Build deck cards for Learn.jsx
     * Returns cards:
     * { id, type:"vocab|quote", front, back, meta:{label,icon} }
     */
    public function deckForUserBook(int $userId, int $userBookId, string $mode, string $search): array
    {
        // must belong to user
        if (!$this->userOwnsUserBook($userId, $userBookId)) {
            return [];
        }

        $search = trim($search);
        $hasSearch = $search !== '';
        $like = '%' . $search . '%';

        $cards = [];

        // VOCAB
        if ($mode === 'mix' || $mode === 'vocab') {
            if ($hasSearch) {
                $stmt = $this->pdo->prepare("
                    SELECT id, word, definition
                    FROM vocab
                    WHERE user_book_id = :ubid
                      AND (word LIKE :q OR definition LIKE :q)
                    ORDER BY id DESC
                ");
                $stmt->execute(['ubid' => $userBookId, 'q' => $like]);
            } else {
                $stmt = $this->pdo->prepare("
                    SELECT id, word, definition
                    FROM vocab
                    WHERE user_book_id = :ubid
                    ORDER BY id DESC
                ");
                $stmt->execute(['ubid' => $userBookId]);
            }

            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $r) {
                $cards[] = [
                    'id' => 'v_' . (int)$r['id'],
                    'type' => 'vocab',
                    'front' => (string)$r['word'],
                    'back' => (string)$r['definition'],
                    'meta' => ['label' => 'Vocab', 'icon' => '📘'],
                ];
            }
        }

        // QUOTES
        if ($mode === 'mix' || $mode === 'quotes') {
            if ($hasSearch) {
                $stmt = $this->pdo->prepare("
                    SELECT id, content
                    FROM quotes
                    WHERE user_book_id = :ubid
                      AND content LIKE :q
                    ORDER BY id DESC
                ");
                $stmt->execute(['ubid' => $userBookId, 'q' => $like]);
            } else {
                $stmt = $this->pdo->prepare("
                    SELECT id, content
                    FROM quotes
                    WHERE user_book_id = :ubid
                    ORDER BY id DESC
                ");
                $stmt->execute(['ubid' => $userBookId]);
            }

            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $r) {
                $content = (string)$r['content'];
                $cards[] = [
                    'id' => 'q_' . (int)$r['id'],
                    'type' => 'quote',
                    'front' => '“' . $content . '”',
                    'back' => 'Ajoute ton interprétation plus tard (MVP).',
                    'meta' => ['label' => 'Citation', 'icon' => '💬'],
                ];
            }
        }

        return $cards;
    }

    public function deckForAllBooks(int $userId, string $mode, string $search): array
    {
        // récupérer tous les user_book_id du user
        $stmt = $this->pdo->prepare("SELECT id FROM user_books WHERE user_id = :uid");
        $stmt->execute(['uid' => $userId]);
        $ids = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []);

        if (!$ids) return [];

        $search = trim($search);
        $hasSearch = $search !== '';
        $like = '%' . $search . '%';

        $cards = [];

        $in = implode(',', array_fill(0, count($ids), '?'));

        // VOCAB
        if ($mode === 'mix' || $mode === 'vocab') {
            if ($hasSearch) {
                $sql = "
                    SELECT id, word, definition
                    FROM vocab
                    WHERE user_book_id IN ($in)
                    AND (word LIKE ? OR definition LIKE ?)
                    ORDER BY id DESC
                ";
                $vStmt = $this->pdo->prepare($sql);
                $vStmt->execute([...$ids, $like, $like]);
            } else {
                $sql = "
                    SELECT id, word, definition
                    FROM vocab
                    WHERE user_book_id IN ($in)
                    ORDER BY id DESC
                ";
                $vStmt = $this->pdo->prepare($sql);
                $vStmt->execute($ids);
            }

            $rows = $vStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $r) {
                $cards[] = [
                    'id' => 'v_' . (int)$r['id'],
                    'type' => 'vocab',
                    'front' => (string)$r['word'],
                    'back' => (string)$r['definition'],
                    'meta' => ['label' => 'Vocab', 'icon' => '📘'],
                ];
            }
        }

        // QUOTES
        if ($mode === 'mix' || $mode === 'quotes') {
            if ($hasSearch) {
                $sql = "
                    SELECT id, content
                    FROM quotes
                    WHERE user_book_id IN ($in)
                    AND content LIKE ?
                    ORDER BY id DESC
                ";
                $qStmt = $this->pdo->prepare($sql);
                $qStmt->execute([...$ids, $like]);
            } else {
                $sql = "
                    SELECT id, content
                    FROM quotes
                    WHERE user_book_id IN ($in)
                    ORDER BY id DESC
                ";
                $qStmt = $this->pdo->prepare($sql);
                $qStmt->execute($ids);
            }

            $rows = $qStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $r) {
                $cards[] = [
                    'id' => 'q_' . (int)$r['id'],
                    'type' => 'quote',
                    'front' => '“' . (string)$r['content'] . '”',
                    'back' => 'Ajoute ton interprétation plus tard (MVP).',
                    'meta' => ['label' => 'Citation', 'icon' => '💬'],
                ];
            }
        }

        return $cards;
    }

}
