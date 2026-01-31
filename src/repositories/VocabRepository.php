<?php
namespace App\Repositories;

use App\Core\Db;

final class VocabRepository
{
    public function listForBook(int $userId, int $userBookId): array
    {
        $pdo = Db::pdo();

        $stmt = $pdo->prepare("
            SELECT v.id, v.word, v.definition, v.created_at
            FROM vocab v
            JOIN user_books ub ON ub.id = v.user_book_id
            WHERE ub.id = :user_book_id
              AND ub.user_id = :user_id
            ORDER BY v.created_at DESC
        ");

        $stmt->execute([
            'user_book_id' => $userBookId,
            'user_id' => $userId,
        ]);

        return $stmt->fetchAll();
    }

    public function create(int $userId, int $userBookId, string $word, string $definition): array
    {
        $pdo = Db::pdo();

        // Insère seulement si le user possède ce user_book
        $stmt = $pdo->prepare("
            INSERT INTO vocab (user_book_id, word, definition)
            SELECT ub.id, :word, :definition
            FROM user_books ub
            WHERE ub.id = :user_book_id
              AND ub.user_id = :user_id
            LIMIT 1
        ");

        $stmt->execute([
            'user_book_id' => $userBookId,
            'user_id' => $userId,
            'word' => $word,
            'definition' => $definition,
        ]);

        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException("Not Found");
        }

        $id = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare("SELECT id, word, definition, created_at FROM vocab WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);

        return $stmt->fetch();
    }

    public function delete(int $userId, int $userBookId, int $vocabId): bool
    {
        $pdo = Db::pdo();

        $stmt = $pdo->prepare("
            DELETE v
            FROM vocab v
            JOIN user_books ub ON ub.id = v.user_book_id
            WHERE v.id = :vocab_id
              AND ub.id = :user_book_id
              AND ub.user_id = :user_id
        ");

        $stmt->execute([
            'vocab_id' => $vocabId,
            'user_book_id' => $userBookId,
            'user_id' => $userId,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function update(int $userId, int $userBookId, int $vocabId, array $patch): ?array
    {
        $pdo = Db::pdo();

        // Ownership + vocab belongs to this user_book
        $stmt = $pdo->prepare("
            SELECT v.id
            FROM vocab v
            JOIN user_books ub ON ub.id = v.user_book_id
            WHERE v.id = :vocab_id
            AND ub.id = :user_book_id
            AND ub.user_id = :user_id
            LIMIT 1
        ");

        $stmt->execute([
            'vocab_id' => $vocabId,
            'user_book_id' => $userBookId,
            'user_id' => $userId,
        ]);

        if (!$stmt->fetch()) return null;

        $fields = [];
        $params = ['vocab_id' => $vocabId];

        foreach (['word', 'definition'] as $k) {
            if (array_key_exists($k, $patch)) {
                $fields[] = "v.$k = :$k";
                $params[$k] = $patch[$k];
            }
        }

        if ($fields) {
            $sql = "UPDATE vocab v SET " . implode(", ", $fields) . " WHERE v.id = :vocab_id";
            $upd = $pdo->prepare($sql);
            $upd->execute($params);
        }

        $stmt = $pdo->prepare("SELECT id, word, definition, created_at FROM vocab WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $vocabId]);

        return $stmt->fetch() ?: null;
    }

}
